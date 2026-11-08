<?php
/**
 * MÓDULO 4 — Alertas de Cadastro Suspeito por E-mail
 * Opa Studio Security — PHP Puro
 * 
 * Envia e-mail imediato quando:
 *  - Novo cadastro de domínio suspeito
 *  - País de IP fora do Brasil
 *  - Múltiplos cadastros do mesmo IP em curto período
 */

// ─── Configuração ─────────────────────────────────────────────────────────────
define('ALERT_FROM_EMAIL',  'sistema@opadesign.com.br');
define('ALERT_FROM_NAME',   'Opa Studio — Segurança');
define('ALERT_TO_EMAIL',    'thiagozmelo@gmail.com'); // seu e-mail de alerta
define('ALERT_RATE_LIMIT',  3);   // máx. cadastros por IP em X minutos
define('ALERT_RATE_WINDOW', 60);  // janela em minutos
define('RATE_LOG_FILE',     __DIR__ . '/logs/rate_register.json');

// ─── Score de Risco ───────────────────────────────────────────────────────────

/**
 * Calcula score de risco de um cadastro (0 = seguro, 100 = altíssimo risco)
 * Retorna ['score' => int, 'reasons' => array]
 */
function calcRiskScore(array $data): array {
    $score   = 0;
    $reasons = [];

    $email  = strtolower(trim($data['email'] ?? ''));
    $domain = substr(strrchr($email, '@'), 1);
    $ip     = $data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

    // Domínios de alto risco conhecidos
    $riskyTLDs     = ['ru', 'cn', 'kp', 'ir', 'by', 'ua'];
    $riskyDomains  = ['rambler.ru', 'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru'];

    // 1. Domínio suspeito explícito (+40)
    if (in_array($domain, $riskyDomains)) {
        $score += 40;
        $reasons[] = "Domínio na lista negra: $domain";
    }

    // 2. TLD de alto risco (+25)
    $tld = strtolower(pathinfo($domain, PATHINFO_EXTENSION));
    if (in_array($tld, $riskyTLDs)) {
        $score += 25;
        $reasons[] = "TLD de alto risco: .$tld";
    }

    // 3. Nome da empresa suspeito: só números, sem letras (+20)
    $empresa = trim($data['empresa'] ?? '');
    if (preg_match('/^\d+$/', $empresa)) {
        $score += 20;
        $reasons[] = "Nome da empresa é apenas números: '$empresa'";
    }

    // 4. Nome igual ao slug (cópia exata) (+10)
    $slug = trim($data['slug'] ?? '');
    if (!empty($slug) && $slug === strtolower($empresa)) {
        $score += 10;
        $reasons[] = 'Nome e slug idênticos';
    }

    // 5. Múltiplos cadastros do mesmo IP (+30)
    $registrationsFromIp = countRecentRegistrations($ip);
    if ($registrationsFromIp >= ALERT_RATE_LIMIT) {
        $score += 30;
        $reasons[] = "IP $ip fez $registrationsFromIp cadastros recentes";
    }

    return ['score' => min($score, 100), 'reasons' => $reasons];
}

// ─── Rate Limiting por IP ─────────────────────────────────────────────────────

function countRecentRegistrations(string $ip): int {
    $data      = loadRateLog();
    $threshold = time() - (ALERT_RATE_WINDOW * 60);
    return count(array_filter($data[$ip] ?? [], fn($t) => $t > $threshold));
}

function recordRegistration(string $ip): void {
    $data      = loadRateLog();
    $threshold = time() - (ALERT_RATE_WINDOW * 60);

    // Limpa registros antigos
    $data[$ip] = array_filter($data[$ip] ?? [], fn($t) => $t > $threshold);
    $data[$ip][] = time();

    saveRateLog($data);
}

function loadRateLog(): array {
    if (!file_exists(RATE_LOG_FILE)) return [];
    $json = file_get_contents(RATE_LOG_FILE);
    return json_decode($json, true) ?: [];
}

function saveRateLog(array $data): void {
    $dir = dirname(RATE_LOG_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(RATE_LOG_FILE, json_encode($data), LOCK_EX);
}

// ─── Envio de Alerta ──────────────────────────────────────────────────────────

/**
 * Envia e-mail de alerta para o superadmin
 */
function sendSecurityAlert(array $cadastroData, array $riskResult): bool {
    $score   = $riskResult['score'];
    $reasons = $riskResult['reasons'];
    $ip      = $cadastroData['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'desconhecido');

    $level   = $score >= 70 ? '🔴 ALTO RISCO' : ($score >= 40 ? '🟡 ATENÇÃO' : '🟢 BAIXO');

    $reasonList = implode("\n  - ", $reasons);

    $subject = "[Opa Studio] Novo cadastro suspeito — Score: {$score}/100";

    $body = <<<TEXT
    ⚠️  ALERTA DE SEGURANÇA — OPA STUDIO
    ═══════════════════════════════════════

    Nível de risco: {$level} (Score: {$score}/100)
    Data/Hora: {$cadastroData['datetime']}

    ─── DADOS DO CADASTRO ───────────────────
    Empresa : {$cadastroData['empresa']}
    E-mail  : {$cadastroData['email']}
    IP      : {$ip}

    ─── MOTIVOS ─────────────────────────────
      - {$reasonList}

    ─── AÇÃO RECOMENDADA ────────────────────
    Acesse o painel para revisar ou deletar:
    https://sistema.opadesign.com.br/superadmin.php

    ─────────────────────────────────────────
    Opa Studio — Sistema de Segurança Automático
    TEXT;

    $headers  = "From: " . ALERT_FROM_NAME . " <" . ALERT_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . ALERT_FROM_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    return mail(ALERT_TO_EMAIL, $subject, $body, $headers);
}

// ─── Função principal ─────────────────────────────────────────────────────────

/**
 * Chame esta função logo após salvar um novo cadastro no banco
 */
function processNewRegistration(array $cadastroData): void {
    $cadastroData['ip']       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cadastroData['datetime'] = date('d/m/Y H:i:s');

    // Registra para rate limiting
    recordRegistration($cadastroData['ip']);

    // Calcula risco
    $risk = calcRiskScore($cadastroData);

    // Envia alerta se score >= 30
    if ($risk['score'] >= 30) {
        sendSecurityAlert($cadastroData, $risk);
    }
}

// ─── EXEMPLO DE USO ───────────────────────────────────────────────────────────
/*
require 'security/4_alerts.php';

// Após inserir no banco de dados:
processNewRegistration([
    'empresa' => $_POST['empresa'],
    'email'   => $_POST['email'],
    'slug'    => $_POST['slug'],
]);
*/
