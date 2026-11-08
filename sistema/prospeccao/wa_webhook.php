<?php
/**
 * Webhook de ENTRADA do WhatsApp (Meta Cloud API) — só para o Vendedor IA.
 * URL na Meta: https://SEU-SITE/pasta/wa_webhook.php
 *   "Verificar token": o valor mostrado em Configurações → WhatsApp
 *   Campos a assinar: messages e smb_message_echoes
 *
 * Este arquivo NÃO processa nada. Ele só:
 *   1. responde à verificação (GET hub.challenge) da Meta;
 *   2. confere a assinatura X-Hub-Signature-256;
 *   3. joga cada mensagem em pro_wa_fila;
 *   4. devolve 200 na hora (a Meta reentrega se demorar; quem trabalha é cron_wa_inbound.php).
 */
define('PRO_SEM_SESSAO', 1);
@ini_set('display_errors', '0');
require_once __DIR__ . '/includes/bootstrap.php';

$LOGF = PRO_ROOT . '/logs/wa_inbound.log';
function wh_log(string $msg): void {
    global $LOGF;
    $fh = @fopen($LOGF, 'a');
    if ($fh) { @fwrite($fh, date('Y-m-d H:i:s') . ' | WEBHOOK | ' . $msg . "\n"); @fflush($fh); @fclose($fh); }
}

// ── 1. Verificação (GET) ────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $modo    = $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '';
    $token   = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $desafio = $_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '';
    $esperado = getPConfig('wa_webhook_token');
    if ($esperado === '') { wh_log('VERIFY recusado: wa_webhook_token não configurado'); http_response_code(500); exit('token nao configurado'); }
    if ($modo === 'subscribe' && hash_equals($esperado, (string)$token)) { wh_log('VERIFY ok'); header('Content-Type: text/plain'); exit((string)$desafio); }
    wh_log('VERIFY recusado: token nao confere');
    http_response_code(403); exit('forbidden');
}

// ── 2. Eventos (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') { http_response_code(200); exit; }

// Assinatura: a Meta assina o corpo cru com o App Secret. Duas fases:
//  • só a chave preenchida: confere e ANOTA o resultado (não bloqueia), para você ver "assinatura OK";
//  • "Recusar assinatura inválida" ligado em Configurações: assinatura errada → 403.
$appSecret = getPConfig('wa_app_secret');
if ($appSecret !== '') {
    $assOk = hash_equals('sha256=' . hash_hmac('sha256', $raw, $appSecret), (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    try { setPConfig('wa_webhook_assinatura', ($assOk ? 'ok' : 'falhou') . '|' . date('Y-m-d H:i:s')); } catch (Throwable $e) {}
    if (!$assOk) {
        if (getPConfig('wa_webhook_exigir') === '1') { wh_log('POST recusado: assinatura invalida'); http_response_code(403); exit; }
        wh_log('AVISO: assinatura nao confere (bloqueio desligado) — confira a chave secreta do app');
    }
} else {
    wh_log('AVISO: wa_app_secret vazio — evento aceito SEM validar assinatura');
}

$body = json_decode($raw, true);
if (!is_array($body)) { http_response_code(200); exit; }

// Último aviso da Meta (qualquer tipo): o diagnóstico do Vendedor IA mostra.
try {
    $campos = []; $nums = [];
    foreach (($body['entry'] ?? []) as $en) foreach (($en['changes'] ?? []) as $ch) {
        $campos[] = (string)($ch['field'] ?? '?');
        if (!empty($ch['value']['metadata']['phone_number_id'])) $nums[] = (string)$ch['value']['metadata']['phone_number_id'];
    }
    setPConfig('wa_webhook_ultimo_post', date('Y-m-d H:i:s') . '|' . implode(',', array_unique($campos)) . '|' . implode(',', array_unique($nums)));
} catch (Throwable $e) {}

// Estrutura da Meta: entry[] → changes[] → value → messages[] / message_echoes[]
$gravadas = 0; $ignoradas = 0;
try {
    $db = getDB();
    $ins = $db->prepare('INSERT IGNORE INTO pro_wa_fila (wamid, phone_number_id, from_e164, tipo, payload, recebido_em) VALUES (?,?,?,?,?,?)');
    $agora = date('Y-m-d H:i:s');
    foreach (($body['entry'] ?? []) as $entry) foreach (($entry['changes'] ?? []) as $change) {
        $value   = $change['value'] ?? [];
        $phoneId = (string)($value['metadata']['phone_number_id'] ?? '');
        // Coexistência: o que o dono manda PELO APP chega como "eco" (destinatário no lugar do remetente).
        foreach (($value['message_echoes'] ?? []) as $eco) {
            $wamid = (string)($eco['id'] ?? ''); $para = preg_replace('/\D/', '', (string)($eco['to'] ?? ''));
            if ($wamid === '' || $para === '') { $ignoradas++; continue; }
            $ins->execute([$wamid, $phoneId, $para, 'echo', json_encode($eco, JSON_UNESCAPED_UNICODE), $agora]);
            $ins->rowCount() > 0 ? $gravadas++ : $ignoradas++;
        }
        // statuses[] (confirmações de entrega) não entram na fila.
        foreach (($value['messages'] ?? []) as $msg) {
            $wamid = (string)($msg['id'] ?? ''); $from = preg_replace('/\D/', '', (string)($msg['from'] ?? ''));
            if ($wamid === '' || $from === '') { $ignoradas++; continue; }
            $ins->execute([$wamid, $phoneId, $from, (string)($msg['type'] ?? 'unknown'), json_encode($msg, JSON_UNESCAPED_UNICODE), $agora]);
            $ins->rowCount() > 0 ? $gravadas++ : $ignoradas++;   // UNIQUE(wamid): reentrega da Meta não duplica
        }
    }
} catch (Throwable $e) {
    // Mesmo com erro interno devolvemos 200: um 500 faz a Meta reentregar em loop.
    wh_log('ERRO ao enfileirar: ' . $e->getMessage());
    http_response_code(200); exit('EVENT_RECEIVED');
}
if ($gravadas > 0 || $ignoradas > 0) wh_log("enfileiradas={$gravadas} ignoradas={$ignoradas}");
http_response_code(200);
header('Content-Type: text/plain');
echo 'EVENT_RECEIVED';
