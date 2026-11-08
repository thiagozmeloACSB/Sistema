<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════
// Vendedor IA (opcional): conversas de venda no WhatsApp.
// A 1ª mensagem sai pela Prospecção; quando o contato responde, a IA continua; você assume quando quiser.
// Lógica em includes/vendedor_ia.php. Portado de pages_super/vendedor_ia.php do DojoSystem.
// ═══════════════════════════════════════════════════════════
require_once __DIR__ . '/../includes/vendedor_ia.php';
require_once __DIR__ . '/../includes/wa_client.php';

$pid = (int)($_GET['id'] ?? 0);
$volta = proUrl('vendedor_ia', $pid ? ['id' => $pid] : []);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    if (!csrfCheck($_POST['csrf'] ?? '')) {
        if ($acao === 'simular') proJson(['ok' => false, 'erro' => 'Sessão expirada. Recarregue.']);
        flash('Sessão expirada.', 'error'); redirect($volta);
    }

    if ($acao === 'config') {
        setPConfig('vend_phone_id', preg_replace('/\D/', '', (string)($_POST['phone_id'] ?? '')));
        setPConfig('vend_ativo', isset($_POST['ativo']) ? '1' : '0');
        setPConfig('vend_digitando', isset($_POST['digitando']) ? '1' : '0');
        setPConfig('vend_anuncio', in_array($_POST['anuncio'] ?? '', ['anuncio', 'todos', 'nao'], true) ? $_POST['anuncio'] : 'anuncio');
        $ac = (int)($_POST['anuncio_campanha'] ?? 0);
        setPConfig('vend_anuncio_campanha', $ac && proCampanha($db, $ac) ? (string)$ac : '');
        flash('Configuração do Vendedor IA salva.'); redirect($volta);
    }

    // ── Instagram Direct: conectar a conta (token), regras e comentários ──
    if ($acao === 'ig_config') {
        $tok = trim((string)($_POST['ig_token'] ?? ''));
        if ($tok !== '') {
            $r = vdIgApi('GET', 'me', ['fields' => 'user_id,username,account_type'], $tok);
            if (!$r['ok'] || empty($r['data']['user_id'])) { flash('O Instagram não aceitou esse token: ' . ($r['data']['error']['message'] ?? 'sem resposta') . '. Gere de novo no app da Meta → Instagram → Configuração da API com login do Instagram → Gerar token.', 'error'); redirect($volta); }
            setPConfig('vend_ig_token', $tok); setPConfig('vend_ig_token_em', (string)time());
            setPConfig('vend_ig_conta', (string)$r['data']['user_id']); setPConfig('vend_ig_usuario', '@' . (string)($r['data']['username'] ?? ''));
            // Liga os avisos desta conta para o app (sem isso a Meta não manda as mensagens)
            $sub = vdIgApi('POST', 'me/subscribed_apps?subscribed_fields=messages,messaging_postbacks,comments', [], $tok);
            if (!$sub['ok']) $_SESSION['vd_ig_aviso'] = 'Conta conectada, mas não consegui ligar os avisos: ' . ($sub['data']['error']['message'] ?? 'erro') . '. Confira as permissões do token (mensagens e comentários).';
        }
        $sec = trim((string)($_POST['ig_secret'] ?? ''));
        if ($sec !== '') setPConfig('vend_ig_app_secret', $sec);
        setPConfig('vend_ig_ativo', isset($_POST['ig_ativo']) ? '1' : '0');
        $modo = (string)($_POST['ig_coment_modo'] ?? '');
        $palavra = mb_substr(trim((string)($_POST['ig_coment_palavra'] ?? '')), 0, 60);
        setPConfig('vend_ig_coment', $modo === 'todos' ? '*' : ($modo === 'palavra' && $palavra !== '' ? $palavra : ''));
        $cm = mb_substr(trim(str_replace("\r", '', (string)($_POST['ig_coment_msg'] ?? ''))), 0, 900);
        if ($cm !== '') setPConfig('vend_ig_coment_msg', $cm);
        flash($tok !== '' ? 'Instagram conectado: ' . getPConfig('vend_ig_usuario') . '.' : 'Configuração do Instagram salva.'); redirect($volta);
    }
    if ($acao === 'ig_desconectar') {
        foreach (['token', 'token_em', 'conta', 'usuario', 'ativo'] as $k) setPConfig('vend_ig_' . $k, '');
        flash('Instagram desconectado. As conversas continuam aqui; a IA para de responder no Direct.'); redirect($volta);
    }

    // ── Conectar o número de vendas: acha a conta e assina os webhooks ──
    if ($acao === 'buscar_numeros' || $acao === 'usar_numero') {
        $tok = trim(getPConfig('meta_wpp_token'));
        if ($tok === '') { flash('Falta o token da Meta (Configurações → WhatsApp).', 'error'); redirect($volta); }
        $semAcesso = 'O token não enxerga essa conta. Na Meta: Configurações → Contas do WhatsApp → clique no número → Atribuir pessoas → escolha o usuário do sistema (o mesmo do token) → Controle total. Depois tente de novo.';
        if ($acao === 'buscar_numeros') {
            $wabas = array_filter([preg_replace('/\D/', '', (string)($_POST['waba'] ?? ''))]);
            if (!$wabas) {   // descobre as contas que o token alcança
                $app = trim(getPConfig('wa_app_id')) && trim(getPConfig('wa_app_secret'));
                $dbg = waRequest('GET', 'debug_token', ['input_token' => $tok, 'access_token' => $app ? getPConfig('wa_app_id') . '|' . getPConfig('wa_app_secret') : $tok]);
                foreach (($dbg['data']['data']['granular_scopes'] ?? []) as $gs)
                    if (in_array($gs['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true))
                        foreach (($gs['target_ids'] ?? []) as $t) $wabas[] = (string)$t;
                $wabas = array_values(array_unique($wabas));
                if (!$wabas) { flash('Não consegui descobrir as contas sozinho. Cole o ID da conta do WhatsApp (WABA) no campo e tente de novo.', 'error'); redirect($volta); }
            }
            $achados = []; $erros = [];
            foreach ($wabas as $w) {
                $r = waListarNumeros($w, $tok);
                if (!$r['ok']) { $erros[] = $w . ': ' . ($r['data']['error']['message'] ?? 'erro'); continue; }
                foreach (($r['data']['data'] ?? []) as $n) $achados[] = ['waba' => $w, 'id' => (string)$n['id'], 'numero' => (string)($n['display_phone_number'] ?? ''), 'nome' => (string)($n['verified_name'] ?? '')];
            }
            if (!$achados) { flash(($erros ? 'A Meta respondeu: ' . implode(' · ', $erros) . '. ' : '') . $semAcesso, 'error'); redirect($volta); }
            $_SESSION['vd_numeros'] = $achados;
            flash('Encontrei ' . count($achados) . ' número(s). Escolha abaixo qual é o de vendas.'); redirect($volta);
        }
        $w = preg_replace('/\D/', '', (string)($_POST['waba'] ?? '')); $id = preg_replace('/\D/', '', (string)($_POST['pid'] ?? ''));
        if ($w === '' || $id === '') { flash('Escolha um número.', 'error'); redirect($volta); }
        $r = waAssinarWebhook($w, $tok);
        if (!$r['ok']) { flash('Não consegui ligar os avisos (webhook) dessa conta: ' . ($r['data']['error']['message'] ?? 'erro') . '. ' . $semAcesso, 'error'); redirect($volta); }
        setPConfig('vend_phone_id', $id); setPConfig('vend_waba_id', $w);
        setPConfig('vend_numero', mb_substr(trim((string)($_POST['numero'] ?? '')), 0, 40));
        unset($_SESSION['vd_numeros']);
        flash('Número de vendas conectado: as mensagens dele já chegam ao sistema. Falta conferir no app da Meta o campo de webhook smb_message_echoes.'); redirect($volta);
    }

    // ── Pergunta à Meta como está o número de vendas (API/coexistência, webhook) ──
    if ($acao === 'verificar_numero') {
        $tok = trim(getPConfig('meta_wpp_token')); $vc = vdConfig(); $w = getPConfig('vend_waba_id');
        $out = [];
        if ($tok === '' || $vc['phone_id'] === '') { flash('Conecte o número de vendas primeiro.', 'error'); redirect($volta); }
        $r = waRequest('GET', $vc['phone_id'], ['fields' => 'display_phone_number,verified_name,platform_type,status,code_verification_status,quality_rating,messaging_limit_tier,is_on_biz_app,account_mode,name_status'], $tok);
        if (!$r['ok']) $r = waRequest('GET', $vc['phone_id'], ['fields' => 'display_phone_number,verified_name,platform_type,status,code_verification_status,quality_rating,account_mode'], $tok);
        $out['numero'] = $r['ok'] ? $r['data'] : ['erro' => $r['data']['error']['message'] ?? 'erro'];
        if ($w !== '') {
            $r = waRequest('GET', $w . '/subscribed_apps', [], $tok);
            $out['apps'] = $r['ok'] ? array_map(fn($a) => ($a['whatsapp_business_api_data']['name'] ?? $a['name'] ?? '?') . ' (' . ($a['whatsapp_business_api_data']['id'] ?? $a['id'] ?? '?') . ')', $r['data']['data'] ?? []) : ['erro: ' . ($r['data']['error']['message'] ?? 'erro')];
        }
        $out['em'] = date('d/m H:i');
        $_SESSION['vd_verif'] = $out;
        redirect($volta . '#verif');
    }

    // ── Simulação (nada é enviado) ──
    if ($acao === 'simular') {
        if (trim(getPConfig('claude_api_key')) === '') proJson(['ok' => false, 'erro' => 'Falta a chave do Claude (Configurações).']);
        $camp = proCampanha($db, (int)($_POST['campanha'] ?? 0));
        if (!$camp) proJson(['ok' => false, 'erro' => 'Escolha uma campanha.']);
        $sim = $_SESSION['vd_sim'] ?? [];
        if (!empty($_POST['reiniciar'])) { unset($_SESSION['vd_sim']); proJson(['ok' => true]); }
        if (!$sim) $sim[] = ['autor' => 'voce', 'texto' => trim((string)($_POST['primeira'] ?? '')) ?: 'Oi, tudo bem? Vi o negócio de vocês no Google e queria te apresentar ' . $camp['produto'] . '. Posso te mostrar como funciona?'];
        $sim[] = ['autor' => 'cliente', 'texto' => mb_substr(trim((string)($_POST['texto'] ?? '')), 0, 1000)];
        $fake = ['id' => 0, 'nome' => 'Negócio Exemplo', 'segmento' => $camp['ramo'], 'cidade' => 'Florianópolis', 'uf' => 'SC', 'nota' => null];
        $r = vdPensar($db, $fake, $camp, $sim);
        if ($r['ok']) $sim[] = ['autor' => 'ia', 'texto' => $r['resposta']];
        $_SESSION['vd_sim'] = array_slice($sim, -30);
        proJson(['ok' => $r['ok'], 'resposta' => $r['resposta'], 'acao' => $r['acao'], 'motivo' => $r['motivo'], 'erro' => $r['ok'] ? '' : $r['motivo']]);
    }

    $st = $db->prepare('SELECT * FROM pro_prospects WHERE id = ?'); $st->execute([(int)($_POST['id'] ?? 0)]);
    $pp = $st->fetch();
    if ($pp && $acao === 'assumir')  { $db->prepare('UPDATE pro_prospects SET ia_pausada = 1 WHERE id = ?')->execute([(int)$pp['id']]); flash('Você assumiu a conversa. A IA não responde mais este contato até você devolver.'); redirect($volta); }
    if ($pp && $acao === 'devolver') {
        if ($pp['status'] === 'nao_contatar') { flash('Este contato pediu para não ser contatado.', 'error'); redirect($volta); }
        $db->prepare('UPDATE pro_prospects SET ia_pausada = 0, ia_ativa = 1 WHERE id = ?')->execute([(int)$pp['id']]); flash('A IA volta a responder este contato na próxima mensagem dele.'); redirect($volta);
    }
    if ($pp && $acao === 'zerar') {
        $db->prepare('DELETE FROM pro_vd_mensagens WHERE prospect_id = ?')->execute([(int)$pp['id']]);
        $db->prepare("UPDATE pro_prospects SET status = 'novo', mensagem = NULL, contatado_em = NULL, ia_ativa = 0, ia_pausada = 0, ia_ultima_em = NULL, ia_aviso_em = NULL, ia_nao_lidas = 0, atualizado_em = ? WHERE id = ?")->execute([proAgora(), (int)$pp['id']]);
        if ($pp['status'] === 'nao_contatar') proDesbloquear($db, (string)$pp['telefone']);
        flash('Conversa apagada. O contato voltou para 🆕 Novo na Prospecção — escreva e envie a 1ª mensagem de novo.');
        redirect(proUrl('prospeccao', ['c' => (int)$pp['campanha_id'], 'st' => 'novo']));
    }
    if ($pp && $acao === 'desligar') { $db->prepare('UPDATE pro_prospects SET ia_ativa = 0 WHERE id = ?')->execute([(int)$pp['id']]); flash('IA desligada para este contato.'); redirect($volta); }
    if ($pp && $acao === 'responder') {
        $txt = trim((string)($_POST['texto'] ?? ''));
        if ($txt === '') { flash('Escreva a mensagem.', 'error'); redirect($volta); }
        if ($pp['status'] === 'nao_contatar') { flash('Este contato pediu para não ser contatado.', 'error'); redirect($volta); }
        $canalR = $pp['ia_canal'] === 'instagram' ? 'instagram' : 'whatsapp';
        $nomeC = $canalR === 'instagram' ? 'Instagram' : 'WhatsApp';
        if (vdJanelaAte($db, (int)$pp['id'], $canalR) === null) {
            $q = $db->prepare("SELECT COUNT(*) FROM pro_vd_mensagens WHERE prospect_id = ? AND autor = 'cliente' AND COALESCE(canal, 'whatsapp') = ?"); $q->execute([(int)$pp['id'], $canalR]);
            flash((int)$q->fetchColumn() === 0
                ? "O contato ainda não respondeu (ou a resposta não chegou ao sistema — veja o 📡 Diagnóstico abaixo). Pelas regras do {$nomeC}, enquanto ele não responder, mensagens só pelo app do celular."
                : "Passaram 24 h desde a última mensagem do contato: pelas regras do {$nomeC}, responda pelo app.", 'error');
            redirect($volta);
        }
        $r = vdEnviarPara($pp, $txt);
        if (!$r['ok']) { flash("O {$nomeC} recusou: " . $r['erro'], 'error'); redirect($volta); }
        vdGravar($db, (int)$pp['id'], 'voce', $txt, $r['wamid'], $canalR);
        $db->prepare('UPDATE pro_prospects SET ia_pausada = 1, ia_nao_lidas = 0 WHERE id = ?')->execute([(int)$pp['id']]);
        flash('Enviada. A IA fica pausada nesta conversa até você devolver.'); redirect($volta);
    }
    redirect($volta);
}

$cfg = vdConfig();
$temClaude = trim(getPConfig('claude_api_key')) !== '';
$temToken  = trim(getPConfig('meta_wpp_token')) !== '';
$lista = $db->query("SELECT p.id, p.nome, p.cidade, p.uf, p.telefone, p.status, p.ia_canal, p.ia_ativa, p.ia_pausada, p.ia_nao_lidas, p.ia_ultima_em, c.nome AS campanha,
                            (SELECT texto FROM pro_vd_mensagens m WHERE m.prospect_id = p.id ORDER BY m.id DESC LIMIT 1) AS ultima,
                            (SELECT COUNT(*) FROM pro_vd_mensagens m WHERE m.prospect_id = p.id AND m.autor = 'cliente') AS respostas
                       FROM pro_prospects p LEFT JOIN pro_campanhas c ON c.id = p.campanha_id WHERE p.ia_ultima_em IS NOT NULL
                      ORDER BY (p.ia_pausada = 1 AND p.ia_nao_lidas > 0) DESC, p.ia_ultima_em DESC LIMIT 150")->fetchAll();
$aberta = null; $msgs = [];
if ($pid) {
    $st = $db->prepare('SELECT * FROM pro_prospects WHERE id = ?'); $st->execute([$pid]);
    $aberta = $st->fetch() ?: null;
    if ($aberta) { $msgs = vdHistorico($db, $pid, 300); $db->prepare('UPDATE pro_prospects SET ia_nao_lidas = 0 WHERE id = ?')->execute([$pid]); }
}
$conv = (int)$db->query('SELECT COUNT(*) FROM pro_prospects WHERE ia_ativa = 1')->fetchColumn();
$resp = (int)$db->query("SELECT COUNT(DISTINCT prospect_id) FROM pro_vd_mensagens WHERE autor = 'cliente'")->fetchColumn();
$inter = (int)$db->query("SELECT COUNT(*) FROM pro_prospects WHERE status IN ('interessado','cliente') AND ia_ultima_em IS NOT NULL")->fetchColumn();
$icoCanal = fn($c) => $c === 'instagram' ? '📸' : '💬';
$autores = ['cliente' => 'Contato', 'ia' => '🤖 IA', 'voce' => '🙋 Você', 'sistema' => '⚠️ Sistema'];
$sim = $_SESSION['vd_sim'] ?? [];
$camps = $db->query('SELECT id, nome FROM pro_campanhas ORDER BY ativa DESC, id DESC')->fetchAll();

proHeader('Vendedor IA', 'vendedor_ia');
?>
<div class="kpis">
  <div class="kpi"><b><?= $cfg['ativo'] ? '🟢' : '⚪' ?></b><span><?= $cfg['ativo'] ? 'IA respondendo' : 'IA desligada' ?></span></div>
  <div class="kpi"><b><?= $conv ?></b><span>Contatos com IA ligada</span></div>
  <div class="kpi"><b><?= $resp ?></b><span>Responderam</span></div>
  <div class="kpi"><b class="ok"><?= $inter ?></b><span>Interessados / clientes</span></div>
</div>

<div class="vd-grid">
  <div class="painel" style="padding:0;overflow:hidden;">
    <div class="painel-h"><div class="painel-t">💬 Conversas</div></div>
    <div class="vd-lista">
      <?php if (!$lista): ?><div class="dica" style="padding:14px;">Nenhuma conversa ainda. Envie a primeira mensagem pela <a href="<?= e(proUrl('prospeccao')) ?>">Prospecção</a> (botão "Abrir no WhatsApp").</div><?php endif; ?>
      <?php foreach ($lista as $c): ?>
        <a href="<?= e(proUrl('vendedor_ia', ['id' => $c['id']])) ?>" class="<?= $aberta && (int)$aberta['id'] === (int)$c['id'] ? 'on' : '' ?>">
          <span title="<?= $c['ia_canal'] === 'instagram' ? 'Instagram' : 'WhatsApp' ?>"><?= $icoCanal($c['ia_canal']) ?></span> <b style="font-size:.88rem;"><?= e($c['nome']) ?></b> <?= (int)$c['ia_nao_lidas'] ? '<span class="tag n">' . (int)$c['ia_nao_lidas'] . '</span>' : '' ?><br>
          <span class="tag"><?= e(PRO_STATUS[$c['status']] ?? $c['status']) ?></span><?= !(int)$c['ia_ativa'] ? '<span class="tag">IA desligada</span>' : ((int)$c['ia_pausada'] ? '<span class="tag h">🙋 com você</span>' : '<span class="tag">🤖 IA</span>') ?><?= (int)$c['respostas'] ? '' : '<span class="tag">aguardando resposta</span>' ?>
          <div class="dica" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['campanha']) ?> · <?= e(mb_substr((string)$c['ultima'], 0, 70)) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
    <?php if ($aberta): ?>
    <div class="painel"><div class="painel-b">
      <div class="linha" style="justify-content:space-between;margin-bottom:10px;">
        <div><b><?= e($aberta['nome']) ?></b> <span class="dica"><?= e(trim(($aberta['cidade'] ?? '') . ($aberta['uf'] ? '/' . $aberta['uf'] : ''))) ?>
          <?php $abIg = $aberta['ia_canal'] === 'instagram'; $janela = vdJanelaAte($db, (int)$aberta['id'], $abIg ? 'instagram' : 'whatsapp'); ?>
          <?php if ($abIg): ?> · 📸 <a href="https://ig.me/m/<?= e(vdIgUser((string)$aberta['instagram'])) ?>" target="_blank" rel="noopener">abrir no Instagram</a>
          <?php else: ?> · <a href="https://wa.me/<?= e($aberta['telefone']) ?>" target="_blank" rel="noopener">abrir no WhatsApp</a><?php endif; ?>
          · <?= $janela ? '<span class="ok">janela aberta até ' . e(date('d/m H:i', $janela)) . '</span>' : '<span class="x">janela de 24 h fechada: só pelo app</span>' ?></span></div>
        <form method="POST" class="linha"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$aberta['id'] ?>">
          <?php if ((int)$aberta['ia_ativa'] && !(int)$aberta['ia_pausada']): ?><button class="btn btn-sm" name="acao" value="assumir">🙋 Assumir</button>
          <?php else: ?><button class="btn btn-sm" name="acao" value="devolver">🤖 Devolver para a IA</button><?php endif; ?>
          <?php if ((int)$aberta['ia_ativa']): ?><button class="btn btn-sm" name="acao" value="desligar" onclick="return confirm('Desligar a IA para este contato?');">Desligar IA</button><?php endif; ?>
          <button class="btn btn-sm" name="acao" value="zerar" onclick="return confirm('Apagar esta conversa e recomeçar do zero? O contato volta para Novo na Prospecção.');">🔄 Recomeçar do zero</button>
        </form>
      </div>
      <div class="vd-thread" id="vdThread">
        <?php foreach ($msgs as $m): ?><div class="vd-m <?= e($m['autor']) ?>"><?= e($m['texto']) ?><small><?= $autores[$m['autor']] ?? '' ?> · <?= ($m['canal'] ?? '') === 'instagram' ? '📸 ' : '' ?><?= date('d/m H:i', strtotime($m['criado_em'])) ?></small></div><?php endforeach; ?>
      </div>
      <form method="POST" class="linha" style="margin-top:10px;flex-wrap:nowrap;">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="responder"><input type="hidden" name="id" value="<?= (int)$aberta['id'] ?>">
        <textarea name="texto" class="input" rows="2" placeholder="Responder por aqui (ou pelo app). Só funciona com a janela de 24 h aberta. A IA pausa nesta conversa."></textarea>
        <button class="btn btn-p">Enviar</button>
      </form>
    </div></div>
    <script>(function(){var t=document.getElementById('vdThread');if(t)t.scrollTop=t.scrollHeight;})();</script>
    <?php endif; ?>

    <div class="painel">
      <div class="painel-h"><div class="painel-t">🧪 Simular uma conversa</div></div>
      <div class="painel-b">
        <div class="dica" style="margin-bottom:8px;">Responda como se fosse o contato e veja o que a IA diria com as regras da campanha escolhida. Nada é enviado.</div>
        <?php if (!$camps): ?><div class="dica">Crie uma campanha primeiro.</div><?php else: ?>
        <div class="campo"><select id="vdCamp" class="input" style="max-width:340px;"><?php foreach ($camps as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="vd-thread" id="vdSim" style="max-height:340px;margin-bottom:8px;">
          <?php foreach ($sim as $m): ?><div class="vd-m <?= e($m['autor']) ?>"><?= e($m['texto']) ?></div><?php endforeach; ?>
        </div>
        <?php if (!$sim): ?><textarea id="vdPrim" class="input" rows="2" placeholder="Opcional: a 1ª mensagem que você mandou (se vazio, uso uma de exemplo)" style="margin-bottom:6px;"></textarea><?php endif; ?>
        <form id="vdSimF" class="linha" style="flex-wrap:nowrap;"><input type="text" id="vdSimT" class="input" placeholder="Ex.: Oi, quanto custa?"><button class="btn btn-p btn-sm">Enviar</button><button type="button" class="btn btn-sm" id="vdSimR">Recomeçar</button></form>
        <?php if (!$temClaude): ?><div class="dica x" style="margin-top:6px;">Falta a chave do Claude em Configurações.</div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php
    // ── Diagnóstico do recebimento (webhook → pro_wa_fila → cron) ──
    $dg = ['ultimas' => [], 'pend' => 0, 'ult' => null, 'ultV' => null, 'erroTab' => false];
    try {
        $dg['ultimas'] = $db->query('SELECT recebido_em, phone_number_id, from_e164, tipo, status, erro FROM pro_wa_fila ORDER BY id DESC LIMIT 10')->fetchAll();
        $q = $db->prepare("SELECT COUNT(*) FROM pro_wa_fila WHERE status = 'pendente' AND recebido_em < ?"); $q->execute([date('Y-m-d H:i:s', strtotime('-2 minutes'))]);
        $dg['pend'] = (int)$q->fetchColumn();
        $dg['ult']  = $db->query('SELECT MAX(recebido_em) FROM pro_wa_fila')->fetchColumn() ?: null;
        if ($cfg['phone_id'] !== '') { $q = $db->prepare('SELECT MAX(recebido_em) FROM pro_wa_fila WHERE phone_number_id = ?'); $q->execute([$cfg['phone_id']]); $dg['ultV'] = $q->fetchColumn() ?: null; }
    } catch (Throwable $e) { $dg['erroTab'] = true; }
    $ass = explode('|', getPConfig('wa_webhook_assinatura'));
    $bloq = ($ass[0] ?? '') === 'falhou' && getPConfig('wa_webhook_exigir') === '1';
    $logW = [];
    $lf = PRO_ROOT . '/logs/wa_inbound.log';
    if (is_file($lf) && ($sz = filesize($lf))) { $fh = fopen($lf, 'r'); fseek($fh, max(0, $sz - 6000)); $logW = array_slice(array_filter(explode("\n", (string)fread($fh, 6000)), fn($l) => str_contains($l, 'WEBHOOK') || str_contains($l, 'ERRO')), -8); fclose($fh); }
    $problema = $dg['erroTab'] || !$dg['ultV'] || $dg['pend'] > 0 || $bloq;
    $rotulo = fn($id) => $id === $cfg['phone_id'] ? '🤖 vendas' : '📱 ' . $id;
    $webhookUrl = proBaseUrl() . '/wa_webhook.php';
    ?>
    <details class="painel" style="margin-bottom:16px;" <?= $problema && $cfg['phone_id'] ? 'open' : '' ?>>
      <summary class="painel-h"><div class="painel-t">📡 Diagnóstico do recebimento</div></summary>
      <div class="painel-b dica" style="line-height:1.8;">
        <?php if ($dg['erroTab']): ?><span class="x">✗</span> A fila de mensagens recebidas não pôde ser lida.<br>
        <?php else: ?>
          <?= $dg['ult'] ? '<span class="ok">✓</span> Última mensagem recebida (qualquer número): <b>' . e(date('d/m H:i', strtotime($dg['ult']))) . '</b>' : '<span class="x">✗</span> <b>Nenhuma mensagem chegou ao webhook ainda.</b> Confira na Meta: URL de callback <code>' . e($webhookUrl) . '</code> verificada e os campos <b>messages</b> e <b>smb_message_echoes</b> assinados.' ?><br>
          <?php if ($cfg['phone_id']): ?><?= $dg['ultV'] ? '<span class="ok">✓</span> Última no número de vendas: <b>' . e(date('d/m H:i', strtotime($dg['ultV']))) . '</b>' : '<span class="x">✗</span> <b>Nada chegou ainda do número de vendas.</b> Clique de novo em "Buscar meus números" → "Usar este" (liga a conta ao webhook) e mande uma mensagem de teste para o número de vendas.' ?><br><?php endif; ?>
          <?= $dg['pend'] ? '<span class="x">✗</span> <b>' . $dg['pend'] . ' mensagem(ns) parada(s) na fila há mais de 2 min: o cron <code>cron_wa_inbound.php</code> não está rodando.</b> Hostinger → Cron Jobs → a cada minuto (o comando está em Configurações).' : '<span class="ok">✓</span> Nenhuma mensagem parada na fila' ?><br>
        <?php endif; ?>
        <?php $up = explode('|', getPConfig('wa_webhook_ultimo_post')); ?>
        <?= $up[0] !== '' ? '<span class="ok">✓</span> Último aviso da Meta (qualquer tipo): <b>' . e(date('d/m H:i', strtotime($up[0]))) . '</b> — campo <b>' . e($up[1] ?? '') . '</b>' . (($up[2] ?? '') !== '' ? ' · número ' . e(implode(', ', array_map($rotulo, explode(',', $up[2])))) : '') : '<span class="x">✗</span> <b>A Meta ainda não mandou nenhum aviso para o webhook.</b> Na Meta, em Campos do webhook, clique em <b>Testar</b> na linha <b>messages</b>: se aparecer aqui, o servidor recebe e o problema é a ligação da conta.' ?><br>
        <?php $upI = explode('|', getPConfig('vend_ig_ultimo_post')); if ($cfg['ig_conta'] !== '' || $upI[0] !== ''): ?>
          <?= $upI[0] !== '' ? '<span class="ok">✓</span> Último aviso do Instagram: <b>' . e(date('d/m H:i', strtotime($upI[0]))) . '</b>' . (($upI[2] ?? '') !== '' ? ' — campo <b>' . e($upI[2]) . '</b>' : '') : '<span class="x">✗</span> <b>O Instagram ainda não mandou nenhum aviso.</b> No app da Meta → Instagram → Webhooks: URL <code>' . e(proBaseUrl() . '/ig_webhook.php') . '</code>, assine <b>messages</b>, e mande uma DM de teste para a sua conta.' ?><br>
          <?php $assI = explode('|', getPConfig('vend_ig_webhook_assinatura')); if (($assI[0] ?? '') === 'falhou'): ?>⚠️ A assinatura dos avisos do Instagram não confere: confira a chave secreta do app do Instagram.<br><?php endif; ?>
        <?php endif; ?>
        <?php if ($bloq): ?><span class="x">✗</span> <b>A assinatura do webhook não confere e o bloqueio está ligado: a Meta entrega, mas o sistema recusa.</b> Configurações → WhatsApp: confira a chave secreta do app (ou desmarque o bloqueio).<br>
        <?php elseif (($ass[0] ?? '') === 'falhou'): ?>⚠️ A assinatura do webhook não confere (a chave secreta do app está diferente), mas o bloqueio está desligado — as mensagens entram mesmo assim.<br><?php endif; ?>
        <div id="verif" style="margin:10px 0;padding:10px;border:1px solid var(--borda);border-radius:8px;">
          <form method="POST" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="verificar_numero">
            <button class="btn btn-sm">🔍 Perguntar à Meta como está o número de vendas</button></form>
          <?php $vv = $_SESSION['vd_verif'] ?? null; if ($vv): $n = $vv['numero'] ?? []; ?>
            <div style="margin-top:8px;">Resposta da Meta (<?= e($vv['em']) ?>):<br>
            <?php if (isset($n['erro'])): ?><span class="x">✗</span> <?= e($n['erro']) ?><br>
            <?php else: ?>
              Número: <b><?= e($n['display_phone_number'] ?? '?') ?></b> · <?= e($n['verified_name'] ?? '') ?><br>
              Plataforma: <b><?= e($n['platform_type'] ?? '?') ?></b><?= ($n['platform_type'] ?? '') === 'CLOUD_API' ? ' <span class="ok">✓ está na API</span>' : ' <span class="x">✗ não está na API do WhatsApp (Cloud API) — a Meta não manda as mensagens dele para o sistema</span>' ?><br>
              <?php if (array_key_exists('is_on_biz_app', $n)): ?>Também no app WhatsApp Business (coexistência): <b><?= $n['is_on_biz_app'] ? 'sim' : 'não' ?></b><br><?php endif; ?>
              Status: <b><?= e($n['status'] ?? '?') ?></b> · verificação: <?= e($n['code_verification_status'] ?? '?') ?> · qualidade: <?= e($n['quality_rating'] ?? '?') ?><?= isset($n['account_mode']) ? ' · modo: ' . e($n['account_mode']) : '' ?><br>
            <?php endif; ?>
            <?php if (isset($vv['apps'])): ?>Apps ligados à conta (recebem os avisos): <b><?= $vv['apps'] ? e(implode(', ', $vv['apps'])) : 'nenhum' ?></b><?= $vv['apps'] ? '' : ' <span class="x">✗ clique em Buscar meus números → Usar este</span>' ?><br><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($dg['ultimas']): ?>
          <div class="tabwrap"><table class="tab" style="font-size:.75rem;">
            <tr><th>Quando</th><th>Número</th><th>De / para</th><th>Tipo</th><th>Status</th></tr>
            <?php foreach ($dg['ultimas'] as $u): ?><tr><td><?= e(date('d/m H:i', strtotime($u['recebido_em']))) ?></td><td><?= e($rotulo((string)$u['phone_number_id'])) ?></td><td><?= e($u['from_e164']) ?></td><td><?= e($u['tipo']) ?></td><td><?= e($u['status']) ?><?= $u['erro'] ? ' — ' . e($u['erro']) : '' ?></td></tr><?php endforeach; ?>
          </table></div>
        <?php endif; ?>
        <?php if ($logW): ?><div style="margin-top:8px;">Log do webhook (últimas linhas):<pre style="white-space:pre-wrap;font-size:.7rem;background:var(--card2);padding:8px;border-radius:8px;margin:4px 0 0;"><?= e(implode("\n", $logW)) ?></pre></div><?php endif; ?>
      </div>
    </details>

    <details class="painel" <?= !$cfg['phone_id'] ? 'open' : '' ?>>
      <summary class="painel-h"><div class="painel-t">⚙️ Configuração do módulo</div></summary>
      <div class="painel-b">
        <div class="dica" style="margin-bottom:10px;line-height:1.8;">
          <?= $temToken ? '<span class="ok">✓</span>' : '<span class="x">✗</span>' ?> Token da Meta (<a href="<?= e(proUrl('config')) ?>">Configurações → WhatsApp</a>)<br>
          <?= $cfg['phone_id'] ? '<span class="ok">✓</span>' : '<span class="x">✗</span>' ?> Phone Number ID do número de vendas<br>
          <?= $temClaude ? '<span class="ok">✓</span>' : '<span class="x">✗</span>' ?> Chave do Claude<br>
          • Webhook na Meta: <code><?= e($webhookUrl) ?></code> — assinar <b>messages</b> e <b>smb_message_echoes</b> (o segundo faz a IA parar quando você escreve pelo celular).<br>
          • Cron a cada minuto: <code>cron_wa_inbound.php</code> (comando em Configurações).<br>
          • Objetivo, preço permitido, proibições e fatos extras da IA ficam em cada <a href="<?= e(proUrl('campanhas')) ?>">campanha</a>.
        </div>
        <div style="border:1px solid var(--borda);border-radius:10px;padding:12px;margin-bottom:14px;">
          <div style="font-weight:700;margin-bottom:6px;">🔌 Conectar o número de vendas</div>
          <?php $vNum = getPConfig('vend_numero'); if ($cfg['phone_id']): ?>
            <div class="dica" style="margin-bottom:8px;"><span class="ok">✓</span> Conectado<?= $vNum !== '' ? ': <b>' . e($vNum) . '</b>' : '' ?> (ID <?= e($cfg['phone_id']) ?>). Pode conectar de novo se trocar de número.</div>
          <?php endif; ?>
          <?php $vdNums = $_SESSION['vd_numeros'] ?? []; if ($vdNums): ?>
            <div style="display:grid;gap:6px;margin-bottom:10px;">
            <?php foreach ($vdNums as $n): ?>
              <form method="POST" class="linha" style="align-items:center;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="usar_numero">
                <input type="hidden" name="waba" value="<?= e($n['waba']) ?>"><input type="hidden" name="pid" value="<?= e($n['id']) ?>"><input type="hidden" name="numero" value="<?= e($n['numero']) ?>">
                <span style="flex:1;min-width:180px;"><b><?= e($n['numero']) ?></b> <span class="dica"><?= e($n['nome']) ?></span></span>
                <button class="btn btn-p btn-sm" type="submit">Usar este</button>
              </form>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="POST" class="linha">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="buscar_numeros">
            <input type="text" name="waba" class="input" style="flex:1;min-width:200px;width:auto;" placeholder="ID da conta do WhatsApp (opcional)" inputmode="numeric">
            <button class="btn btn-p btn-sm" type="submit">Buscar meus números</button>
          </form>
          <div class="dica" style="margin-top:6px;">O sistema procura os números que o token alcança. Se o seu não aparecer, a tela explica o que liberar na Meta.</div>
        </div>
        <form method="POST" style="display:grid;gap:10px;">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="config">
          <label class="dica"><input type="checkbox" name="ativo" value="1" <?= $cfg['ativo'] ? 'checked' : '' ?>> <b>IA respondendo</b> (desligado = só registra as respostas)</label>
          <label class="dica"><input type="checkbox" name="digitando" value="1" <?= $cfg['digitando'] ? 'checked' : '' ?>> <b>Parecer gente</b>: marca como lida, mostra "digitando…" e responde com o tempo de uma pessoa (uns 10 a 40 s)</label>
          <div class="campo" style="margin:0;"><label class="rotulo">Quem chama o número de vendas sem ter sido abordado (anúncio "clique para o WhatsApp")</label>
            <select name="anuncio" class="input"><?php foreach (['anuncio' => 'A IA atende quem vem de anúncio (Instagram/Facebook)', 'todos' => 'A IA atende qualquer contato novo', 'nao' => 'Ninguém novo: eu respondo pelo app'] as $k => $rot): ?><option value="<?= $k ?>" <?= $cfg['anuncio'] === $k ? 'selected' : '' ?>><?= $rot ?></option><?php endforeach; ?></select></div>
          <div class="campo" style="margin:0;"><label class="rotulo">Campanha que atende esses contatos (e quem comenta no Instagram)</label>
            <select name="anuncio_campanha" class="input"><option value="0">A campanha ativa mais recente</option><?php foreach ($camps as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$cfg['anuncio_campanha'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
          <div class="campo" style="margin:0;"><label class="rotulo">Phone Number ID do número de vendas</label><input type="text" name="phone_id" class="input" value="<?= e($cfg['phone_id']) ?>" placeholder="Ex.: 123456789012345"></div>
          <button class="btn btn-p btn-sm" style="justify-self:start;">Salvar</button>
        </form>
      </div>
    </details>

    <?php $igAviso = $_SESSION['vd_ig_aviso'] ?? ''; unset($_SESSION['vd_ig_aviso']); $igCom = $cfg['ig_coment']; $igUrl = proBaseUrl() . '/ig_webhook.php'; ?>
    <details class="painel" <?= $igAviso ? 'open' : '' ?>>
      <summary class="painel-h"><div class="painel-t">📸 Instagram Direct <?= $cfg['ig_conta'] !== '' ? '<span class="ok" style="font-size:.8rem;">— ' . e($cfg['ig_usuario'] ?: 'conectado') . ($cfg['ig_ativo'] ? ' · IA ligada' : ' · IA desligada') . '</span>' : '' ?></div></summary>
      <div class="painel-b">
        <?php if ($igAviso): ?><div class="dica x" style="margin-bottom:10px;"><?= e($igAviso) ?></div><?php endif; ?>
        <div class="dica" style="margin-bottom:10px;line-height:1.7;">
          <b>Como funciona:</b> a Meta não deixa sistema nenhum iniciar conversa no Direct. A 1ª mensagem você manda pelo app (na Prospecção, o botão 📸 copia o texto e abre a conversa). Quando o contato responder, a IA continua por aqui — dentro de 24 h da última mensagem dele, que é a regra do Instagram.<br>
          <b>Para ligar (uma vez):</b><br>
          1. Instagram da Opa como <b>conta profissional</b> (Empresa ou Criador).<br>
          2. No app do Instagram: Configurações → Mensagens e respostas → <b>Ferramentas conectadas</b> → permitir acesso às mensagens.<br>
          3. No app da Meta (developers.facebook.com, o mesmo do WhatsApp): adicione o produto <b>Instagram</b> → "API com login do Instagram" → adicione a sua conta e clique em <b>Gerar token</b>. Cole o token abaixo.<br>
          4. Ainda lá, em <b>Configurar webhooks</b>: URL <code><?= e($igUrl) ?></code>, token de verificação = o mesmo do WhatsApp (<a href="<?= e(proUrl('config')) ?>">Configurações</a>), e assine <b>messages</b>, <b>messaging_postbacks</b> e (se for usar) <b>comments</b>.<br>
          5. Para responder quem não é testador do app, a Meta pede a <b>análise do app</b> da permissão <code>instagram_business_manage_messages</code> (e <code>instagram_business_manage_comments</code> para comentários). Até lá, funciona com as contas cadastradas como testadoras.
        </div>
        <form method="POST" style="display:grid;gap:10px;">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="ig_config">
          <div class="campo" style="margin:0;"><label class="rotulo">Token da conta do Instagram <?= $cfg['ig_conta'] !== '' ? '(conectado: ' . e($cfg['ig_usuario']) . ' — deixe vazio para manter; renova sozinho)' : '' ?></label>
            <input type="password" name="ig_token" class="input" autocomplete="off" placeholder="<?= $cfg['ig_conta'] !== '' ? '•••••••• salvo' : 'Cole aqui o token gerado no app da Meta' ?>"></div>
          <div class="campo" style="margin:0;"><label class="rotulo">Chave secreta do app do Instagram (opcional — confere que os avisos vêm mesmo da Meta)</label>
            <input type="password" name="ig_secret" class="input" autocomplete="off" placeholder="<?= trim(getPConfig('vend_ig_app_secret')) !== '' ? '•••••••• salva' : 'Instagram → Configuração da API → Chave secreta do app do Instagram' ?>"></div>
          <label class="dica"><input type="checkbox" name="ig_ativo" value="1" <?= $cfg['ig_ativo'] ? 'checked' : '' ?>> <b>IA respondendo no Direct</b> (desligado = só registra as respostas; vale também o "IA respondendo" geral)</label>
          <div class="campo" style="margin:0;"><label class="rotulo">Quem comenta num post seu recebe uma DM?</label>
            <select name="ig_coment_modo" class="input">
              <option value="" <?= $igCom === '' ? 'selected' : '' ?>>Não</option>
              <option value="todos" <?= $igCom === '*' ? 'selected' : '' ?>>Sim, qualquer comentário</option>
              <option value="palavra" <?= $igCom !== '' && $igCom !== '*' ? 'selected' : '' ?>>Só quem comentar a palavra…</option>
            </select></div>
          <div class="campo" style="margin:0;"><label class="rotulo">Palavra (ex.: QUERO SITE)</label><input type="text" name="ig_coment_palavra" class="input" value="<?= $igCom !== '*' ? e($igCom) : '' ?>"></div>
          <div class="campo" style="margin:0;"><label class="rotulo">DM enviada a quem comentar (a IA continua quando a pessoa responder)</label>
            <textarea name="ig_coment_msg" class="input" rows="2"><?= e($cfg['ig_coment_msg']) ?></textarea></div>
          <div class="dica">Uma DM por pessoa a cada 7 dias. Quem comentou entra na Prospecção na lista "Comentários no Instagram", na campanha escolhida acima.</div>
          <div class="linha">
            <button class="btn btn-p btn-sm">Salvar</button>
            <?php if ($cfg['ig_conta'] !== ''): ?><button class="btn btn-sm" name="acao" value="ig_desconectar" formnovalidate onclick="return confirm('Desconectar o Instagram?');">Desconectar</button><?php endif; ?>
          </div>
        </form>
      </div>
    </details>
  </div>
</div>
<script>
(function () {
  var CSRF = <?= json_encode(csrfToken()) ?>, box = document.getElementById('vdSim'), t = document.getElementById('vdSimT');
  if (!box || !t) return;
  function add(txt, cls) { var d = document.createElement('div'); d.className = 'vd-m ' + cls; d.textContent = txt; box.appendChild(d); box.scrollTop = box.scrollHeight; return d; }
  function post(d) { var fd = new FormData(); fd.append('csrf', CSRF); fd.append('acao', 'simular'); fd.append('ajax', '1'); fd.append('campanha', document.getElementById('vdCamp').value); for (var k in d) fd.append(k, d[k]); return fetch(<?= json_encode(proUrl('vendedor_ia')) ?>, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }); }
  document.getElementById('vdSimF').onsubmit = function (e) {
    e.preventDefault(); var x = t.value.trim(); if (!x) return; t.value = '';
    var prim = document.getElementById('vdPrim'), p1 = prim ? prim.value.trim() : '';
    if (prim) { add(p1 || '(1ª mensagem de exemplo)', 'voce'); prim.remove(); }
    add(x, 'cliente'); var w = add('…', 'ia');
    post({texto: x, primeira: p1}).then(function (j) {
      w.textContent = j.ok ? j.resposta + (j.acao && j.acao !== 'nenhuma' ? '\n[ação: ' + j.acao + (j.motivo ? ' — ' + j.motivo : '') + ']' : '') : '⚠️ ' + (j.erro || 'falhou');
    }).catch(function () { w.textContent = '⚠️ Falha de conexão.'; });
  };
  document.getElementById('vdSimR').onclick = function () { post({reiniciar: 1}).then(function () { location.reload(); }); };
})();
</script>
<?php proFooter();
