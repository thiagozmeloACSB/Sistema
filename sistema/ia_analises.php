<?php
/**
 * ia_analises.php — Controle de uso do módulo Inteligência IA
 * Estrutura flat: todos os arquivos na raiz de /sistema/
 */

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (empty($_SESSION['uid'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado.']);
    exit;
}

$db           = getDB();
$input        = json_decode(file_get_contents('php://input'), true) ?? [];
$action       = $input['action'] ?? ($_GET['action'] ?? '');
$uid          = (int) $_SESSION['uid'];
$role         = $_SESSION['role'] ?? '';
$isSuperAdmin = ($role === 'superadmin');

// ── Helpers ───────────────────────────────────────────────────

function iaGetAgenciaId($db, $uid) {
    $st = $db->prepare("SELECT agencia_id FROM usuarios WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['agencia_id'] : 0;
}

function iaGetPlano($db, $agencia_id) {
    $st = $db->prepare("SELECT plano FROM agencias WHERE id = ? LIMIT 1");
    $st->execute([$agencia_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? strtolower($row['plano']) : 'start';
}

function iaGetConfigPlano($db, $plano) {
    $st = $db->prepare("SELECT * FROM ia_config_planos WHERE plano = ? LIMIT 1");
    $st->execute([$plano]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['analises_mes' => 0, 'credito_preco' => 4.90];
}

function iaGetUsoMes($db, $agencia_id) {
    $st = $db->prepare("
        SELECT COUNT(*) FROM ia_analises_log
        WHERE agencia_id = ?
          AND tipo_consumo = 'mensal'
          AND YEAR(criado_em)  = YEAR(NOW())
          AND MONTH(criado_em) = MONTH(NOW())
    ");
    $st->execute([$agencia_id]);
    return (int)$st->fetchColumn();
}

function iaGetCreditos($db, $agencia_id) {
    $st = $db->prepare("SELECT saldo FROM ia_creditos WHERE agencia_id = ? LIMIT 1");
    $st->execute([$agencia_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['saldo'] : 0;
}

function respJ($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Actions ───────────────────────────────────────────────────

switch ($action) {

    // Verificar se pode gerar + status atual
    case 'verificar':
        $ag   = iaGetAgenciaId($db, $uid);
        if (!$ag) respJ(['erro' => 'Agência não encontrada.'], 400);

        $plano  = iaGetPlano($db, $ag);
        $config = iaGetConfigPlano($db, $plano);
        $uso    = iaGetUsoMes($db, $ag);
        $limite = (int)$config['analises_mes'];
        $cred   = iaGetCreditos($db, $ag);
        $pode   = ($limite > 0 && $uso < $limite) || $cred > 0;

        respJ([
            'pode'          => $pode,
            'plano'         => $plano,
            'limite_mes'    => $limite,
            'uso_mes'       => $uso,
            'restante_mes'  => max(0, $limite - $uso),
            'creditos'      => $cred,
            'credito_preco' => (float)$config['credito_preco'],
            'tipo_consumo'  => ($limite > 0 && $uso < $limite) ? 'mensal' : 'credito',
        ]);
        break;

    // Registrar uso após análise gerada
    case 'registrar':
        $ag   = iaGetAgenciaId($db, $uid);
        if (!$ag) respJ(['erro' => 'Agência não encontrada.'], 400);

        $plano  = iaGetPlano($db, $ag);
        $config = iaGetConfigPlano($db, $plano);
        $uso    = iaGetUsoMes($db, $ag);
        $limite = (int)$config['analises_mes'];
        $cred   = iaGetCreditos($db, $ag);

        if ($limite > 0 && $uso < $limite) {
            $tipo = 'mensal';
        } elseif ($cred > 0) {
            $tipo = 'credito';
        } else {
            respJ(['erro' => 'Limite atingido. Adquira créditos para continuar.', 'sem_limite' => true], 403);
        }

        $db->prepare("
            INSERT INTO ia_analises_log
                (agencia_id, tipo_consumo, tipos_analise, cliente_nome, tokens_input, tokens_output)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $ag, $tipo,
            $input['tipos_analise'] ?? '',
            $input['cliente_nome']  ?? '',
            (int)($input['tokens_input']  ?? 0),
            (int)($input['tokens_output'] ?? 0),
        ]);

        if ($tipo === 'credito') {
            $db->prepare("
                INSERT INTO ia_creditos (agencia_id, saldo, total_comprado)
                VALUES (?, 0, 0)
                ON DUPLICATE KEY UPDATE saldo = GREATEST(0, saldo - 1)
            ")->execute([$ag]);
        }

        respJ([
            'ok'           => true,
            'tipo_consumo' => $tipo,
            'uso_mes'      => iaGetUsoMes($db, $ag),
            'restante_mes' => max(0, $limite - iaGetUsoMes($db, $ag)),
            'creditos'     => iaGetCreditos($db, $ag),
        ]);
        break;

    // Superadmin: relatório + config planos
    case 'relatorio':
        if (!$isSuperAdmin) respJ(['erro' => 'Sem permissão.'], 403);

        $uso = $db->query("
            SELECT
                a.id as agencia_id, a.nome AS agencia, a.plano,
                COUNT(CASE WHEN l.tipo_consumo='mensal'  THEN 1 END) AS uso_mensal,
                COUNT(CASE WHEN l.tipo_consumo='credito' THEN 1 END) AS uso_credito,
                COUNT(*) AS total,
                COALESCE(ic.saldo, 0) AS creditos_saldo
            FROM ia_analises_log l
            JOIN agencias a ON a.id = l.agencia_id
            LEFT JOIN ia_creditos ic ON ic.agencia_id = l.agencia_id
            WHERE YEAR(l.criado_em) = YEAR(NOW())
              AND MONTH(l.criado_em) = MONTH(NOW())
            GROUP BY l.agencia_id
            ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $config = $db->query("SELECT * FROM ia_config_planos ORDER BY FIELD(plano,'start','pro','agencia')")
                     ->fetchAll(PDO::FETCH_ASSOC);

        respJ(['ok' => true, 'dados' => $uso, 'config_planos' => $config]);
        break;

    // Superadmin: salvar configurações dos planos
    case 'salvar_config':
        if (!$isSuperAdmin) respJ(['erro' => 'Sem permissão.'], 403);

        $planos = $input['planos'] ?? [];
        if (empty($planos)) respJ(['erro' => 'Nenhum plano enviado.'], 400);

        $st = $db->prepare("
            INSERT INTO ia_config_planos (plano, analises_mes, credito_preco)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                analises_mes  = VALUES(analises_mes),
                credito_preco = VALUES(credito_preco)
        ");
        foreach ($planos as $p) {
            $st->execute([$p['plano'], (int)$p['analises_mes'], (float)$p['credito_preco']]);
        }

        respJ(['ok' => true, 'msg' => 'Configurações salvas.']);
        break;

    // Superadmin: adicionar créditos
    case 'adicionar_creditos':
        if (!$isSuperAdmin) respJ(['erro' => 'Sem permissão.'], 403);

        $ag  = (int)($input['agencia_id'] ?? 0);
        $qtd = (int)($input['quantidade']  ?? 0);
        if (!$ag || $qtd < 1) respJ(['erro' => 'Dados inválidos.'], 400);

        $db->prepare("
            INSERT INTO ia_creditos (agencia_id, saldo, total_comprado)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                saldo          = saldo + VALUES(saldo),
                total_comprado = total_comprado + VALUES(total_comprado)
        ")->execute([$ag, $qtd, $qtd]);

        $db->prepare("
            INSERT INTO ia_creditos_historico
                (agencia_id, quantidade, adicionado_por, observacao)
            VALUES (?, ?, ?, ?)
        ")->execute([$ag, $qtd, $uid, $input['observacao'] ?? '']);

        respJ(['ok' => true, 'saldo_novo' => iaGetCreditos($db, $ag)]);
        break;

    default:
        respJ(['erro' => 'Ação inválida.'], 400);
}
