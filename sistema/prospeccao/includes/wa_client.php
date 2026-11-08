<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// WhatsApp Cloud API — cliente HTTP (cURL puro). Só o que o Vendedor IA usa:
// descobrir os números da conta, assinar o webhook e consultar o estado do número.
if (!function_exists('waRequest')) {

function waGraphUrl(string $path): string {
    return 'https://graph.facebook.com/' . (getPConfig('meta_wpp_version') ?: 'v21.0') . '/' . ltrim($path, '/');
}

/** @return array [ok(bool), status(int), data(array)] */
function waRequest(string $method, string $path, array $params = [], ?string $bearer = null): array {
    $url = waGraphUrl($path);
    $ch  = curl_init();
    $headers = ['Accept: application/json'];
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
    if (strtoupper($method) === 'GET') {
        if ($params) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    } else {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
                            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
    $resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($resp === false) return ['ok' => false, 'status' => 0, 'data' => ['error' => ['message' => $cerr]]];
    $data = json_decode($resp, true);
    if (!is_array($data)) $data = ['raw' => $resp];
    return ['ok' => ($status >= 200 && $status < 300 && !isset($data['error'])), 'status' => $status, 'data' => $data];
}

/** GET /{waba_id}/phone_numbers */
function waListarNumeros(string $wabaId, string $bearer): array {
    return waRequest('GET', $wabaId . '/phone_numbers', ['fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status'], $bearer);
}

/** POST /{waba_id}/subscribed_apps — liga a conta aos webhooks do app. */
function waAssinarWebhook(string $wabaId, string $bearer): array {
    return waRequest('POST', $wabaId . '/subscribed_apps', [], $bearer);
}

}
