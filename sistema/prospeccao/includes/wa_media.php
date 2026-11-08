<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════
// Download de mídia recebida pelo WhatsApp (usado para transcrever notas de voz).
// O webhook NÃO traz o arquivo, só um media_id:
//   1. GET /{media-id}        → devolve uma URL temporária
//   2. GET naquela URL        → devolve os bytes, MAS exige o header Authorization (sem ele, 401).
// ═══════════════════════════════════════════════════════════
if (!defined('WA_MEDIA_MAX_BYTES')) define('WA_MEDIA_MAX_BYTES', 16 * 1024 * 1024);   // nota de voz comum tem < 1 MB; é só rede de proteção

if (!function_exists('wa_baixarMidia')) {

/** @return array ['ok'=>true,'bytes','mime'] ou ['ok'=>false,'erro'] */
function wa_baixarMidia(string $mediaId): array {
    $token = trim(getPConfig('meta_wpp_token'));
    if ($token === '')   return ['ok' => false, 'erro' => 'Token da Meta não configurado.'];
    if ($mediaId === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $mediaId)) return ['ok' => false, 'erro' => 'media_id inválido.'];
    $ver = getPConfig('meta_wpp_version') ?: 'v21.0';

    $ch = curl_init("https://graph.facebook.com/{$ver}/" . rawurlencode($mediaId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return ['ok' => false, 'erro' => "Meta não devolveu a URL da mídia (HTTP {$code})."];
    $meta = json_decode((string)$res, true) ?: [];
    $url  = (string)($meta['url'] ?? ''); $mime = (string)($meta['mime_type'] ?? '');
    if ($url === '') return ['ok' => false, 'erro' => 'Resposta da Meta sem campo url.'];
    // O Bearer só vai para hosts da própria Meta
    $host = (string)parse_url($url, PHP_URL_HOST);
    if (strncmp($url, 'https://', 8) !== 0 || !preg_match('/(^|\.)(facebook\.com|fbcdn\.net|whatsapp\.net|fbsbx\.com)$/i', $host)) return ['ok' => false, 'erro' => 'URL de mídia fora da Meta.'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_USERAGENT => 'OpaProspeccao/1.0']);
    $bytes = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
    if ($code !== 200 || !is_string($bytes) || $bytes === '') return ['ok' => false, 'erro' => "Falha ao baixar a mídia (HTTP {$code})."];
    if (strlen($bytes) > WA_MEDIA_MAX_BYTES) return ['ok' => false, 'erro' => 'Mídia grande demais.'];
    return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime !== '' ? $mime : $ctype];
}

}
