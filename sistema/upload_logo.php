<?php
/**
 * upload_logo.php — Troca a logo do tenant
 * Aceita JPG, PNG, WebP, SVG — máx 2MB
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin()) jsonErro('Acesso negado', 403);

$tenant = tenantAtual();
$tid    = $tenant['id'];

if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    jsonErro('Nenhum arquivo recebido.');
}

$file    = $_FILES['logo'];
$maxSize = 2 * 1024 * 1024; // 2MB

if ($file['size'] > $maxSize) {
    jsonErro('Arquivo muito grande. Máximo: 2MB.');
}

// Valida tipo real via finfo
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mime     = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowed = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
];

if (!isset($allowed[$mime])) {
    jsonErro('Formato inválido. Use JPG, PNG, WebP ou SVG.');
}

$ext     = $allowed[$mime];
$dir     = __DIR__ . '/uploads/logos/';
$fname   = 'logo_tenant_' . $tid . '.' . $ext;
$dest    = $dir . $fname;
$url     = '/uploads/logos/' . $fname;

// Cria diretório se necessário
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    file_put_contents($dir . '.htaccess', "Options -Indexes\n");
}

// Remove logo anterior do mesmo tenant (qualquer extensão)
foreach (glob($dir . 'logo_tenant_' . $tid . '.*') as $old) {
    @unlink($old);
}

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    jsonErro('Falha ao salvar arquivo.');
}

// Salva no banco
db()->prepare("UPDATE tenants SET logo_url = ? WHERE id = ?")
    ->execute([$url, $tid]);

// Atualiza sessão
$_SESSION['tenant']['logo_url'] = $url;

jsonOk(['logo_url' => $url, 'cache_bust' => time()]);
