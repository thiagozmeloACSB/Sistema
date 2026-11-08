<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents("php://input"), true) ?? [];
$u      = usuarioLogado();
$t      = tenantAtual();

if (!$u) jsonErro('Não autenticado', 401);

// Superadmin bypassa tenant checks
$tid = $t['id'] ?? null;

// ── HELPERS ───────────────────────────────────────────────
function todos_clientes_t(int $tid): array {
    $st = db()->prepare("
        SELECT c.*, u.nome, u.email
        FROM clientes c JOIN usuarios u ON u.id = c.usuario_id
        WHERE c.tenant_id = ? ORDER BY c.id DESC
    ");
    $st->execute([$tid]);
    return $st->fetchAll();
}

function todos_criativos_t(int $tid): array {
    $st = db()->prepare("SELECT * FROM criativos WHERE tenant_id = ? ORDER BY id DESC");
    $st->execute([$tid]);
    return $st->fetchAll();
}

function criativos_cliente(int $tid, int $clienteId): array {
    $st = db()->prepare("SELECT * FROM criativos WHERE tenant_id=? AND cliente_id=? ORDER BY id DESC");
    $st->execute([$tid, $clienteId]);
    return $st->fetchAll();
}

// ── CLIENTES ─────────────────────────────────────────────
if ($action === 'clientes_lista') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    jsonOk(['clientes' => todos_clientes_t($tid)]);
}

if ($action === 'cliente_create') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    if (!podeAdicionarCliente()) {
        jsonErro('Limite de clientes do plano atingido. Faça upgrade para continuar.', 403);
    }
    $nome_empresa = trim($body['nome_empresa'] ?? '');
    $segmento     = trim($body['segmento'] ?? '');
    $nome         = trim($body['nome'] ?? '');
    $email        = strtolower(trim($body['email'] ?? ''));
    $senha        = $body['senha'] ?? '';
    $cor          = $body['cor'] ?? '#e8ff47';

    if (!$nome_empresa || !$email) jsonErro('Empresa e e-mail são obrigatórios');
    if (strlen($senha) < 6) jsonErro('Senha mínima de 6 caracteres');

    $ck = db()->prepare("SELECT id FROM usuarios WHERE email=?");
    $ck->execute([$email]);
    if ($ck->fetch()) jsonErro('E-mail já cadastrado');

    db()->beginTransaction();
    try {
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        db()->prepare("INSERT INTO usuarios (tenant_id,nome,email,senha_hash,tipo,email_verificado) VALUES (?,?,?,?,'cliente',1)")
            ->execute([$tid, $nome ?: $nome_empresa, $email, $hash]);
        $uid = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO clientes (tenant_id,usuario_id,nome_empresa,segmento,cor) VALUES (?,?,?,?,?)")
            ->execute([$tid, $uid, $nome_empresa, $segmento, $cor]);
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        jsonErro('Erro ao criar cliente: ' . $e->getMessage(), 500);
    }

    // Envia e-mail de boas-vindas para o cliente com dados de acesso
    try {
        require_once __DIR__ . '/mailer.php';
        $nome_cliente  = $nome ?: $nome_empresa;
        $nome_agencia  = $t['nome_empresa'] ?? 'sua agência';
        mailer_cliente_acesso($email, $nome_cliente, $nome_empresa, $senha, $nome_agencia);
    } catch (Exception $e) { /* não bloqueia */ }

    jsonOk(['clientes' => todos_clientes_t($tid)]);
}

if ($action === 'cliente_update') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $id = (int)($body['id'] ?? 0);
    // Garante que pertence ao tenant
    $ck = db()->prepare("SELECT * FROM clientes WHERE id=? AND tenant_id=?");
    $ck->execute([$id, $tid]);
    $cli = $ck->fetch();
    if (!$cli) jsonErro('Cliente não encontrado', 404);

    db()->prepare("UPDATE clientes SET nome_empresa=?,segmento=?,cor=? WHERE id=?")
        ->execute([trim($body['nome_empresa']??''), trim($body['segmento']??''), $body['cor']??'#e8ff47', $id]);
    if (!empty($body['nome'])) {
        db()->prepare("UPDATE usuarios SET nome=? WHERE id=?")->execute([trim($body['nome']), $cli['usuario_id']]);
    }
    jsonOk(['clientes' => todos_clientes_t($tid)]);
}

if ($action === 'cliente_delete') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $id = (int)($body['id'] ?? 0);
    $ck = db()->prepare("SELECT usuario_id FROM clientes WHERE id=? AND tenant_id=?");
    $ck->execute([$id, $tid]);
    $cli = $ck->fetch();
    if ($cli) {
        db()->prepare("DELETE FROM usuarios WHERE id=?")->execute([$cli['usuario_id']]);
    }
    jsonOk(['clientes' => todos_clientes_t($tid)]);
}

// ── CRIATIVOS (admin) ─────────────────────────────────────
if ($action === 'criativos_admin') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    jsonOk(['criativos' => todos_criativos_t($tid)]);
}

if ($action === 'criativo_save') {
    $cliente_id = (int)($body['cliente_id'] ?? 0);
    if (isCliente()) {
        $cliente_id = (int)($u['cliente_id'] ?? 0);
        $myTid      = (int)($u['tenant_id'] ?? 0);
    } else {
        $myTid = $tid;
    }
    if (!podeAdicionarCriativo()) {
        jsonErro('Limite de criativos do mês atingido. Faça upgrade para continuar.', 403);
    }
    $titulo     = trim($body['titulo'] ?? '');
    $tipo       = trim($body['tipo'] ?? 'Arte');
    $plataforma = trim($body['plataforma'] ?? '');
    $obs        = trim($body['observacao'] ?? '');
    if (!$titulo || !$plataforma || !$cliente_id) jsonErro('Campos obrigatórios faltando');

    db()->prepare("INSERT INTO criativos (tenant_id,cliente_id,titulo,tipo,plataforma,status,observacao,mes,ano)
                   VALUES (?,?,?,?,?,'pendente',?,?,?)")
        ->execute([$myTid,$cliente_id,$titulo,$tipo,$plataforma,$obs,date('n'),date('Y')]);

    $lista = isAdmin() ? todos_criativos_t($myTid) : criativos_cliente($myTid, $cliente_id);
    jsonOk(['criativos' => $lista]);
}

if ($action === 'criativo_update') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $id = (int)($body['id'] ?? 0);
    $ck = db()->prepare("SELECT id FROM criativos WHERE id=? AND tenant_id=?");
    $ck->execute([$id, $tid]);
    if (!$ck->fetch()) jsonErro('Criativo não encontrado', 404);

    db()->prepare("UPDATE criativos SET titulo=?,tipo=?,plataforma=?,status=?,observacao=?,cliente_id=? WHERE id=?")
        ->execute([trim($body['titulo']??''),$body['tipo']??'',$body['plataforma']??'',$body['status']??'pendente',trim($body['observacao']??''),(int)($body['cliente_id']??0),$id]);
    jsonOk(['criativos' => todos_criativos_t($tid)]);
}

if ($action === 'criativo_update_cliente') {
    if (!isCliente()) jsonErro('Acesso negado', 403);
    $id         = (int)($body['id'] ?? 0);
    $cliente_id = (int)($u['cliente_id'] ?? 0);
    $myTid      = (int)($u['tenant_id'] ?? 0);

    $ck = db()->prepare("SELECT id FROM criativos WHERE id=? AND cliente_id=? AND tenant_id=?");
    $ck->execute([$id, $cliente_id, $myTid]);
    if (!$ck->fetch()) jsonErro('Criativo não encontrado', 404);

    db()->prepare("UPDATE criativos SET titulo=?,tipo=?,plataforma=?,status=?,observacao=? WHERE id=? AND cliente_id=?")
        ->execute([trim($body['titulo']??''),$body['tipo']??'',$body['plataforma']??'',$body['status']??'pendente',trim($body['observacao']??''),$id,$cliente_id]);
    jsonOk(['criativos' => criativos_cliente($myTid, $cliente_id)]);
}

if ($action === 'status_update') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $id = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    if (!in_array($status, ['pendente','producao','finalizado','cancelado'])) jsonErro('Status inválido');
    $ck = db()->prepare("SELECT id FROM criativos WHERE id=? AND tenant_id=?");
    $ck->execute([$id, $tid]);
    if (!$ck->fetch()) jsonErro('Criativo não encontrado', 404);
    db()->prepare("UPDATE criativos SET status=? WHERE id=?")->execute([$status,$id]);
    jsonOk();
}

if ($action === 'status_update_cliente') {
    if (!isCliente()) jsonErro('Acesso negado', 403);
    $id = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    $cliente_id = (int)($u['cliente_id'] ?? 0);
    $myTid = (int)($u['tenant_id'] ?? 0);
    if (!in_array($status, ['pendente','producao','finalizado'])) jsonErro('Status inválido');
    $ck = db()->prepare("SELECT id FROM criativos WHERE id=? AND cliente_id=? AND tenant_id=?");
    $ck->execute([$id, $cliente_id, $myTid]);
    if (!$ck->fetch()) jsonErro('Criativo não encontrado', 404);
    db()->prepare("UPDATE criativos SET status=? WHERE id=?")->execute([$status,$id]);
    jsonOk(['criativos' => criativos_cliente($myTid, $cliente_id)]);
}

if ($action === 'criativo_delete') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $id = (int)($body['id'] ?? 0);
    $ck = db()->prepare("SELECT id FROM criativos WHERE id=? AND tenant_id=?");
    $ck->execute([$id, $tid]);
    if (!$ck->fetch()) jsonErro('Criativo não encontrado', 404);
    db()->prepare("DELETE FROM criativos WHERE id=?")->execute([$id]);
    jsonOk(['criativos' => todos_criativos_t($tid)]);
}

// ── CRIATIVOS (cliente) ───────────────────────────────────
if ($action === 'criativos') {
    if (!isCliente()) jsonErro('Acesso negado', 403);
    $clienteId = (int)($u['cliente_id'] ?? 0);
    $myTid     = (int)($u['tenant_id'] ?? 0);
    if (!$clienteId) jsonErro('Perfil de cliente não encontrado', 404);
    echo json_encode(criativos_cliente($myTid, $clienteId), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── INFO DO PLANO (para admin ver limites) ────────────────
if ($action === 'plano_info') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $p = planoAtual();
    $count_cli = (int)db()->prepare("SELECT COUNT(*) FROM clientes WHERE tenant_id=?")->execute([$tid]) ? db()->prepare("SELECT COUNT(*) FROM clientes WHERE tenant_id=?"): 0;
    $st_cli = db()->prepare("SELECT COUNT(*) FROM clientes WHERE tenant_id=?"); $st_cli->execute([$tid]);
    $st_cr  = db()->prepare("SELECT COUNT(*) FROM criativos WHERE tenant_id=? AND mes=? AND ano=?"); $st_cr->execute([$tid,date('n'),date('Y')]);
    jsonOk([
        'plano'          => $p,
        'clientes_usados'=> (int)$st_cli->fetchColumn(),
        'criativos_usados'=> (int)$st_cr->fetchColumn(),
        'tenant_status'  => $t['status'] ?? '',
        'trial_ends_at'  => $t['trial_ends_at'] ?? null,
    ]);
}


// ============================================================
// META ADS — endpoints (apenas admin)
// ============================================================

// Retorna configs de todos os clientes do tenant
// Inclui clientes SEM config (para exibir linha na tabela)
if ($action === 'meta_lista') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);

    // Recalcula status com base na data de expiração
    $configs_raw = db()->prepare("
        SELECT m.*, c.nome_empresa, c.cor
        FROM meta_configs m
        JOIN clientes c ON c.id = m.cliente_id
        WHERE m.tenant_id = ?
    ");
    $configs_raw->execute([$tid]);
    $configs = $configs_raw->fetchAll();

    // Atualiza status automaticamente
    foreach ($configs as &$cfg) {
        if (empty($cfg['access_token'])) {
            $cfg['token_status'] = 'nao_configurado';
        } elseif (!empty($cfg['token_expires_at'])) {
            $dias = (int)((strtotime($cfg['token_expires_at']) - time()) / 86400);
            if ($dias <= 0)  $cfg['token_status'] = 'expirado';
            elseif ($dias <= 10) $cfg['token_status'] = 'expirando';
            else $cfg['token_status'] = 'ok';
            // Persiste status atualizado
            db()->prepare("UPDATE meta_configs SET token_status=? WHERE id=?")
                ->execute([$cfg['token_status'], $cfg['id']]);
        }
    }
    unset($cfg);

    // Adiciona clientes sem config ainda (linha vazia para poder configurar)
    $cli_com_config = array_column($configs, 'cliente_id');
    $todos_cli = db()->prepare("SELECT id, nome_empresa, cor FROM clientes WHERE tenant_id=?");
    $todos_cli->execute([$tid]);
    foreach ($todos_cli->fetchAll() as $c) {
        if (!in_array($c['id'], $cli_com_config)) {
            $configs[] = [
                'cliente_id'     => $c['id'],
                'nome_empresa'   => $c['nome_empresa'],
                'cor'            => $c['cor'],
                'access_token'   => null,
                'ad_account_id'  => null,
                'pixel_id'       => null,
                'app_id'         => null,
                'token_expires_at' => null,
                'token_status'   => 'nao_configurado',
                'notas'          => null,
                'atualizado_em'  => null,
            ];
        }
    }

    jsonOk(['configs' => $configs]);
}

// Salva ou atualiza config Meta de um cliente
if ($action === 'meta_save') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);

    $cliente_id    = (int)($body['cliente_id'] ?? 0);
    $access_token  = trim($body['access_token'] ?? '');
    $expires_at    = $body['token_expires_at'] ?? null;
    $ad_account_id = trim($body['ad_account_id'] ?? '');
    $pixel_id      = trim($body['pixel_id'] ?? '');
    $app_id        = trim($body['app_id'] ?? '');
    $notas         = trim($body['notas'] ?? '');
    $page_id       = trim($body['page_id'] ?? '');
    $ig_business_id= trim($body['ig_business_id'] ?? '');

    if (!$cliente_id) jsonErro('cliente_id obrigatório');

    // Garante que cliente pertence ao tenant
    $ck = db()->prepare("SELECT id FROM clientes WHERE id=? AND tenant_id=?");
    $ck->execute([$cliente_id, $tid]);
    if (!$ck->fetch()) jsonErro('Cliente não encontrado', 404);

    // Calcula status
    $status = 'nao_configurado';
    if ($access_token) {
        if ($expires_at) {
            $dias = (int)((strtotime($expires_at) - time()) / 86400);
            $status = $dias <= 0 ? 'expirado' : ($dias <= 10 ? 'expirando' : 'ok');
        } else {
            $status = 'ok';
        }
    }

    $expires_val = $expires_at ?: null;

    // Upsert
    $existing = db()->prepare("SELECT id FROM meta_configs WHERE cliente_id=? AND tenant_id=?");
    $existing->execute([$cliente_id, $tid]);
    if ($existing->fetch()) {
        db()->prepare("UPDATE meta_configs SET
            access_token=?, token_expires_at=?, ad_account_id=?, pixel_id=?, app_id=?,
            token_status=?, notas=?, page_id=?, ig_business_id=?, atualizado_em=NOW()
            WHERE cliente_id=? AND tenant_id=?")
            ->execute([$access_token ?: null, $expires_val, $ad_account_id ?: null,
                       $pixel_id ?: null, $app_id ?: null, $status, $notas ?: null,
                       $page_id ?: null, $ig_business_id ?: null,
                       $cliente_id, $tid]);
    } else {
        db()->prepare("INSERT INTO meta_configs
            (tenant_id, cliente_id, access_token, token_expires_at, ad_account_id, pixel_id, app_id, token_status, notas, page_id, ig_business_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid, $cliente_id, $access_token ?: null, $expires_val,
                       $ad_account_id ?: null, $pixel_id ?: null, $app_id ?: null, $status, $notas ?: null,
                       $page_id ?: null, $ig_business_id ?: null]);
    }

    // Retorna lista atualizada (reutiliza lógica do meta_lista)
    $_GET['action'] = 'meta_lista';
    // Chama inline
    $configs_raw = db()->prepare("
        SELECT m.*, c.nome_empresa, c.cor
        FROM meta_configs m
        JOIN clientes c ON c.id = m.cliente_id
        WHERE m.tenant_id = ?
    ");
    $configs_raw->execute([$tid]);
    $configs = $configs_raw->fetchAll();
    $cli_com_config = array_column($configs, 'cliente_id');
    $todos_cli = db()->prepare("SELECT id, nome_empresa, cor FROM clientes WHERE tenant_id=?");
    $todos_cli->execute([$tid]);
    foreach ($todos_cli->fetchAll() as $c) {
        if (!in_array($c['id'], $cli_com_config)) {
            $configs[] = [
                'cliente_id' => $c['id'], 'nome_empresa' => $c['nome_empresa'],
                'cor' => $c['cor'], 'access_token' => null, 'ad_account_id' => null,
                'pixel_id' => null, 'app_id' => null, 'token_expires_at' => null,
                'token_status' => 'nao_configurado', 'notas' => null, 'atualizado_em' => null,
            ];
        }
    }
    jsonOk(['configs' => $configs]);
}


// ============================================================
// REVISÃO DO CRIATIVO (cliente)
// ============================================================
if ($action === 'criativo_revisar') {
    if (!isCliente()) jsonErro('Acesso negado', 403);
    $id          = (int)($body['id'] ?? 0);
    $revisao     = $body['revisao_status'] ?? '';
    $comentario  = trim($body['revisao_comentario'] ?? '');
    $nota        = isset($body['revisao_nota']) ? (int)$body['revisao_nota'] : null;
    $cliente_id  = (int)($u['cliente_id'] ?? 0);
    $myTid       = (int)($u['tenant_id'] ?? 0);

    $validos = ['aprovado', 'alteracao', 'reprovado'];
    if (!$id || !in_array($revisao, $validos)) jsonErro('Dados inválidos');
    if ($nota !== null && ($nota < 1 || $nota > 5)) jsonErro('Nota deve ser entre 1 e 5');

    // Garante que pertence ao cliente e tem arquivo
    $ck = db()->prepare("SELECT id FROM criativos WHERE id=? AND cliente_id=? AND tenant_id=? AND arquivo_url IS NOT NULL");
    $ck->execute([$id, $cliente_id, $myTid]);
    if (!$ck->fetch()) jsonErro('Criativo não encontrado ou sem arquivo para revisar', 404);

    // Atualiza status geral baseado na revisão
    $novoStatus = $revisao === 'aprovado' ? 'finalizado' : ($revisao === 'reprovado' ? 'cancelado' : 'producao');

    db()->prepare("
        UPDATE criativos
        SET revisao_status=?, revisao_comentario=?, revisao_nota=?,
            revisao_data=NOW(), status=?, atualizado_em=NOW()
        WHERE id=? AND cliente_id=? AND tenant_id=?
    ")->execute([$revisao, $comentario ?: null, $nota, $novoStatus, $id, $cliente_id, $myTid]);

    jsonOk(['criativos' => criativos_cliente($myTid, $cliente_id)]);
}

// ============================================================
// CRIATIVO — BRIEFING (cliente cria com briefing)
// ============================================================
if ($action === 'criativo_save_briefing') {
    if (!isCliente()) jsonErro('Acesso negado', 403);
    $cliente_id = (int)($u['cliente_id'] ?? 0);
    $myTid      = (int)($u['tenant_id'] ?? 0);

    if (!podeAdicionarCriativo()) jsonErro('Limite de criativos do mês atingido. Contate sua agência.', 403);

    $titulo     = trim($body['titulo'] ?? '');
    $tipo       = trim($body['tipo']   ?? 'Arte');
    $plataforma = trim($body['plataforma'] ?? '');
    $objetivo   = trim($body['briefing_objetivo'] ?? '');
    $publico    = trim($body['briefing_publico']   ?? '');
    $copy       = trim($body['briefing_copy']      ?? '');
    $refs       = trim($body['briefing_referencias'] ?? '');
    $cores      = trim($body['briefing_cores']     ?? '');

    if (!$titulo || !$plataforma) jsonErro('Título e plataforma são obrigatórios');

    db()->prepare("
        INSERT INTO criativos
        (tenant_id, cliente_id, titulo, tipo, plataforma, status,
         briefing_objetivo, briefing_publico, briefing_copy, briefing_referencias, briefing_cores,
         mes, ano)
        VALUES (?,?,?,?,?,'pendente',?,?,?,?,?,?,?)
    ")->execute([$myTid,$cliente_id,$titulo,$tipo,$plataforma,$objetivo,$publico,$copy,$refs,$cores,date('n'),date('Y')]);

    jsonOk(['criativos' => criativos_cliente($myTid, $cliente_id)]);
}

// ============================================================
// ADMIN — VER BRIEFING DE UM CRIATIVO
// ============================================================
if ($action === 'criativo_briefing') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare("SELECT * FROM criativos WHERE id=? AND tenant_id=?");
    $st->execute([$id, $tid]);
    $c = $st->fetch();
    if (!$c) jsonErro('Criativo não encontrado', 404);
    jsonOk(['criativo' => $c]);
}


// ============================================================
// META ANALYTICS + PUBLICAÇÃO
// ============================================================

if ($action === 'meta_analytics') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    require_once __DIR__ . '/meta_api.php';

    $cliente_id = (int)($_GET['cliente_id'] ?? 0);
    if (!$cliente_id) jsonErro('cliente_id obrigatório');

    // Busca config Meta do cliente
    $st = db()->prepare("SELECT * FROM meta_configs WHERE cliente_id = ? AND tenant_id = ?");
    $st->execute([$cliente_id, $tid]);
    $cfg = $st->fetch();

    if (!$cfg || empty($cfg['access_token'])) jsonErro('Token Meta não configurado para este cliente');

    $token  = $cfg['access_token'];
    $result = ['instagram' => null, 'facebook' => null, 'ads' => null];

    // Instagram
    $ig_id = $cfg['ig_business_id'];
    if (!$ig_id && !empty($cfg['page_id'])) {
        // Tenta descobrir automaticamente
        $ig_account = meta_instagram_account($token, $cfg['page_id']);
        if ($ig_account && !empty($ig_account['id'])) {
            $ig_id = $ig_account['id'];
            // Salva para próximas vezes
            db()->prepare("UPDATE meta_configs SET ig_business_id = ? WHERE cliente_id = ? AND tenant_id = ?")
                ->execute([$ig_id, $cliente_id, $tid]);
        }
    }

    if ($ig_id) {
        $igData = meta_instagram_insights($token, $ig_id);
        if (!isset($igData['error'])) {
            $result['instagram'] = $igData;
        } else {
            $result['instagram'] = ['error' => $igData['error']['message'] ?? 'Erro'];
        }
    }

    // Facebook Page
    if (!empty($cfg['page_id'])) {
        $fbData = meta_page_insights($token, $cfg['page_id']);
        if (!isset($fbData['error'])) {
            $result['facebook'] = $fbData;
        }
    }

    // Meta Ads
    if (!empty($cfg['ad_account_id'])) {
        $adsData = meta_ads_spend($token, $cfg['ad_account_id']);
        if (!isset($adsData['error'])) {
            $result['ads'] = $adsData;
        }
    }

    jsonOk($result);
}

if ($action === 'meta_publicar') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    require_once __DIR__ . '/meta_api.php';

    $cliente_id = (int)($body['cliente_id'] ?? 0);
    $plataforma = $body['plataforma'] ?? '';
    $caption    = trim($body['caption'] ?? '');
    $image_url  = trim($body['image_url'] ?? '');

    if (!$cliente_id || !$plataforma || !$caption) jsonErro('Dados obrigatórios faltando');
    if (!in_array($plataforma, ['instagram', 'facebook'])) jsonErro('Plataforma inválida');

    $st = db()->prepare("SELECT * FROM meta_configs WHERE cliente_id = ? AND tenant_id = ?");
    $st->execute([$cliente_id, $tid]);
    $cfg = $st->fetch();

    if (!$cfg || empty($cfg['access_token'])) jsonErro('Token não configurado');

    $token = $cfg['access_token'];
    $resultado = ['ok' => false, 'erro' => 'Plataforma não configurada'];

    if ($plataforma === 'instagram') {
        if (empty($cfg['ig_business_id'])) jsonErro('Instagram Business ID não configurado');
        if (!$image_url) jsonErro('Instagram requer uma URL de imagem');
        $resultado = meta_instagram_publish($token, $cfg['ig_business_id'], $image_url, $caption);
    } elseif ($plataforma === 'facebook') {
        if (empty($cfg['page_id'])) jsonErro('Page ID do Facebook não configurado');
        $resultado = meta_facebook_publish($token, $cfg['page_id'], $caption, $image_url ?: null);
    }

    // Registra no histórico
    $status   = $resultado['ok'] ? 'publicado' : 'erro';
    $erro_msg = $resultado['ok'] ? null : ($resultado['erro'] ?? null);
    db()->prepare("
        INSERT INTO publicacoes (tenant_id, cliente_id, plataforma, caption, image_url, status, post_id_meta, erro_msg, publicado_em)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([
        $tid, $cliente_id, $plataforma, $caption, $image_url ?: null,
        $status, $resultado['post_id'] ?? null, $erro_msg,
        $resultado['ok'] ? date('Y-m-d H:i:s') : null
    ]);

    if (!$resultado['ok']) {
        jsonErro($resultado['erro'] ?? 'Erro ao publicar');
    }
    jsonOk(['post_id' => $resultado['post_id'] ?? null]);
}

// Atualiza page_id e ig_business_id na meta_configs
if ($action === 'meta_save') {
    // Já existe — mas precisamos garantir que page_id e ig_business_id são salvos
    // O endpoint meta_save existente já cobre campos novos via UPSERT
}


// ============================================================
// IA — OpenAI
// ============================================================
if ($action === 'salvar_openai_key') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    $key = trim($body['key'] ?? '');
    if (!str_starts_with($key, 'sk-')) jsonErro('Chave inválida');
    db()->prepare("UPDATE tenants SET openai_key=? WHERE id=?")->execute([$key, $tid]);
    jsonOk();
}

function getOpenAIKey(int $tid): ?string {
    $st = db()->prepare("SELECT openai_key FROM tenants WHERE id=?");
    $st->execute([$tid]);
    return $st->fetchColumn() ?: null;
}

if ($action === 'ia_legenda') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    require_once __DIR__ . '/ia_helper.php';
    $key = getOpenAIKey($tid);
    if (!$key) jsonErro('Chave OpenAI não configurada');
    $r = ia_gerar_legenda($body, $key);
    if (!$r['ok']) jsonErro($r['erro']);
    jsonOk($r);
}

if ($action === 'ia_hashtags') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    require_once __DIR__ . '/ia_helper.php';
    $key = getOpenAIKey($tid);
    if (!$key) jsonErro('Chave OpenAI não configurada');
    $r = ia_gerar_hashtags($body, $key);
    if (!$r['ok']) jsonErro($r['erro']);
    jsonOk($r);
}

if ($action === 'ia_imagem') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    require_once __DIR__ . '/ia_helper.php';
    $key  = getOpenAIKey($tid);
    if (!$key) jsonErro('Chave OpenAI não configurada');
    $size = in_array($body['size']??'', ['1024x1024','1792x1024','1024x1792']) ? $body['size'] : '1024x1024';
    $r = ia_gerar_imagem($body['prompt'] ?? '', $key);
    if (!$r['ok']) jsonErro($r['erro']);
    jsonOk($r);
}

if ($action === 'ia_briefing') {
    if (!isAdmin()) jsonErro('Acesso negado', 403);
    require_once __DIR__ . '/ia_helper.php';
    $key = getOpenAIKey($tid);
    if (!$key) jsonErro('Chave OpenAI não configurada');
    $r = ia_analisar_briefing($body, $key);
    if (!$r['ok']) jsonErro($r['erro']);
    jsonOk($r);
}

// ── TEMA E TOUR ─────────────────────────────────────────────
if ($action === 'salvar_tema') {
    $tema = in_array($body['tema']??'', ['dark','light']) ? $body['tema'] : 'dark';
    db()->prepare("UPDATE usuarios SET tema=? WHERE id=?")->execute([$tema, $u['id']]);
    jsonOk();
}

if ($action === 'tour_concluido') {
    db()->prepare("UPDATE usuarios SET tour_concluido=1 WHERE id=?")->execute([$u['id']]);
    jsonOk();
}

jsonErro('Ação desconhecida', 404);
