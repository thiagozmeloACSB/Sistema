<?php
/**
 * Print My Case — Cron de Trial e Inadimplência
 *
 * Execute via cron diariamente:
 * 0 3 * * * /usr/bin/php /home/SEU_USER/public_html/cron_trial.php >> /dev/null 2>&1
 *
 * Na Hostinger: hPanel → Cron Jobs → Adicionar
 * Comando: php /home/SEU_USER/public_html/cron_trial.php
 * Frequência: Uma vez por dia (ex: 03:00)
 *
 * ACESSO EXTERNO BLOQUEADO pelo .htaccess — roda só via CLI/cron.
 */

// Bloqueia acesso via web
if (isset($_SERVER['HTTP_HOST'])) {
    http_response_code(403);
    exit('Acesso negado');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$hoje = date('Y-m-d');
$log  = [];

// ── 1. Expira trials vencidos ──────────────────────────────
$st = db()->prepare("
    UPDATE tenants
    SET status = 'inadimplente'
    WHERE status = 'trial'
      AND trial_ends_at IS NOT NULL
      AND trial_ends_at < ?
");
$st->execute([$hoje]);
$expirados = $st->rowCount();
$log[] = "[{$hoje}] Trials expirados: {$expirados}";

// ── 2. Recalcula status Meta Ads de todos os tenants ───────
$meta = db()->query("SELECT id, token_expires_at FROM meta_configs WHERE access_token IS NOT NULL");
$atualizados = 0;
foreach ($meta->fetchAll(PDO::FETCH_ASSOC) as $m) {
    if (!$m['token_expires_at']) continue;
    $dias   = (int)ceil((strtotime($m['token_expires_at']) - time()) / 86400);
    $status = $dias <= 0 ? 'expirado' : ($dias <= 10 ? 'expirando' : 'ok');
    db()->prepare("UPDATE meta_configs SET token_status = ? WHERE id = ?")
        ->execute([$status, $m['id']]);
    $atualizados++;
}
$log[] = "[{$hoje}] Tokens Meta verificados: {$atualizados}";

// ── 3. Log em arquivo ──────────────────────────────────────
$logFile = __DIR__ . '/cron.log';
// Mantém apenas as últimas 200 linhas
$linhas = file_exists($logFile) ? file($logFile) : [];
$linhas = array_slice(array_merge($linhas, array_map(fn($l) => $l . PHP_EOL, $log)), -200);
file_put_contents($logFile, implode('', $linhas));

echo implode(PHP_EOL, $log) . PHP_EOL;
