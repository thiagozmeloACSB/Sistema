<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// Configurações: chaves de API (ficam no banco, nunca em arquivo), empresa, WhatsApp/Vendedor IA e bloqueios.
$segredos = ['claude_api_key', 'google_places_key', 'meta_wpp_token', 'wa_app_secret', 'openai_api_key'];   // campos que nunca voltam preenchidos na tela
$self = proUrl('config');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfCheck($_POST['csrf'] ?? '')) { flash('Sessão expirada.', 'error'); redirect($self); }
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'geral') {
        setPConfig('empresa_nome', mb_substr(trim((string)($_POST['empresa_nome'] ?? '')), 0, 120) ?: 'Opa Tecnologia e Design');
        $em = trim((string)($_POST['aviso_email'] ?? ''));
        setPConfig('aviso_email', ($em === '' || filter_var($em, FILTER_VALIDATE_EMAIL)) ? $em : getPConfig('aviso_email'));
        setPConfig('msg_saida', mb_substr(trim((string)($_POST['msg_saida'] ?? '')), 0, 200));
        setPConfig('claude_modelo', mb_substr(trim((string)($_POST['claude_modelo'] ?? '')), 0, 60));
        flash('Configurações gerais salvas.'); redirect($self);
    }
    if ($acao === 'chaves') {
        $campos = ['claude_api_key', 'google_places_key', 'meta_wpp_token', 'wa_app_secret', 'openai_api_key'];
        foreach ($campos as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '') setPConfig($k, $v);
            elseif (!empty($_POST['remover_' . $k])) setPConfig($k, '');
        }
        setPConfig('wa_app_id', preg_replace('/\D/', '', (string)($_POST['wa_app_id'] ?? '')));
        setPConfig('meta_wpp_version', preg_match('/^v\d+\.\d+$/', trim((string)($_POST['meta_wpp_version'] ?? ''))) ? trim($_POST['meta_wpp_version']) : '');
        setPConfig('wa_webhook_exigir', isset($_POST['wa_webhook_exigir']) ? '1' : '0');
        flash('Chaves salvas. Os campos de chave ficam em branco por segurança; deixe vazio para manter a atual.'); redirect($self);
    }
    if ($acao === 'novo_token') {
        setPConfig(($_POST['qual'] ?? '') === 'cron' ? 'wa_cron_token' : 'wa_webhook_token', bin2hex(random_bytes(16)));
        flash('Novo token gerado. Atualize o valor na Meta / no cron.'); redirect($self);
    }
    if ($acao === 'bloquear') {
        $tipo = ($_POST['tipo'] ?? '') === 'cliente' ? 'cliente' : 'nao_contatar';
        $n = 0;
        foreach (preg_split('/[\r\n;,]+/', (string)($_POST['telefones'] ?? '')) as $t) {
            $tel = proTel($t); if (strlen($tel) < 12) continue;
            proBloquear($db, $tel, $tipo, '', 'importado em Configurações'); $n++;
            // quem já está na base acompanha o bloqueio
            $var = proTelVariantes($tel);
            $in = implode(',', array_fill(0, count($var), '?'));
            $s = $db->prepare("SELECT id FROM pro_prospects WHERE telefone IN ($in)"); $s->execute($var);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) proMudarStatus($db, (int)$id, $tipo === 'cliente' ? 'cliente' : 'nao_contatar');
        }
        flash($n ? "{$n} número(s) adicionados à lista." : 'Nenhum telefone válido encontrado (use DDD + número, um por linha).', $n ? 'ok' : 'error'); redirect($self);
    }
    if ($acao === 'desbloquear') {
        $db->prepare('DELETE FROM pro_bloqueados WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Removido da lista. Se o contato ainda existe na base, mude o status dele na Prospecção.'); redirect($self);
    }
    redirect($self);
}

$tem = fn($k) => trim(getPConfig($k)) !== '';
$base = proBaseUrl();
$cronCmd = '* * * * * /opt/alt/php82/usr/bin/php ' . PRO_ROOT . '/cron_wa_inbound.php';
$cronUrl = $base . '/cron_wa_inbound.php?k=' . getPConfig('wa_cron_token');
$bloqCont = $db->query('SELECT tipo, COUNT(*) FROM pro_bloqueados GROUP BY tipo')->fetchAll(PDO::FETCH_KEY_PAIR);
$bloqLista = $db->query('SELECT * FROM pro_bloqueados ORDER BY id DESC LIMIT 40')->fetchAll();
$ph = fn($k, $vazio) => $tem($k) ? '✓ configurada — cole uma nova para trocar' : $vazio;

proHeader('Configurações', 'config');
?>
<div class="grid-2">
<div>
  <form method="POST" class="painel"><div class="painel-h"><div class="painel-t">Geral</div></div><div class="painel-b">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="geral">
    <div class="campo"><label class="rotulo">Nome da empresa (painel e Vendedor IA)</label><input class="input" name="empresa_nome" value="<?= e(getPConfig('empresa_nome', 'Opa Tecnologia e Design')) ?>"></div>
    <div class="campo"><label class="rotulo">E-mail para avisos do Vendedor IA (quando alguém pede para falar com você)</label><input class="input" type="email" name="aviso_email" value="<?= e(getPConfig('aviso_email')) ?>" placeholder="<?= e($_SESSION['usuario']['email'] ?? '') ?>"></div>
    <div class="campo"><label class="rotulo">Frase de saída colocada no fim da 1ª mensagem (LGPD — vazio desliga)</label><input class="input" name="msg_saida" value="<?= e(getPConfig('msg_saida')) ?>" maxlength="200"></div>
    <div class="campo"><label class="rotulo">Modelo do Claude (vazio = claude-opus-5-5, o mesmo do DojoSystem)</label><input class="input" name="claude_modelo" value="<?= e(getPConfig('claude_modelo')) ?>" placeholder="claude-opus-5-5"></div>
    <button class="btn btn-p btn-sm">Salvar</button>
  </div></form>

  <form method="POST" class="painel"><div class="painel-h"><div class="painel-t">Chaves de API</div></div><div class="painel-b">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="chaves">
    <div class="dica" style="margin-bottom:12px;">As chaves ficam no banco de dados (tabela <code>pro_config</code>), não em arquivo. Campo em branco = mantém a atual.</div>

    <div class="rotulo" style="font-size:.9rem;color:var(--texto);">Claude (escreve as mensagens e conduz o Vendedor IA)</div>
    <div class="campo"><input class="input" type="password" name="claude_api_key" autocomplete="off" placeholder="<?= e($ph('claude_api_key', 'Cole a chave da API da Anthropic (console.anthropic.com)')) ?>">
      <?php if ($tem('claude_api_key')): ?><label class="dica"><input type="checkbox" name="remover_claude_api_key" value="1"> remover</label><?php endif; ?></div>

    <div class="rotulo" style="font-size:.9rem;color:var(--texto);margin-top:14px;">OpenAI — transcrição de áudio (opcional)</div>
    <div class="campo"><input class="input" type="password" name="openai_api_key" autocomplete="off" placeholder="<?= e($ph('openai_api_key', 'Cole a chave da OpenAI (platform.openai.com)')) ?>">
      <div class="dica">Com a chave, a nota de voz que o contato manda vira texto e a IA responde ao conteúdo. Sem ela, a IA pede para a pessoa escrever.</div>
      <?php if ($tem('openai_api_key')): ?><label class="dica"><input type="checkbox" name="remover_openai_api_key" value="1"> remover</label><?php endif; ?></div>

    <div class="rotulo" style="font-size:.9rem;color:var(--texto);margin-top:14px;">Google — Places API (New) (busca de negócios)</div>
    <div class="campo"><input class="input" type="password" name="google_places_key" autocomplete="off" placeholder="<?= e($ph('google_places_key', 'Cole a chave de API do Google')) ?>">
      <?php if ($tem('google_places_key')): ?><label class="dica"><input type="checkbox" name="remover_google_places_key" value="1"> remover</label><?php endif; ?></div>
    <details><summary class="dica">Como obter a chave do Google (uma vez só)</summary>
      <div class="dica" style="line-height:1.7;margin-top:6px;">
        1. Entre em <b>console.cloud.google.com</b> e crie um projeto (ex.: "Prospecção Opa").<br>
        2. Em <b>APIs e serviços → Biblioteca</b>, procure <b>"Places API (New)"</b> e clique em <b>Ativar</b>.<br>
        3. Em <b>Faturamento</b>, vincule um cartão (o Google exige, mesmo dentro da cota gratuita mensal).<br>
        4. Em <b>APIs e serviços → Credenciais → Criar credenciais → Chave de API</b>. Em "Restrições de API", deixe só a <b>Places API (New)</b>.<br>
        5. Copie a chave e cole acima.</div></details>

    <div class="rotulo" style="font-size:.9rem;color:var(--texto);margin-top:14px;">WhatsApp Cloud API (só para o Vendedor IA)</div>
    <div class="campo"><label class="rotulo">Token de acesso do usuário do sistema (Meta)</label>
      <input class="input" type="password" name="meta_wpp_token" autocomplete="off" placeholder="<?= e($ph('meta_wpp_token', 'Cole o token da Meta')) ?>">
      <?php if ($tem('meta_wpp_token')): ?><label class="dica"><input type="checkbox" name="remover_meta_wpp_token" value="1"> remover</label><?php endif; ?></div>
    <div class="grid-2">
      <div class="campo"><label class="rotulo">App ID da Meta</label><input class="input" name="wa_app_id" value="<?= e(getPConfig('wa_app_id')) ?>" inputmode="numeric"></div>
      <div class="campo"><label class="rotulo">Versão da Graph API</label><input class="input" name="meta_wpp_version" value="<?= e(getPConfig('meta_wpp_version')) ?>" placeholder="v21.0"></div>
    </div>
    <div class="campo"><label class="rotulo">App Secret (valida a assinatura do webhook)</label>
      <input class="input" type="password" name="wa_app_secret" autocomplete="off" placeholder="<?= e($ph('wa_app_secret', 'Cole o App Secret')) ?>">
      <?php if ($tem('wa_app_secret')): ?><label class="dica"><input type="checkbox" name="remover_wa_app_secret" value="1"> remover</label><?php endif; ?></div>
    <label class="dica"><input type="checkbox" name="wa_webhook_exigir" value="1" <?= getPConfig('wa_webhook_exigir') === '1' ? 'checked' : '' ?>> Recusar webhook com assinatura inválida (ligue só depois do diagnóstico mostrar "assinatura OK")</label>
    <div style="margin-top:12px;"><button class="btn btn-p btn-sm">Salvar chaves</button></div>
  </div></form>
</div>

<div>
  <div class="painel"><div class="painel-h"><div class="painel-t">WhatsApp: webhook e cron</div></div><div class="painel-b dica" style="line-height:1.9;">
    <b>URL do webhook (na Meta):</b><br><code><?= e($base . '/wa_webhook.php') ?></code><br>
    <b>Verificar token:</b><br><code><?= e(getPConfig('wa_webhook_token')) ?></code><br>
    <b>Campos a assinar:</b> <code>messages</code> e <code>smb_message_echoes</code><br>
    <b>Instagram Direct (opcional):</b> URL <code><?= e($base . '/ig_webhook.php') ?></code> (mesmo verify token). Detalhes em Vendedor IA → 📸 Instagram Direct.<br><br>
    <b>Cron (Hostinger → Avançado → Cron Jobs, a cada minuto):</b><br><code><?= e($cronCmd) ?></code><br>
    Ajuste a versão do PHP no caminho para a mesma dos seus outros crons.<br>
    <b>Alternativa por URL:</b><br><code><?= e($cronUrl) ?></code><br>
    <form method="POST" class="linha" style="margin-top:8px;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="novo_token">
      <button class="btn btn-sm" name="qual" value="webhook" onclick="return confirm('Gerar novo verify token? Você precisará atualizá-lo na Meta.');">Gerar novo verify token</button>
      <button class="btn btn-sm" name="qual" value="cron" onclick="return confirm('Gerar novo token do cron por URL?');">Gerar novo token do cron</button>
    </form>
  </div></div>

  <div class="painel"><div class="painel-h"><div class="painel-t">🚫 Não contatar / clientes atuais</div></div><div class="painel-b">
    <div class="dica" style="margin-bottom:8px;"><?= (int)($bloqCont['nao_contatar'] ?? 0) ?> número(s) em "não contatar" · <?= (int)($bloqCont['cliente'] ?? 0) ?> marcados como cliente. Esses números nunca entram em busca, planilha ou lista, em nenhuma campanha.</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="bloquear">
      <div class="campo"><textarea class="input" name="telefones" rows="3" placeholder="Um telefone por linha (com DDD)"></textarea></div>
      <div class="linha"><select name="tipo" class="input" style="max-width:240px;"><option value="nao_contatar">🚫 Não contatar</option><option value="cliente">🏆 Já é cliente</option></select>
        <button class="btn btn-p btn-sm">Adicionar</button></div>
    </form>
    <?php if ($bloqLista): ?>
    <details style="margin-top:10px;"><summary class="dica">Ver os últimos <?= count($bloqLista) ?></summary>
      <div class="tabwrap"><table class="tab"><?php foreach ($bloqLista as $b): ?>
        <tr><td><?= e($b['telefone']) ?><div class="dica"><?= e($b['nome']) ?></div></td><td><?= $b['tipo'] === 'cliente' ? '🏆 cliente' : '🚫 não contatar' ?></td><td><?= e(date('d/m/Y', strtotime($b['criado_em']))) ?></td>
          <td><form method="POST"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="desbloquear"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-sm" onclick="return confirm('Remover da lista? O número poderá voltar a ser contatado.');">remover</button></form></td></tr>
      <?php endforeach; ?></table></div></details>
    <?php endif; ?>
  </div></div>

</div>
</div>
<?php proFooter();
