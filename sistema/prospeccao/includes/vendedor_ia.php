<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════════
// Vendedor IA — conversas de venda pelo WhatsApp e pelo Instagram Direct, genérico por campanha.
// Portado do DojoSystem (includes/vendedor_ia.php) e adaptado para a Opa.
//
// WhatsApp:
//   1. Na Prospecção você manda a 1ª mensagem pelo app WhatsApp Business (link wa.me). O contato fica
//      marcado: ia_ativa = 1.
//   2. O número de vendas está na API pela "coexistência" (app + Cloud API no mesmo número). Quando o
//      contato RESPONDE, a mensagem chega por wa_webhook.php → pro_wa_fila → cron_wa_inbound.php.
//   3. Só contatos marcados recebem resposta da IA (exceção: quem chega por anúncio "clique para o
//      WhatsApp", conforme vend_anuncio). O resto você responde pelo app.
//   4. Se você escrever pelo app numa conversa em andamento (eco smb_message_echoes), a IA para.
//   5. Nota de voz do contato é transcrita (chave da OpenAI em Configurações) e a IA responde ao conteúdo.
//   6. "Parece gente": marca como lida, mostra "digitando…" e responde no tempo de uma pessoa.
//
// Instagram Direct (mesmo fluxo, outro canal):
//   • A Meta não deixa sistema nenhum INICIAR conversa no Direct: a 1ª mensagem sai à mão (a Prospecção
//     copia o texto e abre ig.me/m/perfil). As respostas chegam por ig_webhook.php → pro_wa_fila →
//     cron → vdIgItem(). A IA só responde dentro de 24 h da última mensagem do contato (regra da Meta).
//   • Opcional: quem comenta num post recebe uma DM (resposta privada) e entra na Prospecção.
//
// A IA fala em nome do dono (persona da campanha), mas se perguntarem se é robô, diz a verdade.
// Nunca inventa preço nem condição. Pedido de saída → "não contatar" permanente.
// ═══════════════════════════════════════════════════════════════
if (!function_exists('vdConfig')) {

require_once __DIR__ . '/claude_api.php';

function vdConfig(): array {
    $g = function ($k, $d = '') { $v = getPConfig('vend_' . $k); return $v === '' ? $d : $v; };
    $anuncio = $g('anuncio', 'anuncio');
    return [
        'ativo'     => $g('ativo', '0') === '1',
        'phone_id'  => trim($g('phone_id')),     // Phone Number ID do número de vendas (Cloud API)
        'empresa'   => getPConfig('empresa_nome', 'Opa Tecnologia e Design'),
        'digitando' => $g('digitando', '1') === '1',   // simula ler e digitar antes de responder (padrão: ligado)
        // Anúncio "clique para o WhatsApp": anuncio = só quem vem de anúncio | todos = qualquer contato novo | nao = ninguém novo
        'anuncio'   => in_array($anuncio, ['anuncio', 'todos', 'nao'], true) ? $anuncio : 'anuncio',
        'anuncio_campanha' => (int)$g('anuncio_campanha', '0'),   // campanha que atende esses contatos (0 = a mais recente ativa)
        // Instagram Direct
        'ig_ativo'  => $g('ig_ativo', '0') === '1',
        'ig_conta'  => trim($g('ig_conta')),     // ID da conta profissional (o "user_id" do webhook)
        'ig_usuario'=> trim($g('ig_usuario')),   // @ da conta, só para mostrar
        'ig_coment' => $g('ig_coment'),          // '' = não responde comentários | '*' = qualquer um | palavra-chave
        'ig_coment_msg' => $g('ig_coment_msg', 'Oi! Vi seu comentário aqui 🙂 Posso te contar rapidinho como funciona?'),
    ];
}

/** Telefone em E.164 só com dígitos (55 + DDD + número). */
function vdTel(string $t): string {
    $d = preg_replace('/\D/', '', $t);
    if ($d === '') return '';
    if (strlen($d) >= 12 && strpos($d, '55') === 0) return $d;
    return (strlen($d) === 10 || strlen($d) === 11) ? '55' . $d : $d;
}

/** Contato pelo telefone (com e sem o 9º dígito). */
function vdProspectPorTelefone(PDO $db, string $e164): ?array {
    $d = vdTel($e164);
    if (strlen($d) < 12) return null;
    $var = proTelVariantes($d);
    $in = implode(',', array_fill(0, count($var), '?'));
    $st = $db->prepare("SELECT * FROM pro_prospects WHERE telefone IN ($in) ORDER BY ia_ativa DESC, id DESC LIMIT 1");
    $st->execute($var);
    return $st->fetch() ?: null;
}

function vdGravar(PDO $db, int $pid, string $autor, string $texto, ?string $wamid = null, string $canal = 'whatsapp'): void {
    $db->prepare('INSERT INTO pro_vd_mensagens (prospect_id, autor, texto, wamid, criado_em, canal) VALUES (?,?,?,?,?,?)')
       ->execute([$pid, $autor, mb_substr($texto, 0, 4000), $wamid, proAgora(), $canal]);
    $db->prepare('UPDATE pro_prospects SET ia_ultima_em = ?' . ($autor === 'cliente' ? ', ia_nao_lidas = ia_nao_lidas + 1' : '') . ' WHERE id = ?')
       ->execute([proAgora(), $pid]);
}

function vdHistorico(PDO $db, int $pid, int $lim = 20): array {
    $st = $db->prepare('SELECT autor, texto, criado_em, canal FROM pro_vd_mensagens WHERE prospect_id = ? ORDER BY id DESC LIMIT ' . (int)$lim);
    $st->execute([$pid]);
    return array_reverse($st->fetchAll());
}

/**
 * Marca o contato para a IA quando a 1ª mensagem sai pelo sistema e guarda o texto
 * enviado como início da conversa (a IA precisa saber o que foi dito).
 */
function vdMarcarEnvio(PDO $db, int $pid, string $texto, string $canal = 'whatsapp'): void {
    $canal = $canal === 'instagram' ? 'instagram' : 'whatsapp';
    $db->prepare('UPDATE pro_prospects SET ia_ativa = 1, ia_pausada = 0, ia_canal = ? WHERE id = ?')->execute([$canal, $pid]);
    $st = $db->prepare("SELECT COUNT(*) FROM pro_vd_mensagens WHERE prospect_id = ? AND COALESCE(canal, 'whatsapp') = ?"); $st->execute([$pid, $canal]);
    if ((int)$st->fetchColumn() === 0 && trim($texto) !== '') vdGravar($db, $pid, 'voce', trim($texto), null, $canal);
}

/** Envia texto pelo número de vendas (Cloud API). */
function vdEnviar(string $para, string $texto): array {
    $cfg = vdConfig();
    $token = trim(getPConfig('meta_wpp_token'));
    if ($token === '' || $cfg['phone_id'] === '') return ['ok' => false, 'erro' => 'Número de vendas não configurado (token da Meta e Phone Number ID).'];
    $ch = curl_init(vdGraphUrl($cfg['phone_id'] . '/messages'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => vdTel($para), 'type' => 'text',
                                           'text' => ['body' => $texto, 'preview_url' => true]], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $res = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$res, true) ?: [];
    if (!empty($j['messages'][0]['id'])) return ['ok' => true, 'wamid' => (string)$j['messages'][0]['id']];
    return ['ok' => false, 'erro' => (string)($j['error']['message'] ?? 'falha no envio')];
}

/** URL da Graph API do WhatsApp (constante PRO_GRAPH_URL só para teste). */
function vdGraphUrl(string $caminho): string {
    return (defined('PRO_GRAPH_URL') ? PRO_GRAPH_URL : 'https://graph.facebook.com') . '/' . (getPConfig('meta_wpp_version') ?: 'v21.0') . '/' . ltrim($caminho, '/');
}

/** Envia pelo canal da conversa (ia_canal): WhatsApp de vendas ou Direct. */
function vdEnviarPara(array $p, string $texto): array {
    if (($p['ia_canal'] ?? '') === 'instagram') {
        if (empty($p['ig_id'])) return ['ok' => false, 'erro' => 'Contato do Instagram ainda não identificado.'];
        return vdIgEnviar((string)$p['ig_id'], $texto);
    }
    return vdEnviar((string)$p['telefone'], $texto);
}

/** Janela de 24 h do canal: até quando dá para mandar pela API (null = fechada / nunca respondeu). */
function vdJanelaAte(PDO $db, int $pid, string $canal): ?int {
    $st = $db->prepare("SELECT MAX(criado_em) FROM pro_vd_mensagens WHERE prospect_id = ? AND autor = 'cliente' AND COALESCE(canal, 'whatsapp') = ?");
    $st->execute([$pid, $canal]);
    $ult = (string)$st->fetchColumn();
    if ($ult === '') return null;
    $ate = strtotime($ult) + 86000;   // um pouco antes das 24 h, por segurança
    return $ate > time() ? $ate : null;
}

/** Avisa o dono por e-mail (se houver e-mail de aviso) e marca o horário do aviso. */
function vdAvisar(PDO $db, array $p, string $texto): void {
    $para = trim(getPConfig('aviso_email'));
    if ($para !== '' && filter_var($para, FILTER_VALIDATE_EMAIL)) {
        try {
            $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? parse_url(proBaseUrl(), PHP_URL_HOST) ?: 'localhost'));
            $link = proBaseUrl() . '/' . proUrl('vendedor_ia', ['id' => (int)$p['id']]);
            @mail($para, '=?UTF-8?B?' . base64_encode('💬 ' . $p['nome']) . '?=', $texto . "\n\n" . $link,
                  "From: Prospecção <no-reply@{$host}>\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        } catch (Throwable $e) {}
    }
    $db->prepare('UPDATE pro_prospects SET ia_aviso_em = ? WHERE id = ?')->execute([proAgora(), (int)$p['id']]);
}

/**
 * Resposta da IA para o contato (sem enviar). Retorna
 * ['ok','resposta','acao','motivo'] — acao: nenhuma|link|humano|perdido|nao_contatar
 */
function vdPensar(PDO $db, array $p, array $camp, ?array $historico = null): array {
    $cfg = vdConfig();
    $nome = trim((string)$camp['persona']) ?: 'o responsável';
    $link = trim((string)$camp['link']);
    $oferta = trim((string)$camp['oferta']);
    $obj = (string)$camp['ia_objetivo'];
    if ($obj === 'link' && $link === '') $obj = 'proposta';   // sem link, não há o que enviar
    $objetivos = [
        'link'     => "levar a pessoa a abrir este link: {$link}" . ($oferta !== '' ? " (oferta: {$oferta})" : ''),
        'conversa' => "marcar uma CONVERSA (ligação ou vídeo) com você: pergunte o melhor dia e horário e, quando a pessoa disser, use acao \"humano\"",
        'proposta' => "entender a necessidade da pessoa e reunir as informações para você montar uma PROPOSTA; quando já tiver o suficiente, diga que vai montar e use acao \"humano\"",
    ];
    $quem = trim($p['nome'] . ($p['segmento'] ? ' · ' . $p['segmento'] : '') . ($p['cidade'] ? ' · ' . $p['cidade'] . ($p['uf'] ? '/' . $p['uf'] : '') : ''));
    $precos = trim((string)$camp['ia_precos']);
    $ig = ($p['ia_canal'] ?? '') === 'instagram';
    $canalTxt = $ig ? 'pelo Direct do Instagram' : 'pelo WhatsApp';
    $origem = (string)($p['origem'] ?? '');
    $hora = (int)date('G');
    $saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
    if ($origem === 'ig_comentario') $abertura = "Esta pessoa comentou num post seu no Instagram e você mandou a primeira mensagem no Direct; agora ela respondeu e você continua. Descubra com naturalidade se ela tem (ou cuida de) um negócio.\n";
    elseif (in_array($origem, ['anuncio', 'whatsapp'], true)) $abertura = "Esta pessoa viu seu anúncio e te chamou: ela escreveu primeiro. Agradeça o contato, apresente-se e descubra com naturalidade qual é o negócio dela e o que ela precisa.\n";
    else $abertura = "Você mandou a primeira mensagem; agora o contato respondeu e você continua.\n";
    $system = "Você é {$nome}, da {$cfg['empresa']}, conversando {$canalTxt} com negócios do ramo de {$camp['ramo']} no Brasil. " . $abertura
        . "FALE SEMPRE EM PRIMEIRA PESSOA, como o próprio {$nome} (\"eu monto\", \"te mando\", \"te mostro\"). NUNCA fale de {$nome} em terceira pessoa.\n"
        . "Estilo: português do Brasil, conversa de gente no " . ($ig ? 'Direct' : 'WhatsApp') . ", não texto de vendedor. MENSAGENS CURTAS: na maioria, 1 ou 2 frases bem curtas (até 30 palavras); só passe disso (até 3 frases) se a pessoa fez uma pergunta que exige explicação. Uma ideia por mensagem. Espelhe o jeito dela: se ela escreve duas palavras, você escreve uma linha. Sem parágrafo, sem lista, sem markdown, no máximo 1 emoji. NO MÁXIMO UMA pergunta por mensagem. Reaja SÓ ao que ela realmente disse: não invente observação sobre a rotina ou o momento dela nem elogio genérico."
        . (trim((string)$camp['tom']) !== '' ? " Tom: " . trim($camp['tom']) . "." : '') . "\n"
        . "Só cumprimente (agora é horário de \"{$saudacao}\", Brasília) na primeira resposta; nas seguintes, não repita saudação. Vocês NUNCA se falaram antes desta conversa (a menos que o histórico mostre o contrário): não finja intimidade, não diga \"lembrei de você\" nem \"faz tempo\".\n"
        . "OBJETIVO: " . $objetivos[$obj] . ". Aos poucos, e uma coisa por vez, entenda o negócio da pessoa"
        . (trim((string)$camp['dor_comum']) !== '' ? " (dores comuns do ramo: " . proLinhas((string)$camp['dor_comum']) . ")" : '')
        . " e mostre como \"{$camp['produto']}\" resolve a dor específica dela.\n"
        . "Mensagens que começam com 🎤 são ÁUDIOS que a pessoa mandou, transcritos automaticamente (podem ter palavras trocadas). Responda ao conteúdo como quem ouviu o áudio; se um trecho não fizer sentido, peça para repetir só esse trecho. Nunca diga que é uma transcrição nem repita o 🎤.\n"
        . "REGRAS:\n"
        . "1. Use SOMENTE as informações abaixo. Não invente funcionalidade, cliente, número, prazo ou garantia.\n"
        . "2. Preço: " . ($precos !== '' ? "pode informar SOMENTE isto: {$precos}" : "NÃO informe valores; explique em UMA frase curta que depende do caso e que você monta uma proposta certinha, e faça perguntas para entender o negócio. Quando a pessoa já tiver passado as informações e quiser a proposta, diga que já vai montar e acao \"humano\"") . ".\n"
        . "3. Só se perguntarem diretamente se é robô/IA, diga a verdade: quem responde é o seu assistente virtual, e você ({$nome}) acompanha a conversa e entra quando precisar.\n"
        . "4. Se a pessoa pedir para falar com uma pessoa, pedir desconto/condição especial, quiser fechar/contratar, reclamar ou o assunto fugir do que você oferece: acao \"humano\" e diga, em primeira pessoa, que já vai ver isso e retorna em breve.\n"
        . "5. RESPOSTA FRIA, SECA OU NEGATIVA (\"quem é?\", \"não preciso\", \"já tenho\", \"sem interesse\", \"ok\", só um emoji, resposta atravessada): isso NÃO encerra a conversa. Trate como porta entreaberta: (a) acolha sem se justificar, sem pedir desculpa e sem fazer pitch; (b) devolva UMA pergunta leve e curiosa sobre a rotina do negócio, que dê vontade de responder; (c) se ela já tem fornecedor ou solução, mostre curiosidade, pergunte o que funciona e o que falta, sem criticar; (d) se for a pessoa certa mas o momento ruim, deixe um gancho leve (\"sem pressa, se um dia fizer sentido, me chama\") e acao \"nenhuma\". Só use acao \"perdido\" na SEGUNDA recusa clara na mesma conversa, ou se ela disser que fechou o negócio ou já fechou com outra solução e não quer ver nada. Se pedir para não receber mais mensagens, perguntar quem passou o contato dela, ou for hostil: peça desculpas pelo incômodo em uma frase e acao \"nao_contatar\", sem tentar reverter.\n"
        . "5b. NOME: nas duas primeiras respostas, cumprimente de volta e descubra o nome da pessoa de forma natural e curta (\"Com quem eu falo?\"), sem juntar com outra pergunta e sem falar do que você oferece. Se ela ainda não respondeu à sua pergunta inicial, retome-a de leve depois do nome. Depois use o nome dela de vez em quando. Se ela já disse o nome, não pergunte de novo.\n"
        . "5c. ENVOLVER, NÃO DESPEJAR: faça a pessoa falar de si e do negócio com perguntas simples e demonstre interesse de verdade. Informação sobre o que você oferece em DOSE PEQUENA: no máximo UM detalhe curto por mensagem, só a partir da 3ª troca, de preferência o que responde ao que a pessoa disse, sem repetir o que já foi dito. Se não encaixar no assunto, não force: pergunte.\n"
        . ($obj === 'link'
            ? "6. LINK SÓ DEPOIS DE EXPLICAR: antes de mandar o link, a conversa precisa ter pelo menos 3 trocas com a pessoa E você precisa ter mostrado, em 1 ou 2 mensagens, como \"{$camp['produto']}\" resolve a dor DELA (use só o que estiver em SOBRE O QUE VOCÊ OFERECE). Só mande o link antes disso se ela pedir. Ao mandar, apresente como forma de ver na prática, use acao \"link\" e não reenvie a menos que peçam.\n"
            : "6. Se mandar algum link, use acao \"link\".\n")
        . "6b. A CONVERSA NÃO ACABA NO OBJETIVO: depois do link ou da proposta, continue normalmente. Pergunte o que ela achou, tire dúvidas, responda objeções (\"é caro\", \"não tenho tempo\") com curiosidade e sem pitch, e mantenha a conversa andando com mensagens curtas: uma pergunta simples ou uma oferta leve, nunca mais de uma pergunta. Você nunca encerra sozinho: só \"perdido\" e \"nao_contatar\" (regra 5) e \"humano\" (regra 4) param a IA.\n"
        . (trim((string)$camp['ia_proibido']) !== '' ? "7. NUNCA diga ou prometa: " . trim($camp['ia_proibido']) . "\n" : '')
        . "Responda SOMENTE com UM objeto JSON, já revisado contra as regras acima (nunca escreva rascunho, comentário, correção ou um segundo JSON — o campo resposta vai direto para a pessoa): {\"resposta\":\"...\",\"acao\":\"nenhuma|link|humano|perdido|nao_contatar\",\"motivo\":\"\"}\n\n"
        . "SOBRE O QUE VOCÊ OFERECE ({$camp['produto']}):\n" . trim((string)$camp['pitch']) . "\n"
        . (trim((string)$camp['ia_extra']) !== '' ? trim($camp['ia_extra']) . "\n" : '')
        . "\nNEGÓCIO COM QUEM VOCÊ FALA: {$quem}" . ($p['nota'] ? " (nota {$p['nota']} no Google)" : '')
        . ($origem === 'ig_comentario' && !empty($p['obs']) ? "\nO QUE A PESSOA COMENTOU: " . $p['obs'] : '')
        . ($origem === 'anuncio' && !empty($p['obs']) ? "\nDE ONDE ELA VEIO: " . $p['obs'] : '');
    // Pesquisa pública do negócio (Prospecção › Pesquisar): só hipóteses
    if (!empty($p['pesquisa'])) {
        if (!function_exists('ppTextoParaIA')) require_once __DIR__ . '/pesquisa.php';
        $system .= ppTextoParaIA((string)$p['pesquisa']);
    }
    $hist = '';
    foreach ($historico ?? vdHistorico($db, (int)$p['id'], 20) as $m) $hist .= ($m['autor'] === 'cliente' ? 'Contato' : 'Você') . ': ' . $m['texto'] . "\n";
    // Nunca manda texto cru: fora do formato duas vezes → passa para a pessoa
    iaContexto('vendedor', (int)$p['id'], (int)($p['campanha_id'] ?? 0) ?: null);
    $r = iaPerguntarJson($system, "Conversa até agora:\n{$hist}\nResponda à última mensagem do contato.", 500);
    if (!$r['ok']) return ['ok' => false, 'resposta' => '', 'acao' => 'humano', 'motivo' => 'IA indisponível: ' . $r['erro']];
    $o = $r['obj'];
    $acoes = ['nenhuma', 'link', 'humano', 'perdido', 'nao_contatar'];
    return ['ok' => true, 'resposta' => trim((string)$o['resposta']), 'acao' => in_array($o['acao'] ?? '', $acoes, true) ? $o['acao'] : 'nenhuma', 'motivo' => (string)($o['motivo'] ?? '')];
}

/** Aplica a ação no funil. */
function vdAplicarAcao(PDO $db, array $p, string $acao, string $motivo): void {
    $id = (int)$p['id'];
    if ($acao === 'link' && !in_array($p['status'], ['cliente', 'nao_contatar'], true)) proMudarStatus($db, $id, 'interessado');
    if ($acao === 'perdido' && !in_array($p['status'], ['cliente', 'nao_contatar'], true)) proMudarStatus($db, $id, 'perdido');
    if ($acao === 'nao_contatar') proMudarStatus($db, $id, 'nao_contatar');
    if (in_array($acao, ['perdido', 'nao_contatar'], true)) $db->prepare('UPDATE pro_prospects SET ia_ativa = 0 WHERE id = ?')->execute([$id]);
    if ($acao === 'humano') {
        $db->prepare('UPDATE pro_prospects SET ia_pausada = 1 WHERE id = ?')->execute([$id]);
        vdAvisar($db, $p, 'Precisa de você: ' . ($motivo ?: 'pediu para falar com você'));
    }
}

/** Pedido de saída escrito de forma inequívoca (a IA cobre os casos em texto livre). */
function vdEhPedidoDeSaida(string $t): bool {
    $t = trim(proNorm($t), " .!?");
    return in_array($t, ['pare', 'parar', 'stop', 'sair', 'descadastrar', 'remover', 'nao quero receber', 'nao quero mais receber', 'nao quero receber mensagens'], true);
}

// ═══════════════════════════════════════════════════════════════
// Áudio: transcrição (OpenAI) das notas de voz
// ═══════════════════════════════════════════════════════════════

/** Extensão aceita pela API de transcrição a partir do mime ('' = formato não suportado). */
function vdExtAudio(string $mime): string {
    $m = strtolower($mime);
    // formatos aceitos: flac mp3 mp4 mpeg mpga m4a ogg wav webm (amr e aac puro não)
    return strpos($m, 'ogg') !== false ? 'ogg'
         : (strpos($m, 'mpeg') !== false || strpos($m, 'mp3') !== false ? 'mp3'
         : (strpos($m, 'mp4') !== false || strpos($m, 'm4a') !== false ? 'm4a'
         : (strpos($m, 'wav') !== false ? 'wav' : (strpos($m, 'webm') !== false ? 'webm' : ''))));
}

/**
 * Transcreve um áudio (nota de voz) com a API de transcrição da OpenAI.
 * Chave em Configurações → "OpenAI — transcrição de áudio". Sem chave, ou se falhar, quem chama
 * volta ao aviso "só consigo ler texto". ['ok','texto','erro']
 */
function vdTranscrever(string $bytes, string $mime): array {
    $key = trim(getPConfig('openai_api_key'));
    if ($key === '') return ['ok' => false, 'texto' => '', 'erro' => 'sem chave da OpenAI'];
    if ($bytes === '' || strlen($bytes) > 20 * 1024 * 1024) return ['ok' => false, 'texto' => '', 'erro' => 'áudio vazio ou grande demais'];
    $ext = vdExtAudio($mime);
    if ($ext === '') return ['ok' => false, 'texto' => '', 'erro' => 'formato de áudio não suportado (' . $mime . ')'];
    $tmp = @tempnam(sys_get_temp_dir(), 'vdaud');
    if (!$tmp) return ['ok' => false, 'texto' => '', 'erro' => 'sem pasta temporária'];
    $arq = $tmp . '.' . $ext;
    @rename($tmp, $arq);
    if (@file_put_contents($arq, $bytes) === false) { @unlink($arq); @unlink($tmp); return ['ok' => false, 'texto' => '', 'erro' => 'falha ao gravar o áudio']; }
    $campos = [
        'file'   => new CURLFile($arq, 'audio/' . $ext, 'audio.' . $ext),
        'model'  => 'gpt-4o-mini-transcribe',
        'prompt' => 'Conversa de WhatsApp com um empresário sobre criação de site, sistema de gestão, loja virtual, aplicativo, design, orçamento e prazo.',
        'response_format' => 'json',
        'language' => 'pt',
    ];
    $ch = curl_init(defined('PRO_OPENAI_URL') ? PRO_OPENAI_URL : 'https://api.openai.com/v1/audio/transcriptions');   // constante só para teste
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $campos, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    @unlink($arq);
    $j = is_string($res) ? json_decode($res, true) : null;
    if ($code !== 200 || !is_array($j)) {
        return ['ok' => false, 'texto' => '', 'erro' => 'OpenAI HTTP ' . $code . ($err !== '' ? ' ' . $err : '') . (is_array($j) && !empty($j['error']['message']) ? ': ' . mb_substr((string)$j['error']['message'], 0, 150) : '')];
    }
    $t = trim((string)($j['text'] ?? ''));
    return $t === '' ? ['ok' => false, 'texto' => '', 'erro' => 'áudio sem fala reconhecida'] : ['ok' => true, 'texto' => mb_substr($t, 0, 2000), 'erro' => ''];
}

/** Nota de voz do WhatsApp → texto ("🎤 …"), ou '' se não deu — e então $erro diz por quê (vai como nota na conversa). */
function vdAudioWhatsApp(array $msg, string &$erro = ''): string {
    if (trim(getPConfig('openai_api_key')) === '') { $erro = 'falta a chave da OpenAI (Configurações → OpenAI — transcrição de áudio)'; return ''; }
    $id = (string)($msg['audio']['id'] ?? '');
    if ($id === '') { $erro = 'a Meta não mandou o id do áudio'; return ''; }
    if (!function_exists('wa_baixarMidia')) require_once __DIR__ . '/wa_media.php';
    $mid = wa_baixarMidia($id);
    if (!$mid['ok']) { $erro = 'download do áudio na Meta: ' . $mid['erro']; error_log('[vendedor_ia] áudio: ' . $mid['erro']); return ''; }
    $r = vdTranscrever($mid['bytes'], (string)$mid['mime']);
    if (!$r['ok']) { $erro = 'transcrição: ' . $r['erro']; error_log('[vendedor_ia] transcrição: ' . $r['erro']); return ''; }
    return '🎤 ' . $r['texto'];
}

/** Áudio do Direct (URL pública do CDN da Meta) → texto ("🎤 …"), ou ''. */
function vdAudioInstagram(array $m, string &$erro = ''): string {
    if (trim(getPConfig('openai_api_key')) === '') { $erro = 'falta a chave da OpenAI (Configurações → OpenAI — transcrição de áudio)'; return ''; }
    foreach ($m['attachments'] ?? [] as $a) {
        if (($a['type'] ?? '') !== 'audio') continue;
        $url = (string)($a['payload']['url'] ?? '');
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (strncmp($url, 'https://', 8) !== 0 || !preg_match('/(^|\.)(fbcdn\.net|cdninstagram\.com|fbsbx\.com)$/i', $host)) continue;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'OpaProspeccao/1.0']);
        $bytes = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code !== 200 || !is_string($bytes) || $bytes === '' || strlen($bytes) > 8 * 1024 * 1024) { error_log('[vendedor_ia] áudio do Direct: HTTP ' . $code); $erro = 'download do áudio no Instagram: HTTP ' . $code; return ''; }
        $r = vdTranscrever($bytes, $ct !== '' ? $ct : 'audio/mp4');
        if (!$r['ok']) { error_log('[vendedor_ia] transcrição do Direct: ' . $r['erro']); $erro = 'transcrição: ' . $r['erro']; return ''; }
        return '🎤 ' . $r['texto'];
    }
    return '';
}

// ═══════════════════════════════════════════════════════════════
// "Parece gente": lida, "digitando…" e tempo de resposta humano
// ═══════════════════════════════════════════════════════════════

/**
 * Mostra "lida" (tique azul) e "digitando…" para o contato.
 * WhatsApp: Cloud API (status=read + typing_indicator, dura até 25 s ou até a resposta chegar).
 * Instagram: mark_seen + typing_on. Falha aqui nunca derruba a resposta.
 */
function vdDigitando(array $p, string $wamid): void {
    try {
        if (($p['ia_canal'] ?? '') === 'instagram') {
            if (empty($p['ig_id'])) return;
            vdIgApi('POST', 'me/messages', ['recipient' => ['id' => (string)$p['ig_id']], 'sender_action' => 'mark_seen']);
            $r = vdIgApi('POST', 'me/messages', ['recipient' => ['id' => (string)$p['ig_id']], 'sender_action' => 'typing_on']);
            if (!$r['ok']) vdNotaDiagnostico($p, 'instagram', '⚠️ "Digitando…" não apareceu — o Instagram recusou: ' . vdIgErro($r['data']));
            return;
        }
        $cfg = vdConfig(); $token = trim(getPConfig('meta_wpp_token'));
        if ($wamid === '') { vdNotaDiagnostico($p, 'whatsapp', '⚠️ "Digitando…" não enviado — a mensagem chegou sem o id (wamid).'); return; }
        if ($token === '' || $cfg['phone_id'] === '') { vdNotaDiagnostico($p, 'whatsapp', '⚠️ "Digitando…" não enviado — falta o token do WhatsApp ou o número de vendas na configuração.'); return; }
        $ch = curl_init(vdGraphUrl($cfg['phone_id'] . '/messages'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid, 'typing_indicator' => ['type' => 'text']]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        ]);
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $j = is_string($res) ? (json_decode($res, true) ?: []) : [];
        if ($code < 200 || $code >= 300 || empty($j['success'])) {
            $motivo = !empty($j['error']['message']) ? (string)$j['error']['message'] . (!empty($j['error']['code']) ? ' (código ' . $j['error']['code'] . ')' : '') : ($err !== '' ? $err : 'HTTP ' . $code);
            error_log('[vendedor_ia] digitando recusado: ' . $motivo);
            vdNotaDiagnostico($p, 'whatsapp', '⚠️ "Digitando…" não apareceu — a Meta recusou: ' . mb_substr($motivo, 0, 200));
        }
    } catch (Throwable $e) {}
}

function vdTempoTxt(int $seg): string {
    return $seg >= 3600 ? round($seg / 3600, 1) . ' h' : ($seg >= 120 ? round($seg / 60) . ' min' : $seg . ' s');
}

/** Nota do sistema na conversa (diagnóstico), no máximo uma por hora com o mesmo início. */
function vdNotaDiagnostico(array $p, string $canal, string $texto): void {
    try {
        if (empty($p['id'])) return;
        $db = getDB();
        $st = $db->prepare("SELECT COUNT(*) FROM pro_vd_mensagens WHERE prospect_id = ? AND autor = 'sistema' AND texto LIKE ? AND criado_em >= ?");
        $st->execute([(int)$p['id'], mb_substr($texto, 0, 40) . '%', date('Y-m-d H:i:s', time() - 3600)]);
        if ((int)$st->fetchColumn() > 0) return;
        vdGravar($db, (int)$p['id'], 'sistema', $texto, null, $canal);
    } catch (Throwable $e) {}
}

/** Segundos totais que a resposta deve levar (lê + digita): ~0,1 s por caractere + 1 a 3 s, entre 4 e 28 s. */
function vdTempoDigitar(string $resposta, ?int $hesitacaoDecimos = null): float {
    return min(28.0, max(4.0, mb_strlen($resposta) * 0.1 + ($hesitacaoDecimos ?? random_int(10, 30)) / 10));
}

/**
 * Tempo de gente: antes de responder, "lê" (alguns segundos) e depois "digita" na proporção do texto.
 * vdHumanoInicio() roda antes de pensar; vdHumanoFim() roda com a resposta pronta e espera o que faltar.
 */
function vdHumanoInicio(array $p, string $wamid): float {
    sleep(random_int(4, 9));          // viu a mensagem e foi ler
    vdDigitando($p, $wamid);          // lida + "digitando…"
    return microtime(true);
}
function vdHumanoFim(array $p, string $wamid, string $resposta, float $inicioDigitando): void {
    $alvo = vdTempoDigitar($resposta);
    $falta = $alvo - (microtime(true) - $inicioDigitando);
    if ($inicioDigitando > 0 && (microtime(true) - $inicioDigitando) > 18) vdDigitando($p, $wamid);   // a pensada demorou: renova o "digitando…"
    while ($falta > 0.2) {
        $parte = min($falta, 18.0);
        usleep((int)($parte * 1000000));
        $falta -= $parte;
        if ($falta > 0.2) vdDigitando($p, $wamid);   // o indicador some em ~25 s: renova
    }
}

// ═══════════════════════════════════════════════════════════════
// Entrada de mensagens
// ═══════════════════════════════════════════════════════════════

/**
 * Entrada do WhatsApp no número de vendas (chamada por waProcessarItem).
 * Retorna sempre true: o número de vendas só atende contatos marcados (ou vindos de anúncio).
 */
function vdEntrada(PDO $db, string $from, string $tipo, array $msg, string $wamid): bool {
    if ($tipo === 'reaction') return true;   // 👍 em mensagem nossa: não é conversa
    $p = vdProspectPorTelefone($db, $from);
    // Anúncio "clique para o WhatsApp": quem chega por ele entra na Prospecção com a IA ligada
    if (!$p || !(int)$p['ia_ativa']) $p = vdAnuncioContato($db, $from, $msg, $p) ?? $p;
    if (!$p || !(int)$p['ia_ativa']) return true;   // não marcado: o dono responde pelo app
    $texto = $tipo === 'text' ? trim((string)($msg['text']['body'] ?? '')) : '[' . $tipo . ']';
    if ($tipo === 'button') $texto = trim((string)($msg['button']['text'] ?? '[botão]'));
    $ehTexto = in_array($tipo, ['text', 'button'], true);
    $erroAudio = '';
    if ($tipo === 'audio') { $t = vdAudioWhatsApp($msg, $erroAudio); if ($t !== '') { $texto = $t; $ehTexto = true; } }   // nota de voz → texto
    $db->prepare("UPDATE pro_prospects SET ia_canal = 'whatsapp' WHERE id = ?")->execute([(int)$p['id']]);
    $p['ia_canal'] = 'whatsapp';
    $atrasoFila = !empty($msg['timestamp']) ? time() - (int)$msg['timestamp'] : 0;
    vdConversar($db, $p, $ehTexto, $texto, $wamid);
    if ($erroAudio !== '') vdGravar($db, (int)$p['id'], 'sistema', '⚠️ Áudio não transcrito — ' . $erroAudio, null, 'whatsapp');
    if ($atrasoFila > 90) vdNotaDiagnostico($p, 'whatsapp', '⏱️ Mensagem lida pela fila ' . vdTempoTxt($atrasoFila) . ' depois de chegar (cron cron_wa_inbound.php) — com essa demora o "digitando…" pode não aparecer.');
    return true;
}

/** Campanha que atende os contatos vindos de anúncio / chamada direta (null se não houver nenhuma ativa). */
function vdCampanhaAnuncio(PDO $db): ?array {
    $id = vdConfig()['anuncio_campanha'];
    if ($id) { $c = proCampanha($db, $id); if ($c) return $c; }
    return $db->query('SELECT * FROM pro_campanhas WHERE ativa = 1 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
}

/**
 * Contato que chamou o número de vendas sem ter sido abordado. Se veio de anúncio (ou a regra for
 * "todos"), cria/religa o contato com a IA ligada, na campanha de vend_anuncio_campanha.
 * Quem já tem conversa e foi desligado de propósito (IA desligada, perdido, não contatar, cliente) não volta sozinho.
 */
function vdAnuncioContato(PDO $db, string $from, array $msg, ?array $p): ?array {
    $cfg = vdConfig();
    $ref = is_array($msg['referral'] ?? null) ? $msg['referral'] : [];
    $doAnuncio = $ref !== [];
    if ($cfg['anuncio'] === 'nao' || ($cfg['anuncio'] === 'anuncio' && !$doAnuncio)) return null;
    if ($p && in_array($p['status'], ['nao_contatar', 'cliente', 'perdido'], true)) return null;
    if ($p && !empty($p['ia_ultima_em']) && !$doAnuncio) return null;   // já conversou e a IA foi desligada: o dono decide
    $camp = $p ? (proCampanha($db, (int)$p['campanha_id']) ?: vdCampanhaAnuncio($db)) : vdCampanhaAnuncio($db);
    if (!$camp) return null;   // sem campanha não há o que dizer
    $tel = vdTel($from);
    $sobre = $doAnuncio ? trim('Veio do anúncio' . (!empty($ref['source_type']) ? ' (' . $ref['source_type'] . ')' : '')
        . (!empty($ref['headline']) ? ': "' . $ref['headline'] . '"' : '') . (!empty($ref['body']) ? ' — ' . mb_substr((string)$ref['body'], 0, 150) : '')) : 'Chamou no WhatsApp de vendas';
    if (!$p) {
        $nomePerfil = trim((string)($msg['_nome'] ?? ''));
        $db->prepare('INSERT INTO pro_prospects (campanha_id, nome, telefone, status, origem, obs, criado_em, ia_canal, busca) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([(int)$camp['id'], $nomePerfil !== '' ? $nomePerfil : '+' . $tel, $tel, 'novo', $doAnuncio ? 'anuncio' : 'whatsapp', mb_substr($sobre, 0, 250), proAgora(), 'whatsapp',
                      $doAnuncio ? 'Anúncio WhatsApp' : 'WhatsApp de vendas']);
        $st = $db->prepare('SELECT * FROM pro_prospects WHERE id = ?'); $st->execute([(int)$db->lastInsertId()]);
        $p = $st->fetch();
        if (!$p) return null;
    }
    $db->prepare("UPDATE pro_prospects SET ia_ativa = 1, ia_pausada = 0, ia_canal = 'whatsapp', contatado_em = COALESCE(contatado_em, ?), atualizado_em = ? WHERE id = ?")
       ->execute([proAgora(), proAgora(), (int)$p['id']]);
    $p['ia_ativa'] = 1; $p['ia_pausada'] = 0;
    vdGravar($db, (int)$p['id'], 'sistema', '📣 ' . $sobre, null, 'whatsapp');
    vdAvisar($db, $p, $doAnuncio ? 'Novo contato do anúncio — a IA está atendendo.' : 'Novo contato no WhatsApp de vendas — a IA está atendendo.');
    return $p;
}

/**
 * Mensagem do contato chegou (WhatsApp ou Instagram): grava, avança o funil e, se a IA estiver
 * ligada para ele, responde pelo mesmo canal.
 */
function vdConversar(PDO $db, array $p, bool $ehTexto, string $texto, string $wamid): void {
    $cfg = vdConfig();
    $canal = ($p['ia_canal'] ?? '') === 'instagram' ? 'instagram' : 'whatsapp';
    vdGravar($db, (int)$p['id'], 'cliente', $texto, $wamid, $canal);
    if ($p['status'] === 'nao_contatar') return;   // pediu para sair: só registra

    if ($ehTexto && vdEhPedidoDeSaida($texto)) {
        proMudarStatus($db, (int)$p['id'], 'nao_contatar');
        if ($cfg['ativo'] && ($canal === 'whatsapp' || $cfg['ig_ativo'])) {
            $ok = 'Tudo bem, não envio mais mensagens. Desculpe o incômodo!';
            $r = vdEnviarPara($p, $ok);
            if ($r['ok']) vdGravar($db, (int)$p['id'], 'ia', $ok, $r['wamid'], $canal);
        }
        return;
    }
    if (in_array($p['status'], ['enviado', 'novo'], true)) {
        proMudarStatus($db, (int)$p['id'], 'respondeu');
        $p['status'] = 'respondeu';
    }
    if (!$cfg['ativo'] || ($canal === 'instagram' && !$cfg['ig_ativo'])) return;
    $camp = proCampanha($db, (int)$p['campanha_id']);
    if (!$camp) return;
    if ((int)$p['ia_pausada']) {   // conversa com o dono: avisa no máximo 1x/hora
        if (!$p['ia_aviso_em'] || strtotime($p['ia_aviso_em']) < time() - 3600) vdAvisar($db, $p, 'Nova mensagem: ' . mb_substr($texto, 0, 120));
        return;
    }
    if (!$ehTexto) {
        $aviso = 'Por aqui eu só consigo ler texto 🙂 Pode me escrever?';
        if ($cfg['digitando']) { $t0 = vdHumanoInicio($p, $wamid); vdHumanoFim($p, $wamid, $aviso, $t0); }
        $r = vdEnviarPara($p, $aviso);
        if ($r['ok']) vdGravar($db, (int)$p['id'], 'ia', $aviso, $r['wamid'], $canal);
        return;
    }
    // Limite de segurança: 25 respostas da IA por contato por dia
    $st = $db->prepare("SELECT COUNT(*) FROM pro_vd_mensagens WHERE prospect_id = ? AND autor = 'ia' AND criado_em >= ?");
    $st->execute([(int)$p['id'], date('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$st->fetchColumn() >= 25) { vdAplicarAcao($db, $p, 'humano', 'limite de respostas da IA no dia'); return; }

    $t0 = $cfg['digitando'] ? vdHumanoInicio($p, $wamid) : 0.0;   // "lê" e começa a "digitar" enquanto a IA pensa
    $ia = vdPensar($db, $p, $camp);
    if (!$ia['ok'] || $ia['resposta'] === '') { vdAplicarAcao($db, $p, 'humano', $ia['motivo'] ?: 'IA sem resposta'); return; }
    if ($cfg['digitando']) vdHumanoFim($p, $wamid, $ia['resposta'], $t0);
    $r = vdEnviarPara($p, $ia['resposta']);
    vdGravar($db, (int)$p['id'], $r['ok'] ? 'ia' : 'sistema', $r['ok'] ? $ia['resposta'] : '⚠️ Falha ao enviar: ' . $r['erro'] . ' — texto: ' . $ia['resposta'], $r['wamid'] ?? null, $canal);
    vdAplicarAcao($db, $p, $ia['acao'], $ia['motivo']);
}

/**
 * Eco de mensagem enviada PELO APP no número de vendas (coexistência).
 * Antes de o contato responder, é a sua prospecção (só registra).
 * Depois que ele respondeu, é você assumindo: a IA para nessa conversa.
 */
function vdEco(PDO $db, string $para, array $msg, string $wamid): bool {
    $p = vdProspectPorTelefone($db, $para);
    if (!$p) return true;
    $st = $db->prepare('SELECT 1 FROM pro_vd_mensagens WHERE wamid = ? LIMIT 1'); $st->execute([$wamid]);
    if ($st->fetchColumn()) return true;   // mensagem que a própria IA mandou
    $texto = trim((string)($msg['text']['body'] ?? '')) ?: '[' . ($msg['type'] ?? 'mensagem') . ']';
    // A 1ª mensagem já foi gravada ao tocar "Abrir no WhatsApp": só anota o id
    $st = $db->prepare("SELECT id FROM pro_vd_mensagens WHERE prospect_id = ? AND autor = 'voce' AND wamid IS NULL AND COALESCE(canal, 'whatsapp') = 'whatsapp' AND TRIM(texto) = ? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$p['id'], $texto]);
    if ($mid = $st->fetchColumn()) { $db->prepare('UPDATE pro_vd_mensagens SET wamid = ? WHERE id = ?')->execute([$wamid, (int)$mid]); return true; }
    vdGravar($db, (int)$p['id'], 'voce', $texto, $wamid);
    $st = $db->prepare("SELECT 1 FROM pro_vd_mensagens WHERE prospect_id = ? AND autor = 'cliente' AND COALESCE(canal, 'whatsapp') = 'whatsapp' LIMIT 1"); $st->execute([(int)$p['id']]);
    if ($st->fetchColumn()) $db->prepare('UPDATE pro_prospects SET ia_pausada = 1, ia_nao_lidas = 0 WHERE id = ?')->execute([(int)$p['id']]);
    return true;
}

// ═══════════════════════════════════════════════════════════════
// Instagram Direct (API do Instagram com login do Instagram)
// ═══════════════════════════════════════════════════════════════

/** O mid do Instagram pode passar de 160 caracteres (coluna wamid). */
function vdIgMid(string $mid): string {
    return strlen($mid) <= 160 ? $mid : 'igh:' . sha1($mid);
}

/** @ normalizado (minúsculo, sem @ e sem URL). */
function vdIgUser(string $s): string {
    $s = trim($s);
    if (preg_match('~instagram\.com/([A-Za-z0-9_.]+)~i', $s, $m)) $s = $m[1];
    return strtolower(ltrim($s, '@ '));
}

/** Token da conta do Instagram. Vale 60 dias; a cada 30 dias o sistema troca por um novo sozinho (a troca exige token com mais de 24 h). */
function vdIgToken(): string {
    $tok = trim(getPConfig('vend_ig_token'));
    if ($tok === '') return '';
    $em = (int)getPConfig('vend_ig_token_em');
    if ($em && $em < time() - 30 * 86400 && (int)getPConfig('vend_ig_token_tent') < time() - 86400) {
        setPConfig('vend_ig_token_tent', (string)time());
        $ch = curl_init('https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=' . urlencode($tok));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $j = json_decode((string)curl_exec($ch), true) ?: []; curl_close($ch);
        if (!empty($j['access_token'])) { $tok = (string)$j['access_token']; setPConfig('vend_ig_token', $tok); setPConfig('vend_ig_token_em', (string)time()); }
        else error_log('[vendedor] instagram: não renovou o token: ' . ($j['error']['message'] ?? 'sem resposta'));
    }
    return $tok;
}

/** Chamada à API do Instagram (graph.instagram.com; constante PRO_IG_URL só para teste). */
function vdIgApi(string $metodo, string $caminho, array $dados = [], ?string $token = null): array {
    $token = $token ?? vdIgToken();
    if ($token === '') return ['ok' => false, 'data' => ['error' => ['message' => 'Instagram não conectado (falta o token).']]];
    $ver = getPConfig('vend_ig_version') ?: 'v21.0';
    $url = (defined('PRO_IG_URL') ? PRO_IG_URL : 'https://graph.instagram.com') . '/' . $ver . '/' . ltrim($caminho, '/');
    $ch = curl_init();
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']];
    if ($metodo === 'GET') $op[CURLOPT_URL] = $url . ($dados ? '?' . http_build_query($dados) : '');
    else { $op[CURLOPT_URL] = $url; $op[CURLOPT_POST] = true; $op[CURLOPT_POSTFIELDS] = json_encode($dados ?: new stdClass(), JSON_UNESCAPED_UNICODE); }
    curl_setopt_array($ch, $op);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$res, true) ?: [];
    return ['ok' => $code >= 200 && $code < 300 && empty($j['error']), 'data' => $j];
}

function vdIgEnviar(string $igsid, string $texto): array {
    $r = vdIgApi('POST', 'me/messages', ['recipient' => ['id' => $igsid], 'message' => ['text' => mb_substr($texto, 0, 990)]]);
    if ($r['ok'] && !empty($r['data']['message_id'])) return ['ok' => true, 'wamid' => vdIgMid((string)$r['data']['message_id'])];
    return ['ok' => false, 'erro' => vdIgErro($r['data'])];
}

/** Resposta privada a um comentário (a Meta permite uma por comentário, até 7 dias). */
function vdIgResponderComentario(string $commentId, string $texto): array {
    $r = vdIgApi('POST', 'me/messages', ['recipient' => ['comment_id' => $commentId], 'message' => ['text' => mb_substr($texto, 0, 990)]]);
    if ($r['ok'] && !empty($r['data']['message_id'])) return ['ok' => true, 'wamid' => vdIgMid((string)$r['data']['message_id'])];
    return ['ok' => false, 'erro' => vdIgErro($r['data'])];
}

function vdIgErro(array $d): string {
    $m = (string)($d['error']['message'] ?? 'falha no envio');
    $sub = (int)($d['error']['error_subcode'] ?? 0);
    if ($sub === 2534022 || stripos($m, 'outside of allowed window') !== false) return 'passaram 24 h desde a última mensagem do contato — pelas regras do Instagram, só pelo app';
    return $m;
}

/** @ e nome de quem mandou mensagem (só funciona para quem já falou com a conta). */
function vdIgPerfil(string $igsid): array {
    $r = vdIgApi('GET', $igsid, ['fields' => 'username,name']);
    return $r['ok'] ? ['username' => (string)($r['data']['username'] ?? ''), 'name' => (string)($r['data']['name'] ?? '')] : [];
}

/** Contato pelo IGSID; se ainda não ligado, procura pelo @ do perfil. */
function vdProspectPorIg(PDO $db, string $igsid, string $username = ''): ?array {
    $st = $db->prepare('SELECT * FROM pro_prospects WHERE ig_id = ? ORDER BY ia_ativa DESC, id DESC LIMIT 1');
    $st->execute([$igsid]);
    if ($p = $st->fetch()) return $p;
    if ($username === '') $username = (string)(vdIgPerfil($igsid)['username'] ?? '');
    $u = vdIgUser($username);
    if ($u === '') return null;
    $st = $db->prepare("SELECT * FROM pro_prospects WHERE instagram IS NOT NULL AND instagram <> '' AND (ig_id IS NULL OR ig_id = '')
                         AND (LOWER(TRIM(LEADING '@' FROM instagram)) = ? OR LOWER(instagram) LIKE ?)
                       ORDER BY ia_ativa DESC, id DESC LIMIT 1");
    $st->execute([$u, '%instagram.com/' . $u . '%']);
    $p = $st->fetch();
    if (!$p) return null;
    $db->prepare('UPDATE pro_prospects SET ig_id = ? WHERE id = ?')->execute([$igsid, (int)$p['id']]);
    $p['ig_id'] = $igsid;
    return $p;
}

/**
 * Item da fila vindo do Instagram (waProcessarItem → aqui).
 * $conta = ID da conta que recebeu (phone_number_id sem o "ig:").
 */
function vdIgItem(PDO $db, string $conta, string $de, string $tipo, array $ev, string $mid): array {
    $cfg = vdConfig();
    if ($cfg['ig_conta'] === '' || $conta !== $cfg['ig_conta']) return ['status' => 'ignorado', 'msg' => 'outra conta do Instagram'];
    if ($de === $conta) return ['status' => 'ignorado', 'msg' => 'a própria conta'];
    if ($tipo === 'ig_comment') return vdIgComentario($db, $cfg, $ev);
    if ($tipo === 'ig_echo') { vdIgEco($db, $de, $ev, $mid); return ['status' => 'ok', 'msg' => 'instagram: eco']; }

    $m = $ev['message'] ?? [];
    $texto = trim((string)($m['text'] ?? ''));
    if (isset($ev['postback'])) $texto = trim((string)($ev['postback']['title'] ?? $ev['postback']['payload'] ?? ''));
    $ehTexto = $texto !== '';
    if (!$ehTexto) {
        $tipos = array_map(fn($a) => (string)($a['type'] ?? 'anexo'), $m['attachments'] ?? []);
        $texto = '[' . ($tipos ? implode(', ', array_unique($tipos)) : 'mensagem') . ']';
    }
    $p = vdProspectPorIg($db, $de);
    if (!$p || !(int)$p['ia_ativa']) return ['status' => 'ignorado', 'msg' => 'instagram: contato fora da prospecção'];
    $erroAudio = '';
    if (!$ehTexto && strpos($texto, 'audio') !== false) { $t = vdAudioInstagram($m, $erroAudio); if ($t !== '') { $texto = $t; $ehTexto = true; } }   // áudio do Direct → texto
    $db->prepare("UPDATE pro_prospects SET ia_canal = 'instagram', ig_cliente_em = ? WHERE id = ?")->execute([proAgora(), (int)$p['id']]);
    $p['ia_canal'] = 'instagram';
    vdConversar($db, $p, $ehTexto, $texto, $mid);
    if ($erroAudio !== '') vdGravar($db, (int)$p['id'], 'sistema', '⚠️ Áudio não transcrito — ' . $erroAudio, null, 'instagram');
    return ['status' => 'ok', 'msg' => 'vendedor IA (instagram)'];
}

/**
 * Mensagem que a conta mandou (pelo app ou pela API). A da IA já está gravada (ignora). A 1ª mensagem da
 * prospecção, colada no app, liga o IGSID ao contato: pelo @ do perfil quando a API informa, senão pelo texto
 * (só se um único contato recebeu aquele texto). Depois que o contato respondeu, uma mensagem sua pelo app
 * pausa a IA nessa conversa.
 */
function vdIgEco(PDO $db, string $para, array $ev, string $mid): void {
    $st = $db->prepare('SELECT 1 FROM pro_vd_mensagens WHERE wamid = ? LIMIT 1'); $st->execute([$mid]);
    if ($st->fetchColumn()) return;
    $m = $ev['message'] ?? [];
    $texto = trim((string)($m['text'] ?? '')) ?: '[' . (string)($m['attachments'][0]['type'] ?? 'mensagem') . ']';
    $norm = fn($t) => preg_replace('/\s+/u', ' ', trim($t));
    $p = vdProspectPorIg($db, $para);
    // 1ª mensagem preparada na Prospecção (Instagram, ainda sem id)
    $q = $db->prepare("SELECT m.id, m.texto, m.prospect_id FROM pro_vd_mensagens m JOIN pro_prospects p ON p.id = m.prospect_id
                        WHERE m.autor = 'voce' AND m.canal = 'instagram' AND m.wamid IS NULL" . ($p ? ' AND m.prospect_id = ' . (int)$p['id'] : " AND (p.ig_id IS NULL OR p.ig_id = '')") . '
                          AND m.criado_em >= ? ORDER BY m.id DESC LIMIT 100');
    $q->execute([date('Y-m-d H:i:s', strtotime('-7 days'))]);
    $iguais = array_values(array_filter($q->fetchAll(), fn($c) => $norm($c['texto']) === $norm($texto)));
    if ($iguais && ($p || count(array_unique(array_column($iguais, 'prospect_id'))) === 1)) {
        $db->prepare('UPDATE pro_vd_mensagens SET wamid = ? WHERE id = ?')->execute([$mid, (int)$iguais[0]['id']]);
        if (!$p) $db->prepare('UPDATE pro_prospects SET ig_id = ? WHERE id = ?')->execute([$para, (int)$iguais[0]['prospect_id']]);
        return;
    }
    if (!$p) return;   // conversa sua com alguém fora da prospecção (ou texto repetido: a resposta liga pelo @)
    vdGravar($db, (int)$p['id'], 'voce', $texto, $mid, 'instagram');
    $st = $db->prepare("SELECT 1 FROM pro_vd_mensagens WHERE prospect_id = ? AND autor = 'cliente' AND canal = 'instagram' LIMIT 1"); $st->execute([(int)$p['id']]);
    if ($st->fetchColumn()) $db->prepare('UPDATE pro_prospects SET ia_pausada = 1, ia_nao_lidas = 0 WHERE id = ?')->execute([(int)$p['id']]);
}

/**
 * Comentário num post da conta: se ligado, manda uma DM privada e o comentarista entra na Prospecção com a IA
 * ligada (a resposta dele no Direct segue com a IA). Uma DM por pessoa a cada 7 dias.
 */
function vdIgComentario(PDO $db, array $cfg, array $v): array {
    $regra = trim((string)$cfg['ig_coment']);
    if ($regra === '' || !$cfg['ig_ativo']) return ['status' => 'ignorado', 'msg' => 'instagram: comentários desligados'];
    $cid = (string)($v['id'] ?? ''); $de = (string)($v['from']['id'] ?? ''); $user = (string)($v['from']['username'] ?? '');
    $texto = trim((string)($v['text'] ?? ''));
    if ($cid === '' || $de === '') return ['status' => 'ignorado', 'msg' => 'instagram: comentário sem autor'];
    if ($cfg['ig_usuario'] !== '' && vdIgUser($user) === vdIgUser($cfg['ig_usuario'])) return ['status' => 'ignorado', 'msg' => 'comentário da própria conta'];
    if ($regra !== '*' && mb_stripos($texto, $regra) === false) return ['status' => 'ignorado', 'msg' => 'instagram: comentário sem a palavra-chave'];
    $camp = vdCampanhaAnuncio($db);
    if (!$camp) return ['status' => 'ignorado', 'msg' => 'instagram: nenhuma campanha ativa para atender'];

    $p = vdProspectPorIg($db, $de, $user);
    if ($p) {
        if (in_array($p['status'], ['nao_contatar', 'perdido', 'cliente'], true)) return ['status' => 'ignorado', 'msg' => 'instagram: contato encerrado'];
        $st = $db->prepare('SELECT 1 FROM pro_vd_mensagens WHERE prospect_id = ? AND criado_em >= ? LIMIT 1');
        $st->execute([(int)$p['id'], date('Y-m-d H:i:s', strtotime('-7 days'))]);
        if ($st->fetchColumn()) return ['status' => 'ignorado', 'msg' => 'instagram: já conversou nos últimos 7 dias'];
    } else {
        $ig = $user !== '' ? '@' . vdIgUser($user) : null;
        $db->prepare('INSERT INTO pro_prospects (campanha_id, nome, instagram, status, origem, obs, criado_em, ig_id, ia_canal, busca) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([(int)$camp['id'], $ig ?? 'Comentário no Instagram', $ig, 'novo', 'ig_comentario', mb_substr($texto, 0, 250), proAgora(), $de, 'instagram', 'Comentários no Instagram']);
        $st = $db->prepare('SELECT * FROM pro_prospects WHERE id = ?'); $st->execute([(int)$db->lastInsertId()]);
        $p = $st->fetch();
    }
    $msg = trim((string)$cfg['ig_coment_msg']);
    $r = vdIgResponderComentario($cid, $msg);
    if (!$r['ok']) {
        vdGravar($db, (int)$p['id'], 'sistema', '⚠️ Comentou "' . mb_substr($texto, 0, 120) . '", mas o Instagram recusou a DM: ' . $r['erro'], null, 'instagram');
        return ['status' => 'erro', 'msg' => 'instagram: ' . $r['erro']];
    }
    $db->prepare("UPDATE pro_prospects SET ia_ativa = 1, ia_pausada = 0, ia_canal = 'instagram', ig_id = ?, status = CASE WHEN status = 'novo' THEN 'enviado' ELSE status END, contatado_em = COALESCE(contatado_em, ?), atualizado_em = ? WHERE id = ?")
       ->execute([$de, proAgora(), proAgora(), (int)$p['id']]);
    vdGravar($db, (int)$p['id'], 'sistema', '💬 Comentou no post: "' . mb_substr($texto, 0, 200) . '"', null, 'instagram');
    vdGravar($db, (int)$p['id'], 'ia', $msg, $r['wamid'], 'instagram');
    vdAvisar($db, $p, 'Comentou no Instagram e recebeu a DM automática.');
    return ['status' => 'ok', 'msg' => 'instagram: DM do comentário enviada'];
}

/**
 * Um item da fila (chamado por cron_wa_inbound.php).
 * Retorna ['status'=>'ok'|'ignorado'|'erro', 'msg'=>string]. Nunca lança.
 */
function waProcessarItem(PDO $db, array $item): array {
    try {
        $msg    = json_decode((string)$item['payload'], true) ?: [];
        $from   = (string)$item['from_e164'];
        $tipo   = (string)$item['tipo'];
        $wamid  = (string)$item['wamid'];
        $phoneId = (string)$item['phone_number_id'];
        // Instagram: ig_webhook.php grava phone_number_id = "ig:<conta>"
        if (strncmp($phoneId, 'ig:', 3) === 0) return vdIgItem($db, substr($phoneId, 3), $from, $tipo, $msg, $wamid);
        $cfg = vdConfig();
        if ($cfg['phone_id'] === '' || $phoneId !== $cfg['phone_id']) return ['status' => 'ignorado', 'msg' => 'outro número'];
        if ($tipo === 'echo') vdEco($db, $from, $msg, $wamid);
        else vdEntrada($db, $from, $tipo, $msg, $wamid);
        return ['status' => 'ok', 'msg' => 'vendedor IA'];
    } catch (Throwable $e) {
        return ['status' => 'erro', 'msg' => $e->getMessage()];
    }
}

}
