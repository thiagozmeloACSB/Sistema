<?php
/**
 * Webhook de ENTRADA do Instagram (Direct) — Vendedor IA.
 * URL na Meta (app → Instagram → Configurar webhooks): https://SEU-SITE/pasta/ig_webhook.php
 *   "Verificar token": o mesmo do WhatsApp (Configurações → WhatsApp)
 *   Campos a assinar: messages, messaging_postbacks e (opcional) comments
 *
 * Igual ao wa_webhook.php: não processa nada. Confere a assinatura, joga cada evento em pro_wa_fila
 * e devolve 200 na hora. Quem trabalha é o cron_wa_inbound.php (waProcessarItem → vdIgItem).
 * Na fila: phone_number_id = "ig:<id da conta>", from_e164 = id do contato no Instagram (IGSID),
 * tipo = ig_text | ig_anexo | ig_echo | ig_comment.
 */
define('PRO_SEM_SESSAO', 1);
@ini_set('display_errors', '0');
require_once __DIR__ . '/includes/bootstrap.php';

function igw_log(string $msg): void {
    $fh = @fopen(PRO_ROOT . '/logs/wa_inbound.log', 'a');
    if ($fh) { @fwrite($fh, date('Y-m-d H:i:s') . ' | WEBHOOK IG | ' . $msg . "\n"); @fflush($fh); @fclose($fh); }
}

// ── Verificação (GET) ───────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $modo    = $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '';
    $token   = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $desafio = $_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '';
    $esperado = getPConfig('wa_webhook_token');
    if ($esperado !== '' && $modo === 'subscribe' && hash_equals($esperado, (string)$token)) { igw_log('VERIFY ok'); header('Content-Type: text/plain'); exit((string)$desafio); }
    igw_log('VERIFY recusado' . ($esperado === '' ? ': wa_webhook_token não configurado' : ': token não confere'));
    http_response_code(403); exit('forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') { http_response_code(200); exit; }

// ── Assinatura ──────────────────────────────────────────────
// Apps com "login do Instagram" assinam com a chave secreta do app do Instagram (vend_ig_app_secret);
// os demais, com a do app da Meta. Aceita qualquer uma das duas. Só bloqueia com a chave do Instagram
// preenchida E o bloqueio geral ligado (Configurações) — chave errada não derruba o Direct sem querer.
$recebida = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$segredos = array_filter([trim(getPConfig('vend_ig_app_secret')), trim(getPConfig('wa_app_secret'))]);
$assOk = false;
foreach ($segredos as $sg) if (hash_equals('sha256=' . hash_hmac('sha256', $raw, $sg), $recebida)) { $assOk = true; break; }
if ($segredos) {
    try { setPConfig('vend_ig_webhook_assinatura', ($assOk ? 'ok' : 'falhou') . '|' . date('Y-m-d H:i:s')); } catch (Throwable $e) {}
    if (!$assOk) {
        if (trim(getPConfig('vend_ig_app_secret')) !== '' && getPConfig('wa_webhook_exigir') === '1') { igw_log('POST recusado: assinatura inválida'); http_response_code(403); exit; }
        igw_log('AVISO: assinatura não confere (bloqueio desligado) — confira a chave secreta do app do Instagram');
    }
} else {
    igw_log('AVISO: nenhuma chave secreta cadastrada — evento aceito SEM validar assinatura');
}

$body = json_decode($raw, true);
if (!is_array($body)) { http_response_code(200); exit; }

$gravadas = 0; $ignoradas = 0; $campos = [];
try {
    $db = getDB();
    $ins = $db->prepare('INSERT IGNORE INTO pro_wa_fila (wamid, phone_number_id, from_e164, tipo, payload, recebido_em) VALUES (?,?,?,?,?,?)');
    $agora = date('Y-m-d H:i:s');
    $mid = fn(string $m) => strlen($m) <= 160 ? $m : 'igh:' . sha1($m);
    $enfileira = function (string $wamid, string $conta, string $de, string $tipo, array $ev) use ($ins, $agora, &$gravadas, &$ignoradas) {
        if ($wamid === '' || $de === '') { $ignoradas++; return; }
        $ins->execute([$wamid, 'ig:' . $conta, substr($de, 0, 20), $tipo, json_encode($ev, JSON_UNESCAPED_UNICODE), $agora]);
        $ins->rowCount() > 0 ? $gravadas++ : $ignoradas++;   // UNIQUE(wamid): reentrega não duplica
    };
    // Um evento de Direct (formato "messaging" ou "changes → messages")
    $evento = function (string $conta, array $ev) use ($enfileira, $mid) {
        if (isset($ev['message'])) {
            $m = $ev['message'];
            if (!empty($m['is_deleted']) || !empty($m['is_unsupported'])) return;
            $eco = !empty($m['is_echo']);
            $de = (string)($eco ? ($ev['recipient']['id'] ?? '') : ($ev['sender']['id'] ?? ''));
            $tipo = $eco ? 'ig_echo' : (trim((string)($m['text'] ?? '')) !== '' ? 'ig_text' : 'ig_anexo');
            $enfileira($mid((string)($m['mid'] ?? '')), $conta, $de, $tipo, $ev);
        } elseif (isset($ev['postback'])) {   // botões de "quebra-gelo" do Direct
            $enfileira($mid((string)($ev['postback']['mid'] ?? '')), $conta, (string)($ev['sender']['id'] ?? ''), 'ig_text', $ev);
        }
        // read, reaction, typing…: nada a fazer
    };
    foreach (($body['entry'] ?? []) as $entry) {
        $conta = (string)($entry['id'] ?? '');
        foreach (($entry['messaging'] ?? []) as $ev) { $campos[] = 'messaging'; $evento($conta, $ev); }
        foreach (($entry['changes'] ?? []) as $ch) {
            $campo = (string)($ch['field'] ?? '?'); $v = $ch['value'] ?? [];
            $campos[] = $campo;
            if ($campo === 'messages' || $campo === 'messaging_postbacks') $evento($conta, $v);
            elseif ($campo === 'comments' || $campo === 'live_comments') $enfileira('igc:' . (string)($v['id'] ?? ''), $conta, (string)($v['from']['id'] ?? ''), 'ig_comment', $v);
        }
    }
} catch (Throwable $e) {
    igw_log('ERRO ao enfileirar: ' . $e->getMessage());   // devolve 200 mesmo assim: 500 faz a Meta reentregar em loop
}
try { setPConfig('vend_ig_ultimo_post', date('Y-m-d H:i:s') . '|' . (string)($body['object'] ?? '') . '|' . implode(',', array_unique($campos))); } catch (Throwable $e) {}
if ($gravadas || $ignoradas) igw_log("enfileiradas={$gravadas} ignoradas={$ignoradas}");
http_response_code(200);
header('Content-Type: text/plain');
echo 'EVENT_RECEIVED';
