<?php
/**
 * Webhook Asaas — Print My Case
 *
 * Configure no Asaas:
 * Conta → Configurações → Integrações → Webhooks
 * URL: https://seudominio.com.br/webhook.php
 * Eventos: PAYMENT_CONFIRMED, PAYMENT_RECEIVED, PAYMENT_OVERDUE,
 *          SUBSCRIPTION_DELETED, PAYMENT_DELETED
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

// ── VERIFICA TOKEN ─────────────────────────────────────────
$token = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? $_GET['token'] ?? '';
if ($token !== WEBHOOK_SECRET && WEBHOOK_SECRET !== '') {
    http_response_code(401);
    echo json_encode(['erro' => 'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['erro' => 'Payload inválido']);
    exit;
}

$event          = $payload['event'] ?? '';
$payment        = $payload['payment'] ?? [];
$subscription   = $payload['subscription'] ?? $payment['subscription'] ?? null;
$subscription_id = $subscription['id'] ?? $payment['subscription'] ?? '';

// Log (opcional — remover em produção se gerar muito volume)
error_log("Webhook Asaas [{$event}] subscription={$subscription_id}");

if (!$subscription_id) {
    echo json_encode(['ok' => true, 'msg' => 'Sem subscription_id, ignorado']);
    exit;
}

// Busca tenant pelo subscription_id
$st = db()->prepare("SELECT * FROM tenants WHERE asaas_subscription_id = ?");
$st->execute([$subscription_id]);
$tenant = $st->fetch();

if (!$tenant) {
    echo json_encode(['ok' => true, 'msg' => 'Tenant não encontrado, ignorado']);
    exit;
}

$tid = $tenant['id'];

switch ($event) {
    // ── PAGAMENTO CONFIRMADO ──────────────────────────────
    case 'PAYMENT_CONFIRMED':
    case 'PAYMENT_RECEIVED':
        db()->prepare("UPDATE tenants SET status='ativo' WHERE id=?")
            ->execute([$tid]);
        break;

    // ── PAGAMENTO VENCIDO ─────────────────────────────────
    case 'PAYMENT_OVERDUE':
        // Dá 3 dias de graça antes de suspender
        $vencimento = $payment['dueDate'] ?? null;
        $diasAtraso = $vencimento
            ? (int)((time() - strtotime($vencimento)) / 86400)
            : 0;
        if ($diasAtraso >= 3) {
            db()->prepare("UPDATE tenants SET status='inadimplente' WHERE id=?")
                ->execute([$tid]);
        }
        break;

    // ── ASSINATURA CANCELADA ──────────────────────────────
    case 'SUBSCRIPTION_DELETED':
    case 'PAYMENT_DELETED':
        db()->prepare("UPDATE tenants SET status='cancelado' WHERE id=?")
            ->execute([$tid]);
        break;
}

echo json_encode(['ok' => true]);
