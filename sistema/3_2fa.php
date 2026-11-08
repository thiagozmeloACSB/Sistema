<?php
/**
 * MÓDULO 3 — 2FA para o Super Admin (TOTP via Google Authenticator)
 * Opa Studio Security — PHP Puro
 * 
 * Sem dependências externas — implementa RFC 6238 (TOTP) manualmente.
 * 
 * FLUXO:
 *  1. Superadmin faz login normal (usuário + senha)
 *  2. Sistema verifica se já tem segredo 2FA salvo
 *     - Se NÃO: mostra QR Code para configurar no Google Authenticator
 *     - Se SIM: pede o código de 6 dígitos
 *  3. Código validado → sessão liberada
 */

// ─── Configuração ─────────────────────────────────────────────────────────────
define('TOTP_ISSUER',      'Opa Studio');
define('TOTP_DIGITS',      6);
define('TOTP_PERIOD',      30);   // segundos por código
define('TOTP_WINDOW',      1);    // aceita ±1 período (tolerância de clock)
define('TOTP_SECRET_FILE', __DIR__ . '/secrets/2fa_secret.txt'); // guarde fora da web root em produção

// ─── Geração de segredo ───────────────────────────────────────────────────────

/**
 * Gera um segredo aleatório em Base32 (16 caracteres = 80 bits)
 */
function totpGenerateSecret(): string {
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

/**
 * Salva o segredo para o superadmin
 */
function totpSaveSecret(string $secret): void {
    $dir = dirname(TOTP_SECRET_FILE);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents(TOTP_SECRET_FILE, $secret);
    chmod(TOTP_SECRET_FILE, 0600);
}

/**
 * Carrega o segredo salvo (ou null se não configurado)
 */
function totpLoadSecret(): ?string {
    if (!file_exists(TOTP_SECRET_FILE)) return null;
    return trim(file_get_contents(TOTP_SECRET_FILE));
}

// ─── Geração e validação de código ────────────────────────────────────────────

/**
 * Decodifica Base32 → bytes binários
 */
function base32Decode(string $base32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32    = strtoupper($base32);
    $buffer    = 0;
    $bitsLeft  = 0;
    $result    = '';

    for ($i = 0; $i < strlen($base32); $i++) {
        $val = strpos($alphabet, $base32[$i]);
        if ($val === false) continue;
        $buffer   = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $result   .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $result;
}

/**
 * Gera o código TOTP para um determinado intervalo de tempo
 */
function totpGenerateCode(string $secret, int $timeSlot): string {
    $key     = base32Decode($secret);
    $time    = pack('N*', 0, $timeSlot);           // 8 bytes big-endian
    $hash    = hash_hmac('sha1', $time, $key, true);
    $offset  = ord($hash[19]) & 0xF;
    $code    = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
         (ord($hash[$offset + 3]) & 0xFF)
    ) % (10 ** TOTP_DIGITS);
    return str_pad((string)$code, TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Valida o código digitado pelo usuário
 */
function totpVerifyCode(string $secret, string $code): bool {
    $currentSlot = (int)floor(time() / TOTP_PERIOD);
    for ($delta = -TOTP_WINDOW; $delta <= TOTP_WINDOW; $delta++) {
        if (hash_equals(totpGenerateCode($secret, $currentSlot + $delta), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Retorna URL para gerar QR Code via Google Charts API (ou use biblioteca local)
 */
function totpGetQrCodeUrl(string $secret, string $accountLabel = 'superadmin'): string {
    $issuer  = urlencode(TOTP_ISSUER);
    $label   = urlencode(TOTP_ISSUER . ':' . $accountLabel);
    $uri     = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&digits=" . TOTP_DIGITS . "&period=" . TOTP_PERIOD;
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri);
}

// ─── Verificação de sessão 2FA ────────────────────────────────────────────────

/**
 * Chama isto NO TOPO de cada página do superadmin.php
 * Redireciona para tela de 2FA se não validado ainda
 */
function require2FA(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Já autenticou com 2FA nesta sessão?
    if (!empty($_SESSION['2fa_verified'])) return;

    // Redireciona para verificação
    header('Location: /superadmin_2fa.php');
    exit;
}

// ─── EXEMPLO DE PÁGINA superadmin_2fa.php ────────────────────────────────────
/*
<?php
session_start();
require 'security/3_2fa.php';

$secret = totpLoadSecret();
$error  = '';

// Primeira vez: gera e salva segredo, mostra QR Code
if (!$secret) {
    $secret = totpGenerateSecret();
    totpSaveSecret($secret);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (totpVerifyCode($secret, $code)) {
        $_SESSION['2fa_verified'] = true;
        header('Location: /superadmin.php');
        exit;
    }
    $error = 'Código inválido. Tente novamente.';
}
?>
<h2>Verificação em 2 Etapas</h2>
<?php if ($error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (!$_SESSION['2fa_configured'] ?? false): ?>
    <p>Escaneie o QR Code no Google Authenticator:</p>
    <img src="<?= totpGetQrCodeUrl($secret) ?>" alt="QR Code 2FA">
    <p>Ou insira manualmente: <strong><?= $secret ?></strong></p>
<?php endif; ?>

<form method="POST">
    <input type="text" name="code" placeholder="Código de 6 dígitos"
           maxlength="6" autocomplete="one-time-code" required>
    <button type="submit">Verificar</button>
</form>
*/
