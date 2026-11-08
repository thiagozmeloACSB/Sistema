<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── PDO ────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['ok' => false, 'erro' => 'Falha na conexão com o banco']));
    }
    return $pdo;
}

// ── GETTERS ────────────────────────────────────────────────
function usuarioLogado(): ?array { return $_SESSION['usuario'] ?? null; }
function tenantAtual(): ?array   { return $_SESSION['tenant']  ?? null; }
function planoAtual(): ?array    { return $_SESSION['plano']   ?? null; }

function isSuperadmin(): bool { return ($_SESSION['usuario']['tipo'] ?? '') === 'superadmin'; }
function isAdmin(): bool      { return ($_SESSION['usuario']['tipo'] ?? '') === 'admin'; }
function isCliente(): bool    { return ($_SESSION['usuario']['tipo'] ?? '') === 'cliente'; }

// ── VERIFICAÇÕES DE ACESSO ─────────────────────────────────
function exigeLogin(string $tipo = ''): void {
    $u = usuarioLogado();
    if (!$u) { header('Location: login.php'); exit; }
    if ($tipo && $u['tipo'] !== $tipo) { header('Location: login.php?erro=acesso'); exit; }
}

function exigeTenantAtivo(): void {
    $t = tenantAtual();
    if (!$t) { header('Location: login.php'); exit; }
    if (in_array($t['status'], ['cancelado','suspenso'])) {
        header('Location: conta_suspensa.php'); exit;
    }
}

// ── LOGIN ─────────────────────────────────────────────────
function login(string $email, string $senha): bool {
    $st = db()->prepare("SELECT * FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1");
    $st->execute([strtolower(trim($email))]);
    $u = $st->fetch();
    if (!$u || !password_verify($senha, $u['senha_hash'])) return false;

    // Bloqueia login se e-mail não confirmado
    if (isset($u['email_verificado']) && !$u['email_verificado']) {
        // Sinaliza para login.php via session temporária
        session_start();
        $_SESSION['login_erro'] = 'email_nao_confirmado';
        return false;
    }

    $_SESSION['usuario'] = [
        'id'         => $u['id'],
        'nome'       => $u['nome'],
        'email'      => $u['email'],
        'tipo'       => $u['tipo'],
        'tenant_id'  => $u['tenant_id'],
        'cliente_id' => null,
    ];

    // Carrega tenant + plano
    if ($u['tenant_id']) {
        $st2 = db()->prepare("
            SELECT t.*, p.nome AS plano_nome, p.preco_centavos, p.max_clientes, p.max_criativos_mes
            FROM tenants t
            JOIN planos p ON p.id = t.plano_id
            WHERE t.id = ?
        ");
        $st2->execute([$u['tenant_id']]);
        $tenant = $st2->fetch();
        $_SESSION['tenant'] = $tenant;
        $_SESSION['plano']  = [
            'nome'              => $tenant['plano_nome'],
            'max_clientes'      => (int)$tenant['max_clientes'],
            'max_criativos_mes' => (int)$tenant['max_criativos_mes'],
        ];
    }

    // Se cliente, carrega cliente_id
    if ($u['tipo'] === 'cliente') {
        $c = db()->prepare("SELECT id FROM clientes WHERE usuario_id = ? LIMIT 1");
        $c->execute([$u['id']]);
        $cli = $c->fetch();
        $_SESSION['usuario']['cliente_id'] = $cli['id'] ?? null;
    }

    return true;
}

function logout(): void {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ── LIMITES DO PLANO ───────────────────────────────────────
function podeAdicionarCliente(): bool {
    $p = planoAtual();
    $t = tenantAtual();
    if (!$p || !$t) return false;
    if ($p['max_clientes'] === -1) return true;
    $count = db()->prepare("SELECT COUNT(*) FROM clientes WHERE tenant_id = ?");
    $count->execute([$t['id']]);
    return (int)$count->fetchColumn() < $p['max_clientes'];
}

function podeAdicionarCriativo(): bool {
    $p = planoAtual();
    $t = tenantAtual();
    if (!$p || !$t) return false;
    if ($p['max_criativos_mes'] === -1) return true;
    $count = db()->prepare("
        SELECT COUNT(*) FROM criativos
        WHERE tenant_id = ? AND mes = ? AND ano = ?
    ");
    $count->execute([$t['id'], date('n'), date('Y')]);
    return (int)$count->fetchColumn() < $p['max_criativos_mes'];
}

// ── RESPONSE ──────────────────────────────────────────────
function jsonOk(array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonErro(string $msg, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'erro' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
