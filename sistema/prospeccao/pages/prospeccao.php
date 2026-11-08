<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════
// Prospecção (por campanha)
//  1. Acha contatos: busca no Google Maps (Places API), CSV do Outscraper/Apify ou "Minha lista".
//  2. O Claude escreve a 1ª mensagem de cada contato (nome, bairro, nota, ramo, produto da campanha).
//  3. Você envia com um toque pelo SEU WhatsApp (link wa.me) ou copia para o DM do Instagram —
//     nunca em massa pela API oficial: disparo frio derruba a qualidade do número.
//  4. Funil: novo → enviado → respondeu → interessado → cliente / perdido. "Não contatar" é permanente.
// Portado de pages_super/prospeccao.php do DojoSystem.
// ═══════════════════════════════════════════════════════════
require_once __DIR__ . '/../includes/claude_api.php';
require_once __DIR__ . '/../includes/places.php';
require_once __DIR__ . '/../includes/prospects.php';
require_once __DIR__ . '/../includes/vendedor_ia.php';
require_once __DIR__ . '/../includes/pesquisa.php';

// ── Campanha atual ──────────────────────────────────────────
$cid = (int)($_GET['c'] ?? $_SESSION['camp_atual'] ?? 0);
$camp = $cid ? proCampanha($db, $cid) : null;
if (!$camp) $camp = $db->query('SELECT * FROM pro_campanhas ORDER BY ativa DESC, id DESC LIMIT 1')->fetch() ?: null;
if (!$camp) { flash('Crie uma campanha primeiro: ela define o que você vende e para qual ramo.', 'error'); redirect(proUrl('campanhas', ['nova' => 1])); }
$cid = (int)$camp['id'];
$_SESSION['camp_atual'] = $cid;
$self = proUrl('prospeccao', ['c' => $cid]);
$agora = proAgora();
$limite = max(5, (int)$camp['limite_dia']);
$vdCfg = vdConfig();
$vdOn = $vdCfg['phone_id'] !== '' || $vdCfg['ig_conta'] !== '';   // há WhatsApp de vendas ou Instagram conectado
$ehAjax = !empty($_POST['ajax']);

// Mensagens do usuário que voltam para a mesma lista (mantém filtros)
$voltaLista = fn(array $extra = []) => proUrl('prospeccao', ['c' => $cid] + $extra);

// ── Lista de cidades do estado (preenche o campo Cidade) ────
if (($_GET['ajax'] ?? '') === 'cidades') proJson(['ok' => true, 'cidades' => proCidadesUF(strtoupper((string)($_GET['uf'] ?? '')))]);

// ── Ações ───────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    if (!csrfCheck($_POST['csrf'] ?? '')) {
        if ($ehAjax) proJson(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.']);
        flash('Sessão expirada.', 'error'); redirect($self);
    }

    // Limpar: apaga só quem ainda não foi contatado (status Novo) desta campanha. Quem já recebeu mensagem,
    // respondeu ou pediu para não ser contatado fica — é isso que impede de abordar duas vezes.
    if ($acao === 'limpar') {
        $b = (string)($_POST['b'] ?? '');
        if ($b === '__todos__') { $s = $db->prepare("DELETE FROM pro_prospects WHERE status = 'novo' AND campanha_id = ?"); $s->execute([$cid]); $rot = 'todos os novos'; }
        elseif ($b === '__sem__') { $s = $db->prepare("DELETE FROM pro_prospects WHERE status = 'novo' AND campanha_id = ? AND busca IS NULL"); $s->execute([$cid]); $rot = 'os novos sem pesquisa'; }
        else { $s = $db->prepare("DELETE FROM pro_prospects WHERE status = 'novo' AND campanha_id = ? AND busca = ?"); $s->execute([$cid, $b]); $rot = '"' . $b . '"'; }
        flash('🗑️ Limpo: ' . $s->rowCount() . " contato(s) não contatados de {$rot}. Quem já recebeu mensagem continua na lista.");
        redirect($voltaLista(['st' => 'novo']));
    }

    if ($acao === 'buscar') {
        $tipo  = trim(mb_substr((string)($_POST['tipo'] ?? ''), 0, 80));
        $local = trim(mb_substr((string)($_POST['local'] ?? ''), 0, 80));
        $uf    = strtoupper(trim((string)($_POST['uf'] ?? '')));
        if (!isset(PRO_UFS[$uf])) $uf = '';
        foreach (proCidadesUF($uf) as $cOf) if (proNorm($cOf) === proNorm($local)) { $local = $cOf; break; }   // nome oficial (IBGE)
        $qtd    = in_array((int)($_POST['qtd'] ?? 20), [20, 40, 60], true) ? (int)$_POST['qtd'] : 20;
        $chaveG = trim(getPConfig('google_places_key'));
        $_SESSION['pr_ult_busca'] = ['tipo' => $tipo, 'local' => $local, 'uf' => $uf, 'qtd' => $qtd];
        $consulta = $tipo . ' em ' . $local . ($uf ? '/' . $uf : '');
        $volta = function (string $msg, string $t) use ($consulta, $voltaLista) { flash($msg, $t); redirect($voltaLista(['st' => 'novo', 'b' => $consulta])); };
        if ($chaveG === '') $volta('Cadastre primeiro a chave do Google em Configurações.', 'error');
        if ($tipo === '' || $local === '' || $uf === '') $volta('Preencha o tipo de negócio, o estado e a cidade.', 'error');
        @set_time_limit(90);
        $r = proBuscarGoogle($chaveG, $tipo . ' em ' . $local . ', ' . PRO_UFS[$uf] . ', Brasil', $qtd);
        if (!$r['ok'] && !$r['lugares']) $volta('Busca no Google falhou: ' . $r['erro'], 'error');

        $dd = proDedupe($db);
        $seg = proSegmentoDetectar($tipo, $camp) ?: mb_substr($tipo, 0, 80);
        $c = ['novo' => 0, 'dup' => 0, 'sem_contato' => 0, 'cliente' => 0, 'bloqueado' => 0]; $fechados = 0; $foraCidade = 0;
        foreach ($r['lugares'] as $pl) {
            if (($pl['businessStatus'] ?? '') === 'CLOSED_PERMANENTLY') { $fechados++; continue; }
            $nome = trim((string)($pl['displayName']['text'] ?? '')); if ($nome === '') continue;
            $end = proEnderecoPartes($pl['addressComponents'] ?? []);
            // Só a cidade escolhida: "São José" não pode trazer São José dos Campos nem São José/PR
            if (!proNaCidade($pl, $end, $local, $uf)) { $foraCidade++; continue; }
            $site = (string)($pl['websiteUri'] ?? '');
            $ig = preg_match('~instagram\.com/([A-Za-z0-9_.]+)~', $site, $m) ? '@' . $m[1] : '';
            $res = proInserir($db, [
                'campanha_id' => $cid, 'nome' => $nome, 'segmento' => $seg, 'cidade' => $local, 'bairro' => $end['bairro'], 'uf' => $uf,
                'endereco' => (string)($pl['formattedAddress'] ?? ''), 'telefone' => proTel((string)($pl['internationalPhoneNumber'] ?? $pl['nationalPhoneNumber'] ?? '')),
                'instagram' => $ig, 'site' => $ig ? '' : $site, 'nota' => isset($pl['rating']) ? (float)$pl['rating'] : null,
                'avaliacoes' => isset($pl['userRatingCount']) ? (int)$pl['userRatingCount'] : null, 'origem' => 'google',
                'google_place_id' => (string)($pl['id'] ?? ''), 'maps_url' => (string)($pl['googleMapsUri'] ?? ''), 'busca' => $consulta,
            ], $dd);
            if (isset($c[$res])) $c[$res]++;
        }
        $novos = $c['novo'] + $c['cliente'];
        $hist = json_decode(getPConfig('buscas_' . $cid), true) ?: [];
        array_unshift($hist, ['q' => $consulta, 'em' => date('d/m/Y H:i'), 'achou' => count($r['lugares']), 'novos' => $novos]);
        setPConfig('buscas_' . $cid, json_encode(array_slice($hist, 0, 15), JSON_UNESCAPED_UNICODE));
        $volta("\"{$consulta}\": " . count($r['lugares']) . " encontrado(s) · {$novos} novo(s) na lista" . proResumoInsercao($c)
             . ($fechados ? " · {$fechados} fechado(s)" : '') . ($foraCidade ? " · {$foraCidade} de outra cidade (descartados)" : '')
             . (!$r['ok'] ? ' · ⚠️ parou antes do fim: ' . $r['erro'] : '') . '.', $novos ? 'ok' : 'error');
    }

    if ($acao === 'importar') {
        $f = $_FILES['csv'] ?? null;
        $segForm = trim((string)($_POST['segmento'] ?? ''));
        $c = ['novo' => 0, 'dup' => 0, 'sem_contato' => 0, 'cliente' => 0, 'bloqueado' => 0];
        if ($f && $f['error'] === UPLOAD_ERR_OK && ($fh = fopen($f['tmp_name'], 'r'))) {
            $primeira = fgets($fh); rewind($fh);
            $sep = substr_count((string)$primeira, ';') > substr_count((string)$primeira, ',') ? ';' : ',';
            $cab = array_map(fn($x) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$x))), fgetcsv($fh, 0, $sep) ?: []);
            $col = function (array $nomes) use ($cab) { foreach ($nomes as $n) { $i = array_search($n, $cab, true); if ($i !== false) return $i; } return null; };
            $iNome = $col(['name', 'title', 'nome', 'query']); $iTel = $col(['phone', 'phoneunformatted', 'phone_1', 'telefone', 'phone_number', 'phones']);
            $iCid = $col(['city', 'cidade']); $iBai = $col(['borough', 'neighborhood', 'bairro', 'district']); $iUf = $col(['state', 'uf', 'estado', 'us_state']);
            $iEnd = $col(['full_address', 'address', 'street', 'endereco', 'endereço']); $iNota = $col(['rating', 'totalscore', 'nota']);
            $iAv = $col(['reviews', 'reviewscount', 'avaliacoes', 'avaliações']); $iSite = $col(['site', 'website', 'url']);
            $iIg = $col(['instagram', 'instagrams', 'instagram_url']); $iCat = $col(['category', 'categoryname', 'categoria', 'type', 'subtypes', 'categories']);
            if ($iNome === null) { flash('Não achei a coluna do nome (name/title). Confira se o arquivo é o CSV exportado do Outscraper ou Apify.', 'error'); redirect($self); }
            $dd = proDedupe($db);
            $buscaCsv = mb_substr('Planilha: ' . basename((string)$f['name']), 0, 160);
            $v = fn($row, $i) => $i === null ? '' : trim((string)($row[$i] ?? ''));
            while (($row = fgetcsv($fh, 0, $sep)) !== false) {
                $nome = $v($row, $iNome); if ($nome === '') continue;
                $ig = $v($row, $iIg);
                if (preg_match('~instagram\.com/([A-Za-z0-9_.]+)~', $ig . ' ' . $v($row, $iSite), $m)) $ig = '@' . $m[1];
                $nota = str_replace(',', '.', $v($row, $iNota));
                $res = proInserir($db, [
                    'campanha_id' => $cid, 'nome' => $nome, 'segmento' => $segForm ?: proSegmentoDetectar($v($row, $iCat) . ' ' . $nome, $camp),
                    'cidade' => $v($row, $iCid), 'bairro' => $v($row, $iBai), 'uf' => strtoupper($v($row, $iUf)), 'endereco' => $v($row, $iEnd),
                    'telefone' => proTel(explode(',', $v($row, $iTel))[0]), 'instagram' => $ig, 'site' => $v($row, $iSite),
                    'nota' => is_numeric($nota) ? (float)$nota : null, 'avaliacoes' => is_numeric($v($row, $iAv)) ? (int)$v($row, $iAv) : null,
                    'origem' => 'csv', 'busca' => $buscaCsv,
                ], $dd);
                if (isset($c[$res])) $c[$res]++;
            }
            fclose($fh);
        }
        $novos = $c['novo'] + $c['cliente'];
        flash("Importados: {$novos}" . proResumoInsercao($c) . '.', $novos ? 'ok' : 'error');
        redirect($self);
    }

    if ($acao === 'gerar') {
        $st = $db->prepare('SELECT * FROM pro_prospects WHERE id = ?'); $st->execute([(int)($_POST['id'] ?? 0)]);
        $p = $st->fetch();
        if (!$p) proJson(['ok' => false, 'erro' => 'Não encontrado.']);
        $cp = (int)$p['campanha_id'] === $cid ? $camp : (proCampanha($db, (int)$p['campanha_id']) ?: $camp);
        $conhecido = ($p['origem'] ?? '') === 'manual';
        $persona = trim((string)$cp['persona']) ?: 'o responsável';
        $empresa = getPConfig('empresa_nome', 'Opa Tecnologia e Design');
        $sys = "Você escreve a PRIMEIRA mensagem de WhatsApp que {$persona}, da {$empresa}, manda para o responsável por um negócio do ramo de {$cp['ramo']}. "
             . "O que está sendo oferecido: {$cp['produto']}. O único objetivo desta mensagem é a pessoa RESPONDER — não é vender nem explicar o produto.\n"
             . "Como escrever:\n"
             . "- Como uma pessoa real digitando no celular: curta (1 a 3 frases, no máximo ~280 caracteres), informal e educada, frases simples.\n"
             . "- Comece com um cumprimento curto (ex.: \"Oi, tudo bem?\").\n"
             . ($conhecido
                ? "- Esta pessoa JÁ CONHECE o remetente: não diga que achou no Google; fale como quem retoma contato.\n"
                : "- Diga de forma natural como chegou até o negócio (ex.: \"vi o negócio de vocês no Google\"). NÃO cole o nome do negócio em frases estranhas; use \"vocês\" ou o nome só se soar natural.\n")
             . "- Mencione UMA dor comum do ramo em poucas palavras, sem listar funcionalidades."
             . (trim((string)$cp['dor_comum']) !== '' ? " Dores comuns (escolha UMA, a que mais combina com o contato): " . proLinhas((string)$cp['dor_comum']) . "\n" : "\n")
             . "- Termine com UMA pergunta leve e fácil de responder.\n"
             . "- SEM link, sem preço, sem negrito, sem hashtags, no máximo 1 emoji, sem frases de propaganda (\"revolucionário\", \"solução completa\"). Não invente fatos. Não assine com nome.\n"
             . (trim((string)$cp['tom']) !== '' ? "- Tom de voz: " . trim($cp['tom']) . ".\n" : '')
             . (trim((string)$cp['contexto']) !== '' ? "Sobre o remetente (use só se soar natural): " . trim(preg_replace('/\s+/', ' ', $cp['contexto'])) . "\n" : '')
             . "Responda só com o texto da mensagem.";
        $dados = "Sobre o que se oferece (só para contexto, não despeje isso): {$cp['pitch']}\n"
               . (trim((string)$cp['oferta']) !== '' ? "Oferta/isca (não cite na 1ª mensagem): {$cp['oferta']}\n" : '')
               . "\nContato:\n- Nome: {$p['nome']}\n"
               . ($p['segmento'] ? "- Tipo de negócio: {$p['segmento']}\n" : '')
               . (($p['bairro'] || $p['cidade']) ? '- Local: ' . trim(($p['bairro'] ?? '') . ', ' . ($p['cidade'] ?? ''), ', ') . "\n" : '')
               . ($p['nota'] !== null ? "- Nota no Google: {$p['nota']}" . ($p['avaliacoes'] ? " ({$p['avaliacoes']} avaliações)" : '') . "\n" : '')
               . ($p['obs'] ? "- Anotação do vendedor: {$p['obs']}\n" : '')
               . ppTextoParaIA($p['pesquisa'] ?? null);   // ficha da pesquisa (hipóteses): ajuda a escolher a dor e o gancho
        iaContexto('abordagem', (int)$p['id'], (int)($p['campanha_id'] ?? 0) ?: null);
        $r = claudeTexto($sys, $dados, 2000, 'low');
        if (!$r['ok']) proJson(['ok' => false, 'erro' => $r['erro']]);
        $msg = trim($r['texto'], " \n\"“”");
        $saida = trim(getPConfig('msg_saida'));
        if ($saida !== '' && stripos($msg, $saida) === false) $msg .= "\n\n" . $saida;   // LGPD: sempre dá a opção de sair
        $db->prepare('UPDATE pro_prospects SET mensagem = ?, atualizado_em = ? WHERE id = ?')->execute([$msg, $agora, (int)$p['id']]);
        proJson(['ok' => true, 'mensagem' => $msg]);
    }

    // Pesquisa pública do contato: site (lido pelo servidor) + Google (já salvo) + prints das redes (anexados)
    if ($acao === 'pesquisar') {
        @set_time_limit(100);
        if (trim(getPConfig('claude_api_key')) === '') proJson(['ok' => false, 'erro' => 'Cadastre a chave do Claude em Configurações antes.']);
        $st = $db->prepare('SELECT * FROM pro_prospects WHERE id = ?'); $st->execute([(int)($_POST['id'] ?? 0)]);
        $p = $st->fetch();
        if (!$p) proJson(['ok' => false, 'erro' => 'Não encontrado.']);
        $cp = (int)$p['campanha_id'] === $cid ? $camp : (proCampanha($db, (int)$p['campanha_id']) ?: $camp);
        $r = ppPesquisar($db, $p, $cp, $_FILES['prints'] ?? []);
        if (!$r['ok']) proJson(['ok' => false, 'erro' => $r['erro'], 'avisos' => $r['avisos']]);
        proJson(['ok' => true, 'html' => ppFichaHtml($r['ficha']), 'avisos' => $r['avisos']]);
    }

    if ($acao === 'salvar_msg') {
        $db->prepare('UPDATE pro_prospects SET mensagem = ?, atualizado_em = ? WHERE id = ?')
           ->execute([mb_substr((string)($_POST['mensagem'] ?? ''), 0, 2000), $agora, (int)($_POST['id'] ?? 0)]);
        proJson(['ok' => true]);
    }

    if ($acao === 'status') {
        if (!proMudarStatus($db, (int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''))) proJson(['ok' => false, 'erro' => 'Status inválido.']);
        proJson(['ok' => true, 'hoje' => proContatadosHoje($db, $cid)]);
    }

    // 1ª mensagem saiu pelo sistema ("Abrir no WhatsApp"): marca como enviada e liga o Vendedor IA para a resposta
    if ($acao === 'enviou') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare('SELECT status FROM pro_prospects WHERE id = ?'); $st->execute([$id]);
        $sAtual = $st->fetchColumn();
        if ($sAtual === false) proJson(['ok' => false, 'erro' => 'Contato não encontrado.']);
        if ($sAtual === 'nao_contatar') proJson(['ok' => false, 'erro' => 'Este contato pediu para não ser contatado.']);
        $msgEnv = trim((string)($_POST['mensagem'] ?? ''));
        $db->prepare('UPDATE pro_prospects SET mensagem = ?, atualizado_em = ?, contatado_em = COALESCE(contatado_em, ?) WHERE id = ?')->execute([$msgEnv, $agora, $agora, $id]);
        if ($sAtual === 'novo') proMudarStatus($db, $id, 'enviado');
        vdMarcarEnvio($db, $id, $msgEnv, ($_POST['canal'] ?? '') === 'instagram' ? 'instagram' : 'whatsapp');
        proJson(['ok' => true, 'hoje' => proContatadosHoje($db, $cid)]);
    }
    if ($acao === 'ia') {
        $db->prepare('UPDATE pro_prospects SET ia_ativa = ?, ia_pausada = 0 WHERE id = ?')->execute([!empty($_POST['ligar']) ? 1 : 0, (int)($_POST['id'] ?? 0)]);
        proJson(['ok' => true, 'ia' => !empty($_POST['ligar'])]);
    }

    // ── Minha lista: contatos que você já conhece (digitados ou colados) ──
    if ($acao === 'manual_add') {
        $linhas = [];
        $nome1 = trim((string)($_POST['nome'] ?? '')); $tel1 = trim((string)($_POST['telefone'] ?? ''));
        if ($nome1 !== '' || $tel1 !== '') $linhas[] = [$nome1, $tel1, trim((string)($_POST['cidade'] ?? '')), strtoupper(trim((string)($_POST['uf'] ?? ''))), trim((string)($_POST['segmento'] ?? '')), trim((string)($_POST['obs'] ?? ''))];
        // Vários de uma vez: uma linha por contato — nome; telefone; cidade; UF; segmento
        foreach (preg_split('/\r?\n/', (string)($_POST['varios'] ?? '')) as $ln) {
            $ln = trim($ln); if ($ln === '') continue;
            $x = array_map('trim', preg_split('/\s*[;\t]\s*/', $ln));
            if (count($x) === 1 && preg_match('/^(.*?)[\s,–-]+(\+?[\d\s().-]{10,})$/u', $ln, $m)) $x = [trim($m[1], " ,-–"), $m[2]];
            $linhas[] = [$x[0] ?? '', $x[1] ?? '', $x[2] ?? '', strtoupper($x[3] ?? ''), $x[4] ?? '', ''];
        }
        $dd = proDedupe($db);
        $c = ['novo' => 0, 'dup' => 0, 'cliente' => 0, 'bloqueado' => 0]; $ruins = [];
        foreach ($linhas as [$nome, $telIn, $cidade, $uf, $seg, $obs]) {
            $tel = proTel($telIn);
            if ($tel === '' || strlen($tel) < 12) { $ruins[] = $nome ?: $telIn; continue; }
            $res = proInserir($db, ['campanha_id' => $cid, 'nome' => $nome ?: $telIn, 'segmento' => $seg ?: proSegmentoDetectar($nome, $camp), 'cidade' => $cidade, 'uf' => $uf,
                                    'telefone' => $tel, 'obs' => $obs, 'origem' => 'manual', 'busca' => 'Minha lista'], $dd);
            if (isset($c[$res])) $c[$res]++;
        }
        $msg = ($c['novo'] + $c['cliente']) ? '✓ ' . ($c['novo'] + $c['cliente']) . ' contato(s) na Minha lista.' : 'Nenhum contato adicionado.';
        $msg .= proResumoInsercao($c);
        if ($ruins) $msg .= ' Telefone inválido (use DDD + número): ' . implode(', ', array_slice($ruins, 0, 5)) . (count($ruins) > 5 ? '…' : '') . '.';
        flash($msg, ($c['novo'] + $c['cliente']) ? 'ok' : 'error');
        redirect($voltaLista(['st' => 'novo', 'b' => 'Minha lista']) . '#resultados');
    }

    if ($acao === 'editar' || $acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $volta = proUrl('prospeccao', ['c' => $cid] + array_filter(array_intersect_key($_POST, array_flip(['st', 'cid', 'seg', 'q', 'b'])), fn($x) => $x !== ''));
        if ($acao === 'excluir') {
            $db->prepare('DELETE FROM pro_prospects WHERE id = ?')->execute([$id]);   // "não contatar" continua em pro_bloqueados
            $db->prepare('DELETE FROM pro_vd_mensagens WHERE prospect_id = ?')->execute([$id]);
            flash('Contato excluído.'); redirect($volta);
        }
        $nome = trim((string)($_POST['nome'] ?? '')); $telIn = trim((string)($_POST['telefone'] ?? '')); $tel = proTel($telIn);
        if ($nome === '') { flash('O nome não pode ficar vazio.', 'error'); redirect($volta); }
        if ($telIn !== '' && strlen($tel) < 12) { flash('Telefone inválido: use DDD + número.', 'error'); redirect($volta); }
        if ($tel !== '') {
            $var = proTelVariantes($tel);
            $q = $db->prepare('SELECT nome FROM pro_prospects WHERE telefone IN (' . implode(',', array_fill(0, count($var), '?')) . ') AND id <> ? LIMIT 1');
            $q->execute([...$var, $id]);
            if ($outro = $q->fetchColumn()) { flash('Esse telefone já é de outro contato: ' . $outro . '.', 'error'); redirect($volta); }
        }
        $uf = strtoupper(trim((string)($_POST['uf'] ?? '')));
        $ig = trim((string)($_POST['instagram'] ?? '')); if ($ig !== '' && $ig[0] !== '@' && !str_contains($ig, '/')) $ig = '@' . $ig;
        $db->prepare('UPDATE pro_prospects SET nome = ?, telefone = ?, cidade = ?, bairro = ?, uf = ?, segmento = ?, instagram = ?, atualizado_em = ? WHERE id = ?')
           ->execute([mb_substr($nome, 0, 190), $tel ?: null, mb_substr(trim((string)($_POST['cidade'] ?? '')), 0, 90) ?: null, mb_substr(trim((string)($_POST['bairro'] ?? '')), 0, 90) ?: null,
                      isset(PRO_UFS[$uf]) ? $uf : null, mb_substr(trim((string)($_POST['segmento'] ?? '')), 0, 80) ?: null, mb_substr($ig, 0, 120) ?: null, $agora, $id]);
        flash('Contato atualizado.'); redirect($volta);
    }

    if ($acao === 'obs') {
        $db->prepare('UPDATE pro_prospects SET obs = ?, atualizado_em = ? WHERE id = ?')->execute([mb_substr((string)($_POST['obs'] ?? ''), 0, 2000), $agora, (int)($_POST['id'] ?? 0)]);
        redirect(proUrl('prospeccao', ['c' => $cid] + array_filter(array_intersect_key($_GET, array_flip(['st', 'cid', 'seg', 'q', 'b'])), fn($x) => $x !== '')));
    }
    redirect($self);
}

// ── Listagem ────────────────────────────────────────────────
$fSt  = isset(PRO_STATUS[$_GET['st'] ?? '']) ? $_GET['st'] : 'novo';
$fCid = trim((string)($_GET['cid'] ?? ''));
$fSeg = trim((string)($_GET['seg'] ?? ''));
$fQ   = trim((string)($_GET['q'] ?? ''));
$fB   = (string)($_GET['b'] ?? '');
$w = ['campanha_id = ?', 'status = ?']; $par = [$cid, $fSt];
if ($fCid !== '') { $w[] = 'cidade = ?'; $par[] = $fCid; }
if ($fSeg !== '') { $w[] = 'segmento = ?'; $par[] = $fSeg; }
if ($fQ !== '')   { $w[] = '(nome LIKE ? OR bairro LIKE ?)'; $par[] = "%$fQ%"; $par[] = "%$fQ%"; }
if ($fB === '__sem__') $w[] = 'busca IS NULL';
elseif ($fB !== '')    { $w[] = 'busca = ?'; $par[] = $fB; }
$st = $db->prepare('SELECT * FROM pro_prospects WHERE ' . implode(' AND ', $w) . ' ORDER BY (nota IS NULL), nota DESC, avaliacoes DESC, id LIMIT 200');
$st->execute($par);
$lista = $st->fetchAll();
$q1 = $db->prepare('SELECT status, COUNT(*) FROM pro_prospects WHERE campanha_id = ? GROUP BY status'); $q1->execute([$cid]);
$contagem = $q1->fetchAll(PDO::FETCH_KEY_PAIR);
$q2 = $db->prepare('SELECT DISTINCT cidade FROM pro_prospects WHERE campanha_id = ? AND cidade IS NOT NULL ORDER BY cidade'); $q2->execute([$cid]); $cidades = $q2->fetchAll(PDO::FETCH_COLUMN);
$q3 = $db->prepare('SELECT DISTINCT segmento FROM pro_prospects WHERE campanha_id = ? AND segmento IS NOT NULL ORDER BY segmento'); $q3->execute([$cid]); $segs = $q3->fetchAll(PDO::FETCH_COLUMN);
$q4 = $db->prepare("SELECT busca, COUNT(*) AS total, SUM(status = 'novo') AS novos, MAX(criado_em) AS em FROM pro_prospects WHERE campanha_id = ? GROUP BY busca ORDER BY (busca IS NULL), em DESC LIMIT 30");
$q4->execute([$cid]); $pesquisas = $q4->fetchAll();
$hoje = proContatadosHoje($db, $cid);
$temClaude = trim(getPConfig('claude_api_key')) !== '';
$chaveG = trim(getPConfig('google_places_key'));
$buscas = json_decode(getPConfig('buscas_' . $cid), true) ?: [];
$_ub = ($_SESSION['pr_ult_busca'] ?? []) + ['tipo' => '', 'local' => '', 'uf' => '', 'qtd' => 20];
$total = array_sum($contagem);
$conv = ($contagem['enviado'] ?? 0) + ($contagem['respondeu'] ?? 0) + ($contagem['interessado'] ?? 0) + ($contagem['cliente'] ?? 0) + ($contagem['perdido'] ?? 0);
$taxaResp = $conv ? round(100 * (($contagem['respondeu'] ?? 0) + ($contagem['interessado'] ?? 0) + ($contagem['cliente'] ?? 0)) / $conv) : 0;
$sugestoes = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$camp['busca_sugestoes']))));
$todasCamps = $db->query('SELECT id, nome, ativa FROM pro_campanhas ORDER BY ativa DESC, id DESC')->fetchAll();

proHeader('Prospecção', 'prospeccao');
?>
<form method="GET" class="linha" style="margin:-4px 0 14px;">
  <input type="hidden" name="p" value="prospeccao">
  <div class="campo" style="margin:0;min-width:240px;"><label class="rotulo">Campanha</label>
    <select name="c" class="input" onchange="this.form.submit()"><?php foreach ($todasCamps as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $cid ? 'selected' : '' ?>><?= e($c['nome']) ?><?= $c['ativa'] ? '' : ' (arquivada)' ?></option><?php endforeach; ?></select></div>
  <div class="dica" style="padding-bottom:10px;">Vende <b><?= e($camp['produto']) ?></b> para <b><?= e($camp['ramo']) ?></b> · <a href="<?= e(proUrl('campanhas', ['id' => $cid])) ?>">editar campanha</a></div>
</form>

<div class="aviso">
  ⚠️ Envie pelo <b>seu WhatsApp pessoal ou de vendas</b> (o botão verde abre o app) ou pelo DM do Instagram — <b>nunca</b> em massa pela API oficial para quem não pediu contato.
  Disparo frio derruba a qualidade do número. Mantenha volume baixo: hoje <b id="prHoje"><?= $hoje ?></b> de <?= $limite ?> sugeridos.
  Se alguém pedir para não receber, marque <b>🚫 Não contatar</b>: o número fica bloqueado para sempre, em todas as campanhas.
</div>

<div class="funil">
  <?php foreach (PRO_STATUS as $k => $rot): ?>
    <a href="<?= e(proUrl('prospeccao', ['c' => $cid, 'st' => $k] + ($fB !== '' ? ['b' => $fB] : []))) ?>" class="<?= $fSt === $k ? 'on' : '' ?>"><b><?= (int)($contagem[$k] ?? 0) ?></b><?= $rot ?></a>
  <?php endforeach; ?>
</div>
<div class="dica" style="margin:-6px 0 14px;"><?= $total ?> contatos nesta campanha · taxa de resposta <?= $taxaResp ?>% dos contatados</div>

<div class="painel">
  <div class="painel-h"><div class="painel-t">🔎 Buscar no Google</div></div>
  <div class="painel-b">
    <?php if ($chaveG !== ''): ?>
    <form method="POST" class="linha" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Buscando…';">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="buscar">
      <div class="campo" style="flex:2;min-width:200px;margin:0;"><label class="rotulo">Tipo de negócio</label>
        <input type="text" name="tipo" class="input" list="prTipos" placeholder="<?= e($sugestoes[0] ?? 'Ex.: tipo de negócio do ramo') ?>" value="<?= e($_ub['tipo']) ?>" required></div>
      <div class="campo" style="width:110px;margin:0;"><label class="rotulo">Estado</label>
        <select name="uf" id="prUf" class="input" required><option value="">UF</option><?php foreach (PRO_UFS as $sg => $nm): ?><option value="<?= $sg ?>" <?= ($_ub['uf'] ?? '') === $sg ? 'selected' : '' ?>><?= $sg ?></option><?php endforeach; ?></select></div>
      <div class="campo" style="flex:2;min-width:180px;margin:0;"><label class="rotulo">Cidade</label>
        <input type="text" name="local" id="prCidade" class="input" list="prCidades" placeholder="Escolha o estado e digite a cidade" value="<?= e($_ub['local']) ?>" autocomplete="off" required>
        <datalist id="prCidades"></datalist></div>
      <div class="campo" style="width:110px;margin:0;"><label class="rotulo">Quantidade</label>
        <select name="qtd" class="input"><?php foreach ([20, 40, 60] as $q): ?><option value="<?= $q ?>" <?= (int)$_ub['qtd'] === $q ? 'selected' : '' ?>>até <?= $q ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-p">🔎 Buscar</button>
      <datalist id="prTipos"><?php foreach ($sugestoes as $op): ?><option value="<?= e($op) ?>"><?php endforeach; ?></datalist>
    </form>
    <div class="dica" style="margin-top:8px;">Os resultados entram na lista abaixo como <b>🆕 Novo</b>. Só ficam os que são <b>exatamente da cidade e do estado escolhidos</b>. Quem já está na base (em qualquer campanha) é ignorado, quem está em "não contatar" nunca entra. O Google devolve no máximo 60 por busca.</div>
    <script>
    (function () {
      var uf = document.getElementById('prUf'), dl = document.getElementById('prCidades'), cache = {};
      function carregar() {
        var u = uf.value; dl.innerHTML = ''; if (!u) return;
        var pr = cache[u] || (cache[u] = fetch('index.php?p=prospeccao&ajax=cidades&uf=' + u, {credentials: 'same-origin'}).then(function (r) { return r.json(); }));
        pr.then(function (j) { if (uf.value !== u) return; (j.cidades || []).forEach(function (c) { var o = document.createElement('option'); o.value = c; dl.appendChild(o); }); }).catch(function () {});
      }
      uf.addEventListener('change', function () { document.getElementById('prCidade').value = ''; carregar(); });
      carregar();
    })();
    </script>
    <?php if ($buscas): ?>
      <details style="margin-top:8px;"><summary class="dica">Últimas buscas</summary>
        <div class="dica" style="margin-top:6px;"><?php foreach ($buscas as $b): ?><?= e($b['em']) ?> — <b><?= e($b['q']) ?></b>: <?= (int)$b['achou'] ?> encontrados, <?= (int)$b['novos'] ?> novos<br><?php endforeach; ?></div></details>
    <?php endif; ?>
    <?php else: ?>
    <div class="aviso" style="margin:0;">Para buscar direto daqui falta a <b>chave de API do Google</b> (uma vez só). Cadastre em <a href="<?= e(proUrl('config')) ?>">Configurações → Google</a> — o passo a passo está lá.
      Enquanto isso, você pode importar uma planilha ou usar a "Minha lista" abaixo.</div>
    <?php endif; ?>
  </div>
</div>

<div class="painel">
  <div class="painel-h"><div class="painel-t">📇 Minha lista — contatos que você já conhece</div>
    <a class="btn btn-sm" href="<?= e(proUrl('prospeccao', ['c' => $cid, 'st' => 'novo', 'b' => 'Minha lista'])) ?>#resultados">Ver minha lista</a></div>
  <div class="painel-b">
    <form method="POST" style="display:grid;gap:10px;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="manual_add">
      <div class="linha">
        <div class="campo" style="flex:2;min-width:180px;margin:0;"><label class="rotulo">Nome do negócio / contato</label><input type="text" name="nome" class="input"></div>
        <div class="campo" style="flex:1;min-width:150px;margin:0;"><label class="rotulo">WhatsApp (com DDD)</label><input type="tel" name="telefone" class="input" placeholder="(48) 99999-9999" inputmode="tel"></div>
        <div class="campo" style="flex:1;min-width:140px;margin:0;"><label class="rotulo">Cidade</label><input type="text" name="cidade" class="input"></div>
        <div class="campo" style="width:90px;margin:0;"><label class="rotulo">UF</label>
          <select name="uf" class="input"><option value="">—</option><?php foreach (PRO_UFS as $sg => $nm): ?><option value="<?= $sg ?>"><?= $sg ?></option><?php endforeach; ?></select></div>
        <div class="campo" style="flex:1;min-width:130px;margin:0;"><label class="rotulo">Segmento</label><input type="text" name="segmento" class="input"></div>
      </div>
      <div class="campo" style="margin:0;"><label class="rotulo">Anotação (opcional)</label><input type="text" name="obs" class="input" placeholder="Ex.: conheci num evento, tem 3 filiais"></div>
      <details><summary class="dica">➕ Colar vários de uma vez</summary>
        <textarea name="varios" class="input" rows="5" style="margin-top:6px;" placeholder="Um por linha:  nome; telefone; cidade; UF; segmento&#10;Empresa A; 48 99999-1111; Palhoça; SC&#10;Empresa B; (48) 98888-2222"></textarea>
        <div class="dica" style="margin-top:4px;">Só nome e telefone são obrigatórios. Também aceita "Nome 48999991111" por linha.</div>
      </details>
      <div><button class="btn btn-p btn-sm">Adicionar à minha lista</button>
        <span class="dica" style="margin-left:6px;">Entram como 🆕 Novo, na pesquisa "Minha lista". Repetidos são ignorados e "não contatar" é respeitado.</span></div>
    </form>
  </div>
</div>

<div class="grid-2">
  <div class="painel">
    <div class="painel-h"><div class="painel-t">📥 Ou importar uma planilha (CSV)</div></div>
    <div class="painel-b">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="importar">
        <div class="campo"><label class="rotulo">Arquivo CSV (Outscraper ou Apify)</label><input type="file" name="csv" accept=".csv,text/csv" class="input" required></div>
        <div class="campo"><label class="rotulo">Segmento (opcional — senão detecta pelo nome/categoria usando os segmentos da campanha)</label><input type="text" name="segmento" class="input"></div>
        <button class="btn btn-p btn-sm">Importar</button>
      </form>
      <div class="dica" style="margin-top:8px;">Busque no Outscraper/Apify por "tipo de negócio em cidade" (um tipo e uma cidade por vez) e exporte em CSV. Repetidos e "não contatar" são reconhecidos pelo telefone.</div>
    </div>
  </div>
  <div class="painel">
    <div class="painel-h"><div class="painel-t">✍️ Como o Claude escreve</div></div>
    <div class="painel-b">
      <div class="dica" style="line-height:1.7;">
        <b>Vende:</b> <?= e($camp['produto']) ?><br><b>Ramo:</b> <?= e($camp['ramo']) ?><br><b>Quem assina:</b> <?= e($camp['persona'] ?: '—') ?><br>
        <b>Descrição usada:</b> <?= e(mb_substr((string)$camp['pitch'], 0, 220)) ?><?= mb_strlen((string)$camp['pitch']) > 220 ? '…' : '' ?>
      </div>
      <p><a class="btn btn-sm" href="<?= e(proUrl('campanhas', ['id' => $cid])) ?>">Ajustar textos da campanha</a></p>
      <?php if (!$temClaude): ?><div class="dica x">Falta a chave do Claude em <a href="<?= e(proUrl('config')) ?>">Configurações</a>.</div><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($pesquisas): ?>
<div class="painel" id="resultados">
  <div class="painel-b">
    <div class="rotulo">📋 Pesquisas</div>
    <div class="chips">
      <a href="<?= e(proUrl('prospeccao', ['c' => $cid, 'st' => $fSt])) ?>" class="<?= $fB === '' ? 'on' : '' ?>">Todas</a>
      <?php foreach ($pesquisas as $pq): $k = $pq['busca'] === null ? '__sem__' : $pq['busca']; ?>
        <a href="<?= e(proUrl('prospeccao', ['c' => $cid, 'st' => $fSt, 'b' => $k])) ?>" class="<?= $fB === $k ? 'on' : '' ?>"><?= e($pq['busca'] ?? 'Sem pesquisa (antigos)') ?> <span><?= (int)$pq['novos'] ?>/<?= (int)$pq['total'] ?></span></a>
      <?php endforeach; ?>
    </div>
    <div class="linha" style="margin-top:10px;">
      <?php if ($fB !== ''): ?>
      <form method="POST" onsubmit="return confirm('Limpar os contatos ainda não contatados desta pesquisa? Quem já recebeu mensagem continua na lista.');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="limpar"><input type="hidden" name="b" value="<?= e($fB) ?>">
        <button class="btn btn-sm">🗑️ Limpar esta pesquisa</button></form>
      <?php endif; ?>
      <form method="POST" onsubmit="return confirm('Limpar TODOS os contatos em Novo desta campanha? Quem já recebeu mensagem, respondeu ou pediu para não ser contatado continua na lista.');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="limpar"><input type="hidden" name="b" value="__todos__">
        <button class="btn btn-sm">🧹 Limpar todos os novos</button></form>
    </div>
    <div class="dica" style="margin-top:6px;">Números: ainda não contatados / total da pesquisa. Limpar apaga só quem está em "Novo".</div>
  </div>
</div>
<?php endif; ?>

<form method="GET" class="linha" style="margin-bottom:12px;">
  <input type="hidden" name="p" value="prospeccao"><input type="hidden" name="c" value="<?= $cid ?>"><input type="hidden" name="st" value="<?= e($fSt) ?>"><input type="hidden" name="b" value="<?= e($fB) ?>">
  <input type="text" name="q" class="input" style="flex:1;min-width:160px;width:auto;" placeholder="Buscar nome ou bairro" value="<?= e($fQ) ?>">
  <select name="cid" class="input" style="max-width:200px;width:auto;"><option value="">Todas as cidades</option><?php foreach ($cidades as $c): ?><option <?= $fCid === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
  <select name="seg" class="input" style="max-width:200px;width:auto;"><option value="">Todos os segmentos</option><?php foreach ($segs as $m): ?><option <?= $fSeg === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm">Filtrar</button>
  <?php if ($fSt === 'novo' && $lista && $temClaude): ?><button type="button" class="btn btn-p btn-sm" id="prGerarTodos">✍️ Escrever mensagens desta lista</button><?php endif; ?>
</form>

<div id="prLista">
<?php if (!$lista): ?>
  <div class="vazio">Nada aqui. <?= $fSt === 'novo' ? 'Faça uma busca no Google, importe uma planilha ou adicione à sua lista.' : '' ?></div>
<?php endif; ?>
<?php foreach ($lista as $p): $bloq = $p['status'] === 'nao_contatar'; ?>
  <div class="pcard" data-id="<?= (int)$p['id'] ?>" data-tel="<?= e($p['telefone']) ?>" data-ig="<?= e(vdIgUser((string)$p['instagram'])) ?>">
    <div class="ptop">
      <div>
        <div class="pnome"><?= e($p['nome']) ?></div>
        <div class="pmeta">
          <?= e(implode(' · ', array_filter([$p['segmento'], trim(($p['bairro'] ?? '') . ($p['bairro'] && $p['cidade'] ? ', ' : '') . ($p['cidade'] ?? ''))]))) ?>
          <?= $p['nota'] !== null ? ' · ⭐ ' . e($p['nota']) . ($p['avaliacoes'] ? ' (' . (int)$p['avaliacoes'] . ')' : '') : '' ?>
          <?= $p['telefone'] ? ' · 📱 ' . e($p['telefone']) : '' ?>
          <?= $p['instagram'] ? ' · <a href="https://instagram.com/' . e(ltrim($p['instagram'], '@')) . '" target="_blank" rel="noopener">' . e($p['instagram']) . '</a>' : '' ?>
          <?= $p['site'] ? ' · <a href="' . e($p['site']) . '" target="_blank" rel="noopener">site</a>' : '' ?>
          <?= !empty($p['maps_url']) ? ' · <a href="' . e($p['maps_url']) . '" target="_blank" rel="noopener">📍 Maps</a>' : '' ?>
        </div>
      </div>
      <select class="input pr-status" style="max-width:170px;width:auto;padding:6px;font-size:.78rem;">
        <?php foreach (PRO_STATUS as $k => $rot): ?><option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= $rot ?></option><?php endforeach; ?>
      </select>
    </div>
    <textarea class="pmsg" placeholder="<?= $temClaude ? 'Clique em ✍️ para o Claude escrever, ou escreva aqui' : 'Escreva a mensagem' ?>"><?= e($p['mensagem']) ?></textarea>
    <div class="pacoes">
      <?php if ($temClaude): ?><button type="button" class="btn pr-gerar"><?= $p['mensagem'] ? '🔄 Reescrever' : '✍️ Escrever' ?></button><?php endif; ?>
      <?php if ($p['telefone'] && !$bloq): ?><button type="button" class="btn btn-wa pr-wa">💬 Abrir no WhatsApp</button><?php endif; ?>
      <?php if ($p['instagram'] && !$bloq): ?><button type="button" class="btn pr-ig" title="Copia a mensagem e abre a conversa no Instagram: cole e envie">📸 Abrir no Instagram</button><?php endif; ?>
      <?php if ($vdOn && ($p['telefone'] || $p['instagram']) && !$bloq && ($p['status'] !== 'novo' || (int)$p['ia_ativa'])): ?>
        <button type="button" class="btn pr-ia" data-ia="<?= (int)$p['ia_ativa'] ?>" title="Quando o contato responder, o Vendedor IA continua a conversa"><?= (int)$p['ia_ativa'] ? ((int)$p['ia_pausada'] ? '🙋 Você assumiu' : '🤖 IA ligada') : '🤖 IA desligada' ?></button>
        <?php if (!empty($p['ia_ultima_em'])): ?><a class="btn" href="<?= e(proUrl('vendedor_ia', ['id' => $p['id']])) ?>">💬 Conversa<?= (int)$p['ia_nao_lidas'] ? ' (' . (int)$p['ia_nao_lidas'] . ')' : '' ?></a><?php endif; ?>
      <?php endif; ?>
      <button type="button" class="btn pr-copiar">📋 Copiar</button>
      <span class="dica pr-info"></span>
    </div>
    <details class="pr-pesq"<?= !empty($p['pesquisa']) ? ' open' : '' ?>><summary class="dica" style="margin-top:8px;">🔎 Pesquisa do contato<?= !empty($p['pesquisa_em']) ? ' · feita em ' . e(date('d/m/Y', strtotime($p['pesquisa_em']))) : '' ?></summary>
      <div class="pr-ficha"><?= ppFichaHtml(!empty($p['pesquisa']) ? json_decode((string)$p['pesquisa'], true) : null) ?></div>
      <div class="pr-pesq-acao">
        <input type="file" class="pr-prints" accept="image/*" multiple>
        <button type="button" class="btn btn-sm pr-pesquisar"><?= !empty($p['pesquisa']) ? '🔄 Pesquisar de novo' : '🔎 Pesquisar' ?></button>
        <span class="dica pr-pesq-info"><?= $p['site'] ? 'Lê o site e usa a nota do Google. ' : 'Sem site cadastrado. ' ?>Anexe prints do Instagram/Facebook (até 4) para incluir as redes.</span>
      </div>
    </details>
    <details class="pr-edit"><summary class="dica" style="margin-top:8px;">✏️ Editar contato</summary>
      <form method="POST" class="linha" style="margin-top:8px;">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="editar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <?php foreach (['st' => $fSt, 'cid' => $fCid, 'seg' => $fSeg, 'q' => $fQ, 'b' => $fB] as $hk => $hv): ?><input type="hidden" name="<?= $hk ?>" value="<?= e($hv) ?>"><?php endforeach; ?>
        <div class="campo" style="flex:2;min-width:170px;margin:0;"><label class="rotulo">Nome</label><input type="text" name="nome" class="input" value="<?= e($p['nome']) ?>" required></div>
        <div class="campo" style="flex:1;min-width:140px;margin:0;"><label class="rotulo">WhatsApp</label><input type="tel" name="telefone" class="input" value="<?= e($p['telefone']) ?>"></div>
        <div class="campo" style="flex:1;min-width:120px;margin:0;"><label class="rotulo">Cidade</label><input type="text" name="cidade" class="input" value="<?= e($p['cidade']) ?>"></div>
        <div class="campo" style="flex:1;min-width:110px;margin:0;"><label class="rotulo">Bairro</label><input type="text" name="bairro" class="input" value="<?= e($p['bairro']) ?>"></div>
        <div class="campo" style="width:80px;margin:0;"><label class="rotulo">UF</label>
          <select name="uf" class="input"><option value="">—</option><?php foreach (PRO_UFS as $sg => $nm): ?><option value="<?= $sg ?>" <?= strtoupper((string)$p['uf']) === $sg ? 'selected' : '' ?>><?= $sg ?></option><?php endforeach; ?></select></div>
        <div class="campo" style="flex:1;min-width:110px;margin:0;"><label class="rotulo">Segmento</label><input type="text" name="segmento" class="input" value="<?= e($p['segmento']) ?>"></div>
        <div class="campo" style="flex:1;min-width:120px;margin:0;"><label class="rotulo">Instagram</label><input type="text" name="instagram" class="input" value="<?= e($p['instagram']) ?>" placeholder="@perfil"></div>
        <button class="btn btn-p btn-sm">💾 Salvar</button>
        <button class="btn btn-sm btn-perigo" name="acao" value="excluir" formnovalidate onclick="return confirm('Excluir este contato da lista? (a conversa do Vendedor IA também é apagada)');">🗑️ Excluir</button>
      </form>
    </details>
    <?php if ($p['obs'] || $p['status'] !== 'novo'): ?>
    <form method="POST" action="<?= e(proUrl('prospeccao', ['c' => $cid, 'st' => $fSt, 'cid' => $fCid, 'seg' => $fSeg, 'q' => $fQ, 'b' => $fB])) ?>" class="linha" style="margin-top:8px;flex-wrap:nowrap;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="obs"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="text" name="obs" class="input" style="flex:1;padding:6px;font-size:.78rem" placeholder="Anotação (ex.: respondeu, quer reunião sexta)" value="<?= e($p['obs']) ?>">
      <button class="btn btn-sm">💾</button>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<script>
(function () {
  var CSRF = <?= json_encode(csrfToken()) ?>, LIMITE = <?= (int)$limite ?>, VD_ON = <?= $vdOn ? 'true' : 'false' ?>;
  var URL_POST = <?= json_encode(proUrl('prospeccao', ['c' => $cid])) ?>;
  function post(dados) {
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('ajax', '1');
    Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
    return fetch(URL_POST, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function card(el) { return el.closest('.pcard'); }
  function info(c, t) { c.querySelector('.pr-info').textContent = t; }
  function hojeAtual() { return parseInt(document.getElementById('prHoje').textContent, 10) || 0; }
  function gerar(c) {
    var b = c.querySelector('.pr-gerar'); if (b) b.disabled = true;
    info(c, 'escrevendo…');
    return post({ acao: 'gerar', id: c.dataset.id }).then(function (j) {
      if (b) b.disabled = false;
      if (!j.ok) { info(c, '⚠️ ' + j.erro); return false; }
      c.querySelector('.pmsg').value = j.mensagem; info(c, '✓ pronta — revise antes de enviar');
      if (b) b.textContent = '🔄 Reescrever';
      return true;
    }).catch(function () { if (b) b.disabled = false; info(c, '⚠️ falhou, tente de novo'); return false; });
  }
  var lista = document.getElementById('prLista');
  lista.addEventListener('click', function (ev) {
    var t = ev.target, c = card(t); if (!c) return;
    if (t.closest('.pr-gerar')) { gerar(c); return; }
    if (t.closest('.pr-copiar')) {
      var txt = c.querySelector('.pmsg').value; if (!txt) return;
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { info(c, '✓ copiada'); }, function () { c.querySelector('.pmsg').select(); document.execCommand('copy'); info(c, '✓ copiada'); });
      return;
    }
    if (t.closest('.pr-wa')) {
      var msg = c.querySelector('.pmsg').value.trim();
      if (!msg) { info(c, 'escreva a mensagem primeiro'); return; }
      if (c.querySelector('.pr-status').value === 'nao_contatar') { info(c, '🚫 pediu para não ser contatado'); return; }
      if (hojeAtual() >= LIMITE && !confirm('Você já abordou ' + hojeAtual() + ' contatos hoje (limite sugerido: ' + LIMITE + '). Enviar mesmo assim?')) return;
      window.open('https://wa.me/' + c.dataset.tel + '?text=' + encodeURIComponent(msg), '_blank');
      post({ acao: 'enviou', id: c.dataset.id, mensagem: msg }).then(function (j) {
        if (!j.ok) { info(c, '⚠️ ' + (j.erro || 'não consegui marcar como enviada')); return; }
        var s = c.querySelector('.pr-status'); if (s.value === 'novo') s.value = 'enviado';
        if (j.hoje !== undefined) document.getElementById('prHoje').textContent = j.hoje;
        info(c, VD_ON ? '✓ enviada · 🤖 IA ligada para a resposta' : '✓ marcada como enviada');
      });
      return;
    }
    if (t.closest('.pr-ig')) {
      var msgI = c.querySelector('.pmsg').value.trim();
      if (!msgI) { info(c, 'escreva a mensagem primeiro'); return; }
      if (c.querySelector('.pr-status').value === 'nao_contatar') { info(c, '🚫 pediu para não ser contatado'); return; }
      if (hojeAtual() >= LIMITE && !confirm('Você já abordou ' + hojeAtual() + ' contatos hoje (limite sugerido: ' + LIMITE + '). Enviar mesmo assim?')) return;
      // O Instagram não aceita texto pronto no link: copia para você colar no Direct
      var copiou = navigator.clipboard ? navigator.clipboard.writeText(msgI) : Promise.reject();
      copiou.catch(function () { var ta = c.querySelector('.pmsg'); ta.select(); try { document.execCommand('copy'); } catch (e) {} });
      window.open('https://ig.me/m/' + encodeURIComponent(c.dataset.ig), '_blank');
      post({ acao: 'enviou', id: c.dataset.id, mensagem: msgI, canal: 'instagram' }).then(function (j) {
        if (!j.ok) { info(c, '⚠️ ' + (j.erro || 'não consegui marcar como enviada')); return; }
        var s = c.querySelector('.pr-status'); if (s.value === 'novo') s.value = 'enviado';
        if (j.hoje !== undefined) document.getElementById('prHoje').textContent = j.hoje;
        info(c, '📋 mensagem copiada — cole no Direct e envie' + (VD_ON ? ' · 🤖 IA ligada para a resposta' : ''));
      });
      return;
    }
    if (t.closest('.pr-pesquisar')) {
      var pb = t.closest('.pr-pesquisar'), pinfo = c.querySelector('.pr-pesq-info'), arqs = c.querySelector('.pr-prints').files;
      var fd = new FormData(); fd.append('csrf', CSRF); fd.append('ajax', '1'); fd.append('acao', 'pesquisar'); fd.append('id', c.dataset.id);
      for (var k = 0; k < arqs.length && k < 4; k++) fd.append('prints[]', arqs[k]);
      pb.disabled = true; pinfo.textContent = 'pesquisando… (pode levar até 1 minuto)';
      fetch(URL_POST, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        pb.disabled = false;
        if (!j.ok) { pinfo.textContent = '⚠️ ' + j.erro + (j.avisos && j.avisos.length ? ' (' + j.avisos.join('; ') + ')' : ''); return; }
        c.querySelector('.pr-ficha').innerHTML = j.html; c.querySelector('.pr-pesq').open = true;
        pb.textContent = '🔄 Pesquisar de novo'; c.querySelector('.pr-prints').value = '';
        pinfo.textContent = '✓ pronta' + (j.avisos && j.avisos.length ? ' · avisos: ' + j.avisos.join('; ') : '');
      }).catch(function () { pb.disabled = false; pinfo.textContent = '⚠️ demorou demais ou falhou, tente de novo'; });
      return;
    }
    if (t.closest('.pr-ia')) {
      var b = t.closest('.pr-ia'), ligar = b.dataset.ia !== '1';
      post({ acao: 'ia', id: c.dataset.id, ligar: ligar ? 1 : '' }).then(function (j) {
        if (!j.ok) return; b.dataset.ia = j.ia ? '1' : '0'; b.textContent = j.ia ? '🤖 IA ligada' : '🤖 IA desligada';
      });
    }
  });
  lista.addEventListener('change', function (ev) {
    if (!ev.target.classList.contains('pr-status')) return;
    var c = card(ev.target), st = ev.target.value;
    if (st === 'nao_contatar' && !confirm('Marcar como NÃO CONTATAR? O número fica bloqueado para sempre, em todas as campanhas.')) { location.reload(); return; }
    post({ acao: 'status', id: c.dataset.id, status: st }).then(function (j) {
      if (j.ok) { info(c, '✓ status salvo'); if (j.hoje != null) document.getElementById('prHoje').textContent = j.hoje; if (st === 'nao_contatar') location.reload(); }
      else info(c, '⚠️ ' + (j.erro || 'falhou'));
    });
  });
  lista.addEventListener('blur', function (ev) {
    if (ev.target.classList.contains('pmsg')) post({ acao: 'salvar_msg', id: card(ev.target).dataset.id, mensagem: ev.target.value });
  }, true);
  // Escrever todas desta lista, uma por vez (cada uma é uma chamada curta — sem 504)
  var bt = document.getElementById('prGerarTodos');
  if (bt) bt.addEventListener('click', function () {
    var cards = Array.prototype.filter.call(document.querySelectorAll('.pcard'), function (c) { return !c.querySelector('.pmsg').value.trim(); });
    if (!cards.length) { alert('Todas desta lista já têm mensagem.'); return; }
    if (!confirm('O Claude vai escrever ' + cards.length + ' mensagem(ns), uma por vez. Continuar?')) return;
    bt.disabled = true; var i = 0;
    (function prox() {
      if (i >= cards.length) { bt.disabled = false; bt.textContent = '✓ Mensagens prontas'; return; }
      bt.textContent = '✍️ ' + (i + 1) + ' de ' + cards.length + '…';
      gerar(cards[i++]).then(prox);
    })();
  });
})();
</script>
<?php proFooter();
