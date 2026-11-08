<?php
// ═══════════════════════════════════════════════════════════
// Anti-bot do cadastro (register.php)
// Camadas: honeypot · tempo mínimo de preenchimento · limite por IP ·
//          nome com letras · e-mail descartável/sem MX · Gmail com pontos/+tag ·
//          hCaptcha (chaves do 2_captcha.php). Falhas ficam em signup_attempts.
// ═══════════════════════════════════════════════════════════

const AB_MIN_SEGUNDOS   = 4;      // menos que isso para preencher = robô
const AB_MAX_SEGUNDOS   = 7200;   // formulário velho demais
const AB_LIMITE_HORA    = 5;      // tentativas por IP em 1 hora
const AB_LIMITE_DIA     = 12;     // tentativas por IP em 24 horas

const AB_DOMINIOS_BLOQ = [
    'mailinator.com','guerrillamail.com','guerrillamail.info','guerrillamailblock.com','sharklasers.com','grr.la',
    'tempmail.com','temp-mail.org','tempr.email','throwam.com','yopmail.com','trashmail.com','trashmail.me',
    'spam4.me','fakeinbox.com','dispostable.com','mailnull.com','spamgourmet.com','getairmail.com','filzmail.com',
    'discard.email','spamhereplease.com','mailexpire.com','10minutemail.com','maildrop.cc','mailnesia.com',
    'gismail.online','rambler.ru','mail.ru','bk.ru','list.ru','inbox.ru',
];

function ab_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

function ab_tabela(): void {
    static $ok = false; if ($ok) return;
    db()->exec("CREATE TABLE IF NOT EXISTS signup_attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL,
        email VARCHAR(191) NOT NULL DEFAULT '',
        motivo VARCHAR(40) NOT NULL DEFAULT 'ok',
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_ip_tempo (ip, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

function ab_log(string $email, string $motivo): void {
    try {
        ab_tabela();
        db()->prepare("INSERT INTO signup_attempts (ip,email,motivo) VALUES (?,?,?)")
            ->execute([ab_ip(), substr($email, 0, 191), $motivo]);
    } catch (Throwable $e) { error_log('[anti_bot] ' . $e->getMessage()); }
}

function ab_segredo(): string { return hash('sha256', (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|signup'); }

// Token do formulário: hora de abertura assinada (vai em campo hidden)
function ab_token(): string {
    $t = (string)time();
    return $t . '.' . hash_hmac('sha256', $t, ab_segredo());
}

// "Jo.hn+x@gmail.com" → "john@gmail.com" (Gmail ignora pontos e +tag)
function ab_email_norm(string $email): string {
    $email = strtolower(trim($email));
    if (strpos($email, '@') === false) return $email;
    [$l, $d] = explode('@', $email, 2);
    if ($d === 'gmail.com' || $d === 'googlemail.com') {
        $l = str_replace('.', '', explode('+', $l, 2)[0]);
        $d = 'gmail.com';
    }
    return $l . '@' . $d;
}

function ab_captcha_ativo(): bool {
    if (!defined('HCAPTCHA_SITE_KEY')) {
        $f = __DIR__ . '/2_captcha.php';
        if (is_file($f)) { ob_start(); require_once $f; ob_end_clean(); }   // só para ler as constantes
    }
    return defined('HCAPTCHA_SITE_KEY') && defined('HCAPTCHA_SECRET_KEY') && HCAPTCHA_SITE_KEY !== '' && HCAPTCHA_SECRET_KEY !== '';
}

function ab_captcha_html(): string {
    if (!ab_captcha_ativo()) return '';
    return '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>'
         . '<div class="h-captcha" data-sitekey="' . htmlspecialchars(HCAPTCHA_SITE_KEY, ENT_QUOTES) . '" data-theme="dark" style="margin:.5rem 0 1rem"></div>';
}

// true = passou (ou falha de configuração/rede: não trava cliente por erro nosso)
function ab_captcha_ok(string $resp): bool {
    if (!ab_captcha_ativo()) return true;
    if ($resp === '') return false;
    $ch = curl_init(defined('HCAPTCHA_VERIFY_URL') ? HCAPTCHA_VERIFY_URL : 'https://hcaptcha.com/siteverify');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => HCAPTCHA_SECRET_KEY, 'response' => $resp, 'remoteip' => ab_ip(), 'sitekey' => HCAPTCHA_SITE_KEY])]);
    $out = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($out === false || $http >= 500) { error_log('[anti_bot] hCaptcha indisponível — liberado'); return true; }
    $j = json_decode((string)$out, true);
    if (!empty($j['success'])) return true;
    $cod = (array)($j['error-codes'] ?? []);
    if (array_intersect($cod, ['missing-input-secret', 'invalid-input-secret', 'sitekey-secret-mismatch', 'invalid-or-missing-sitekey'])) {
        error_log('[anti_bot] hCaptcha mal configurado (' . implode(',', $cod) . ') — liberado'); return true;
    }
    return false;
}

/**
 * Retorna null se pode seguir, ou a mensagem de erro.
 * Deve ser chamado no POST, antes de qualquer INSERT.
 */
function ab_checar(array $p): ?string {
    $email = strtolower(trim((string)($p['email'] ?? '')));
    $erro = function (string $motivo, string $msg) use ($email) { ab_log($email, $motivo); return $msg; };
    $generico = 'Não foi possível concluir o cadastro. Recarregue a página e tente novamente.';

    // 1. Honeypot (campo invisível que humano não preenche)
    if (trim((string)($p['website'] ?? '')) !== '') return $erro('honeypot', $generico);

    // 2. Token de tempo
    $partes = explode('.', (string)($p['_ab'] ?? ''), 2);
    if (count($partes) !== 2 || !ctype_digit($partes[0]) || !hash_equals(hash_hmac('sha256', $partes[0], ab_segredo()), $partes[1]))
        return $erro('token_invalido', $generico);
    $dt = time() - (int)$partes[0];
    if ($dt < AB_MIN_SEGUNDOS)  return $erro('rapido_demais', $generico);
    if ($dt > AB_MAX_SEGUNDOS)  return $erro('form_expirado', 'O formulário expirou. Recarregue a página e tente novamente.');

    // 3. Limite por IP
    try {
        ab_tabela();
        $st = db()->prepare("SELECT SUM(criado_em > NOW() - INTERVAL 1 HOUR) h, COUNT(*) d FROM signup_attempts WHERE ip=? AND criado_em > NOW() - INTERVAL 1 DAY");
        $st->execute([ab_ip()]); $r = $st->fetch();
        if ((int)($r['h'] ?? 0) >= AB_LIMITE_HORA || (int)($r['d'] ?? 0) >= AB_LIMITE_DIA)
            return $erro('limite_ip', 'Muitas tentativas deste endereço. Tente novamente mais tarde.');
    } catch (Throwable $e) { error_log('[anti_bot] ' . $e->getMessage()); }

    // 4. Nomes precisam ter letras (os bots usam números: "822764")
    foreach (['nome_empresa' => 'o nome da empresa', 'nome' => 'seu nome'] as $campo => $rot) {
        if (!preg_match('/\p{L}/u', (string)($p[$campo] ?? ''))) return $erro('nome_sem_letras', 'Informe ' . $rot . ' (precisa conter letras).');
    }

    // 5. Domínio descartável / sem e-mail configurado
    $dom = strpos($email, '@') !== false ? substr(strrchr($email, '@'), 1) : '';
    if ($dom === '' || in_array($dom, AB_DOMINIOS_BLOQ, true))
        return $erro('dominio_bloqueado', 'Este e-mail não é permitido. Use um e-mail corporativo ou de provedor confiável.');
    $conhecidos = ['gmail.com','googlemail.com','hotmail.com','outlook.com','live.com','yahoo.com','yahoo.com.br','icloud.com','uol.com.br','bol.com.br','terra.com.br','msn.com'];
    if (!in_array($dom, $conhecidos, true) && function_exists('checkdnsrr') && !checkdnsrr($dom, 'MX') && !checkdnsrr($dom, 'A'))
        return $erro('sem_mx', 'Domínio do e-mail não encontrado. Verifique e tente novamente.');

    // 6. Gmail com pontos / +tag (mesma caixa postal)
    $norm = ab_email_norm($email);
    if (substr($norm, -10) === '@gmail.com') {
        $st = db()->prepare("SELECT id FROM usuarios
            WHERE SUBSTRING_INDEX(email,'@',-1) IN ('gmail.com','googlemail.com')
              AND REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(email,'@',1),'+',1),'.','') = ? LIMIT 1");
        $st->execute([substr($norm, 0, -10)]);
        if ($st->fetch()) return $erro('gmail_duplicado', 'Este e-mail já está cadastrado.');
    }

    // 7. hCaptcha
    if (!ab_captcha_ok((string)($p['h-captcha-response'] ?? ''))) return $erro('captcha', 'Confirme que você não é um robô e tente novamente.');

    ab_log($email, 'ok');
    return null;
}
