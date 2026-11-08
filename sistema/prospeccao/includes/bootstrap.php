<?php
// ═══════════════════════════════════════════════════════════
// Prospecção Opa — bootstrap: configuração, banco, sessão, helpers
// Roda dentro do subdomínio sistema (sistema/prospeccao/): usa o config.php do sistema (banco e APP_URL)
// e a sessão do login dele — só entra quem está logado como SUPERADMIN.
// ═══════════════════════════════════════════════════════════
if (defined('PRO_BOOT')) return;
define('PRO_BOOT', 1);
define('PRO_ROOT', dirname(__DIR__));

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');
@ini_set('display_errors', '0');
error_reporting(E_ALL);
if (!is_dir(PRO_ROOT . '/logs')) @mkdir(PRO_ROOT . '/logs', 0755, true);
// Autocorreção das proteções: alguns extratores renomeiam ".htaccess" (ex.: "includes.htaccess").
// Se faltar o arquivo correto, ele é recriado aqui; os renomeados por engano são removidos.
(function () {
    $deny = "Require all denied\n";
    $raiz = "Options -Indexes\n<FilesMatch \"^\\.wa_inbound\\.lock$\">\n  Require all denied\n</FilesMatch>\n<FilesMatch \"\\.(log|md)$\">\n  Require all denied\n</FilesMatch>\n";
    foreach (['' => $raiz, '/includes' => $deny, '/pages' => $deny, '/logs' => $deny] as $sub => $conteudo) {
        $dir = PRO_ROOT . $sub;
        if (!is_dir($dir)) continue;
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', $conteudo);
    }
    foreach (['logs.htaccess', 'includes.htaccess', 'pages.htaccess', 'prospeccao.htaccess', 'includes/includes.htaccess', 'pages/pages.htaccess', 'logs/logs.htaccess'] as $f) {
        if (is_file(PRO_ROOT . '/' . $f)) @unlink(PRO_ROOT . '/' . $f);
    }
})();
@ini_set('log_errors', '1');
@ini_set('error_log', PRO_ROOT . '/logs/php_error.log');

require_once dirname(PRO_ROOT) . '/config.php';   // sistema/config.php: DB_*, APP_URL
require_once __DIR__ . '/schema.php';

// ── Banco ───────────────────────────────────────────────────
function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $db;
}

// ── Configuração chave → valor (tabela pro_config) ──────────
function &_pconfig_cache(): array { static $mem = []; return $mem; }
function getPConfig(string $chave, string $default = ''): string {
    $mem = &_pconfig_cache();
    if (array_key_exists($chave, $mem)) return $mem[$chave] === '' ? $default : $mem[$chave];
    try {
        $st = getDB()->prepare('SELECT valor FROM pro_config WHERE chave = ?');
        $st->execute([$chave]);
        $r = $st->fetchColumn();
        $mem[$chave] = $r !== false ? (string)$r : '';
    } catch (Throwable $e) { return $default; }
    return $mem[$chave] === '' ? $default : $mem[$chave];
}
function setPConfig(string $chave, string $valor): void {
    getDB()->prepare('INSERT INTO pro_config (chave, valor, atualizado_em) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = VALUES(atualizado_em)')
           ->execute([$chave, $valor, date('Y-m-d H:i:s')]);
    $mem = &_pconfig_cache();
    $mem[$chave] = $valor;
}

// Migrações do esquema: roda só quando a versão muda
try { if (getPConfig('schema_v') !== PRO_SCHEMA_V) proSchema(getDB()); } catch (Throwable $e) { error_log('[pro] schema: ' . $e->getMessage()); }

// Sementes (antes feitas pelo instalador): tokens do webhook/cron e frase de saída — uma vez só
try {
    if (getPConfig('seed_v') !== '1') {
        if (getPConfig('wa_webhook_token') === '') setPConfig('wa_webhook_token', bin2hex(random_bytes(16)));
        if (getPConfig('wa_cron_token') === '')    setPConfig('wa_cron_token', bin2hex(random_bytes(16)));
        setPConfig('msg_saida', 'Se preferir não receber mensagens, é só me avisar.');
        setPConfig('seed_v', '1');
    }
} catch (Throwable $e) { error_log('[pro] seed: ' . $e->getMessage()); }

// ── Sessão (a mesma do login do sistema: cookie PHPSESSID) ──
if (!defined('PRO_SEM_SESSAO') && php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) session_start();

// ── Helpers de saída / segurança ────────────────────────────
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfToken(): string {
    if (empty($_SESSION['pro_csrf'])) $_SESSION['pro_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['pro_csrf'];
}
function csrfCheck($t): bool { return is_string($t) && $t !== '' && hash_equals((string)($_SESSION['pro_csrf'] ?? ''), $t); }
function flash(string $msg, string $tipo = 'ok'): void { $_SESSION['pro_flash'] = [$msg, $tipo]; }
function flashTake(): ?array { $f = $_SESSION['pro_flash'] ?? null; unset($_SESSION['pro_flash']); return $f; }
function redirect(string $url): void { while (ob_get_level()) ob_end_clean(); header('Location: ' . $url); exit; }
function proJson(array $d): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
}
function proUrl(string $pagina, array $q = []): string {
    return 'index.php?' . http_build_query(['p' => $pagina] + $q);
}
function proBaseUrl(): string {
    if (defined('APP_URL') && APP_URL !== '') return rtrim(APP_URL, '/') . '/prospeccao';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}
function proAgora(): string { return date('Y-m-d H:i:s'); }

// ── Acesso: só superadmin ───────────────────────────────────
function requireLogin(bool $json = false): void {
    if (($_SESSION['usuario']['tipo'] ?? '') === 'superadmin') return;   // sessão criada por sistema/auth.php
    if ($json) { http_response_code(401); proJson(['ok' => false, 'erro' => 'Sessão expirada. Entre de novo.']); }
    redirect(rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/login.php?erro=acesso');
}

// ── Telefone / texto ────────────────────────────────────────
/** Telefone brasileiro só com dígitos, com 55 na frente. '' se inválido. */
function proTel(string $t): string {
    $d = preg_replace('/\D/', '', $t);
    if (strlen($d) >= 12 && str_starts_with($d, '55')) return $d;
    if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
    return strlen($d) >= 10 ? $d : '';
}
/** O número com e sem o 9º dígito (o WhatsApp antigo e o novo são o mesmo contato). */
function proTelVariantes(string $t): array {
    $v = [$t];
    if (preg_match('/^55(\d{2})(\d{8,9})$/', $t, $m)) {
        if (strlen($m[2]) === 9 && $m[2][0] === '9') $v[] = '55' . $m[1] . substr($m[2], 1);
        if (strlen($m[2]) === 8) $v[] = '55' . $m[1] . '9' . $m[2];
    }
    return $v;
}
/** Linhas de um campo de texto numa linha só (ex.: lista de dores separada por "; "). */
function proLinhas(string $t, string $sep = '; '): string {
    return implode($sep, array_filter(array_map('trim', preg_split('/\r?\n/', $t)), fn($x) => $x !== ''));
}
/** Texto comparável: minúsculo, sem acento, sem espaço sobrando. */
function proNorm(string $t): string {
    $t = mb_strtolower(trim($t));
    $t = strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e','í'=>'i','î'=>'i','ì'=>'i','ï'=>'i',
                    'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ú'=>'u','û'=>'u','ù'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',"'"=>'','’'=>'']);
    return preg_replace('/\s+/', ' ', $t);
}

const PRO_UFS = ['AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal',
    'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
    'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
    'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins'];

const PRO_STATUS = ['novo' => '🆕 Novo', 'enviado' => '📤 Enviado', 'respondeu' => '💬 Respondeu', 'interessado' => '🔥 Interessado',
                    'cliente' => '🏆 Cliente', 'perdido' => '❌ Perdido', 'nao_contatar' => '🚫 Não contatar'];

// ── Campanhas ───────────────────────────────────────────────
function proCampanha(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM pro_campanhas WHERE id = ?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}
/**
 * Segmento (ramo/subtipo) do contato pelas palavras-chave da campanha.
 * Formato do campo "segmentos": uma linha por segmento — "Rótulo: palavra, palavra".
 */
function proSegmentoDetectar(string $texto, ?array $camp): string {
    if (!$camp || trim((string)$camp['segmentos']) === '') return '';
    $t = proNorm($texto);
    foreach (preg_split('/\r?\n/', (string)$camp['segmentos']) as $ln) {
        if (!str_contains($ln, ':')) continue;
        [$rot, $kws] = array_map('trim', explode(':', $ln, 2));
        if ($rot === '') continue;
        foreach (explode(',', $kws) as $kw) { $kw = proNorm($kw); if ($kw !== '' && str_contains($t, $kw)) return mb_substr($rot, 0, 80); }
    }
    return '';
}

// ── Lista permanente de bloqueio ────────────────────────────
function proBloquear(PDO $db, string $tel, string $tipo, string $nome = '', string $motivo = ''): void {
    if ($tel === '') return;
    $db->prepare("INSERT INTO pro_bloqueados (telefone, tipo, nome, motivo, criado_em) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), nome = VALUES(nome), motivo = VALUES(motivo)")
       ->execute([$tel, $tipo, mb_substr($nome, 0, 190) ?: null, mb_substr($motivo, 0, 190) ?: null, proAgora()]);
}
function proDesbloquear(PDO $db, string $tel): void {
    if ($tel === '') return;
    $in = implode(',', array_fill(0, count($v = proTelVariantes($tel)), '?'));
    $db->prepare("DELETE FROM pro_bloqueados WHERE telefone IN ($in)")->execute($v);
}

/** Muda o status de um contato e mantém a lista de bloqueio em dia. */
function proMudarStatus(PDO $db, int $id, string $novo): bool {
    if (!isset(PRO_STATUS[$novo])) return false;
    $st = $db->prepare('SELECT * FROM pro_prospects WHERE id = ?'); $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) return false;
    $agora = proAgora();
    $db->prepare('UPDATE pro_prospects SET status = ?, atualizado_em = ?, contatado_em = COALESCE(contatado_em, ?) WHERE id = ?')
       ->execute([$novo, $agora, $novo === 'enviado' ? $agora : null, $id]);
    if ($novo === 'nao_contatar') {
        $db->prepare('UPDATE pro_prospects SET ia_ativa = 0 WHERE id = ?')->execute([$id]);
        proBloquear($db, (string)$p['telefone'], 'nao_contatar', (string)$p['nome'], 'marcado no funil');
    } elseif ($p['status'] === 'nao_contatar' && $p['telefone']) {
        proDesbloquear($db, (string)$p['telefone']);
    }
    return true;
}

function proContatadosHoje(PDO $db, ?int $campanhaId = null): int {
    $sql = 'SELECT COUNT(*) FROM pro_prospects WHERE contatado_em >= ?' . ($campanhaId ? ' AND campanha_id = ?' : '');
    $st = $db->prepare($sql); $st->execute($campanhaId ? [date('Y-m-d 00:00:00'), $campanhaId] : [date('Y-m-d 00:00:00')]);
    return (int)$st->fetchColumn();
}
