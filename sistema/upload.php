<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin()) jsonErro('Acesso negado', 403);

$tid        = tenantAtual()['id'] ?? 0;
$criativo_id = (int)($_POST['criativo_id'] ?? 0);

if (!$criativo_id) jsonErro('criativo_id obrigatório');

// Garante que o criativo pertence ao tenant
$ck = db()->prepare("SELECT id FROM criativos WHERE id = ? AND tenant_id = ?");
$ck->execute([$criativo_id, $tid]);
if (!$ck->fetch()) jsonErro('Criativo não encontrado', 404);

// Valida arquivo
if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    jsonErro('Nenhum arquivo enviado ou erro no upload');
}

$file     = $_FILES['arquivo'];
$maxSize  = 10 * 1024 * 1024; // 10MB
$allowed  = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($file['size'] > $maxSize)             jsonErro('Arquivo muito grande. Máximo: 10MB');
if (!in_array($mimeType, $allowed))       jsonErro('Formato inválido. Use JPG, PNG ou WebP');

// Cria diretório se não existir
$uploadDir = __DIR__ . '/uploads/criativos/' . $tid . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    // Protege o diretório
    file_put_contents($uploadDir . '.htaccess', "Options -Indexes\n");
}

// Remove arquivo anterior se existir
$old = db()->prepare("SELECT arquivo_url FROM criativos WHERE id = ?");
$old->execute([$criativo_id]);
$oldRow = $old->fetch();
if (!empty($oldRow['arquivo_url'])) {
    $oldPath = __DIR__ . '/' . ltrim($oldRow['arquivo_url'], '/');
    if (file_exists($oldPath)) unlink($oldPath);
}

// Gera nome único
$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$ext      = $ext === 'jpg' ? 'jpg' : ($ext === 'png' ? 'png' : 'webp');
$filename = 'criativo_' . $criativo_id . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    jsonErro('Falha ao salvar arquivo no servidor', 500);
}

$fileUrl = '/uploads/criativos/' . $tid . '/' . $filename;

// Salva no banco e muda status para pendente_revisao
db()->prepare("
    UPDATE criativos
    SET arquivo_url = ?, arquivo_nome = ?, revisao_status = 'aguardando',
        revisao_comentario = NULL, revisao_nota = NULL, revisao_data = NULL,
        status = 'producao', atualizado_em = NOW()
    WHERE id = ? AND tenant_id = ?
")->execute([$fileUrl, $file['name'], $criativo_id, $tid]);

jsonOk([
    'arquivo_url'  => $fileUrl,
    'arquivo_nome' => $file['name'],
    'msg'          => 'Arquivo enviado. Cliente pode revisar agora.',
]);
