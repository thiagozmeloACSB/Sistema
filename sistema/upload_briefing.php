<?php
/**
 * upload_briefing.php
 * Recebe briefing + múltiplas imagens de referência do cliente
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isCliente()) jsonErro('Acesso negado', 403);
if (!podeAdicionarCriativo()) jsonErro('Limite de criativos do mês atingido.', 403);

$u          = usuarioLogado();
$cliente_id = (int)($u['cliente_id'] ?? 0);
$tenant_id  = (int)($u['tenant_id']  ?? 0);

$titulo     = trim($_POST['titulo']                ?? '');
$tipo       = trim($_POST['tipo']                  ?? 'Arte');
$plataforma = trim($_POST['plataforma']            ?? '');
$objetivo   = trim($_POST['briefing_objetivo']     ?? '');
$publico    = trim($_POST['briefing_publico']       ?? '');
$copy       = trim($_POST['briefing_copy']          ?? '');
$refs       = trim($_POST['briefing_referencias']   ?? '');
$cores      = trim($_POST['briefing_cores']         ?? '');

if (!$titulo || !$plataforma) jsonErro('Título e plataforma são obrigatórios');

// ── Insere criativo ────────────────────────────────────────
db()->prepare("
    INSERT INTO criativos
      (tenant_id, cliente_id, titulo, tipo, plataforma, status,
       briefing_objetivo, briefing_publico, briefing_copy,
       briefing_referencias, briefing_cores, mes, ano)
    VALUES (?,?,?,?,?,'pendente',?,?,?,?,?,?,?)
")->execute([
    $tenant_id, $cliente_id, $titulo, $tipo, $plataforma,
    $objetivo, $publico, $copy, $refs, $cores,
    date('n'), date('Y')
]);
$criativo_id = (int)db()->lastInsertId();

// ── Processa imagens de referência ─────────────────────────
$imagens_salvas = [];

if (!empty($_FILES['imagens'])) {
    // Suporta input name="imagens[]" (múltiplos) ou name="imagens" (único)
    $files_raw = $_FILES['imagens'];

    // Normaliza para array mesmo quando for único arquivo
    if (!is_array($files_raw['name'])) {
        $files_raw = [
            'name'     => [$files_raw['name']],
            'type'     => [$files_raw['type']],
            'tmp_name' => [$files_raw['tmp_name']],
            'error'    => [$files_raw['error']],
            'size'     => [$files_raw['size']],
        ];
    }

    $uploadDir = __DIR__ . '/uploads/briefings/' . $tenant_id . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        file_put_contents($uploadDir . '.htaccess', "Options -Indexes\n");
    }

    $maxSize = 100 * 1024 * 1024; // 100MB para vídeos // 30MB por arquivo
    $allowed = ['image/jpeg','image/jpg','image/png','image/webp','video/mp4','video/quicktime','video/webm'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);

    foreach ($files_raw['name'] as $i => $nome) {
        if ($files_raw['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files_raw['size'][$i] > $maxSize) continue;

        $tmp  = $files_raw['tmp_name'][$i];
        $mime = finfo_file($finfo, $tmp);
        if (!in_array($mime, $allowed)) continue;

        $ext_map = ['image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm'];
        $ext = $ext_map[$mime] ?? 'jpg';
        $filename = 'ref_' . $criativo_id . '_' . time() . '_' . $i . '.' . $ext;
        $dest     = $uploadDir . $filename;

        if (move_uploaded_file($tmp, $dest)) {
            $imagens_salvas[] = '/uploads/briefings/' . $tenant_id . '/' . $filename;
        }
    }
    finfo_close($finfo);
}

// ── Salva URLs das imagens como JSON ───────────────────────
if (!empty($imagens_salvas)) {
    db()->prepare("UPDATE criativos SET briefing_imagens=? WHERE id=?")
        ->execute([json_encode($imagens_salvas), $criativo_id]);
}

// ── Retorna lista atualizada ───────────────────────────────
$st = db()->prepare("SELECT * FROM criativos WHERE tenant_id=? AND cliente_id=? ORDER BY id DESC");
$st->execute([$tenant_id, $cliente_id]);

jsonOk(['criativos' => $st->fetchAll(PDO::FETCH_ASSOC)]);
