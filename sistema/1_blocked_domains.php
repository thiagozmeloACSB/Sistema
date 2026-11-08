<?php
/**
 * MÓDULO 1 — Bloqueio de Domínios Suspeitos
 * Opa Studio Security — PHP Puro
 * 
 * Como usar:
 *   require 'security/1_blocked_domains.php';
 *   $check = checkEmailSecurity($_POST['email']);
 *   if (!$check['ok']) die($check['message']);
 */

// ─── Lista de domínios bloqueados ───────────────────────────────────────────
// Adicione mais conforme necessário
$BLOCKED_DOMAINS = [
    // Domínios descartáveis populares
    'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwam.com',
    'yopmail.com', 'trashmail.com', 'sharklasers.com', 'guerrillamailblock.com',
    'grr.la', 'guerrillamail.info', 'spam4.me', 'fakeinbox.com',
    'dispostable.com', 'mailnull.com', 'spamgourmet.com', 'trashmail.me',
    'getairmail.com', 'filzmail.com', 'throwam.com', 'tempr.email',
    'discard.email', 'spamhereplease.com', 'mailexpire.com',
    // Domínios russos/suspeitos identificados no seu sistema
    'rambler.ru', 'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru',
];

// ─── Países de alto risco (via IP) ──────────────────────────────────────────
// Opcional: bloquear IPs de países não esperados
$HIGH_RISK_COUNTRY_CODES = ['RU', 'CN', 'KP', 'IR', 'BY'];

/**
 * Verifica se o e-mail é seguro para cadastro
 */
function checkEmailSecurity(string $email): array {
    global $BLOCKED_DOMAINS;

    $email = strtolower(trim($email));

    // 1. Validação básica de formato
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'E-mail inválido.'];
    }

    // 2. Extrai o domínio
    $domain = substr(strrchr($email, '@'), 1);

    // 3. Verifica lista negra
    if (in_array($domain, $BLOCKED_DOMAINS)) {
        logSuspiciousAttempt($email, 'BLOCKED_DOMAIN');
        return [
            'ok'      => false,
            'message' => 'Este e-mail não é permitido. Use um e-mail corporativo ou de provedor confiável.',
        ];
    }

    // 4. Verifica se domínio tem registro MX válido (DNS check)
    if (!checkdnsrr($domain, 'MX')) {
        logSuspiciousAttempt($email, 'NO_MX_RECORD');
        return [
            'ok'      => false,
            'message' => 'Domínio do e-mail não encontrado. Verifique e tente novamente.',
        ];
    }

    return ['ok' => true, 'message' => 'E-mail válido.'];
}

/**
 * Registra tentativa suspeita em log
 */
function logSuspiciousAttempt(string $email, string $reason): void {
    $logFile = __DIR__ . '/logs/suspicious.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $line = sprintf(
        "[%s] IP=%s | EMAIL=%s | MOTIVO=%s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $email,
        $reason
    );
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ─── EXEMPLO DE USO NO FORMULÁRIO DE CADASTRO ───────────────────────────────
/*
require 'security/1_blocked_domains.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $check = checkEmailSecurity($_POST['email']);
    if (!$check['ok']) {
        http_response_code(422);
        echo json_encode(['error' => $check['message']]);
        exit;
    }
    // ...continua o cadastro normalmente
}
*/
