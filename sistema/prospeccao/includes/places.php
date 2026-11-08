<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════
// Google Places (API "Places API (New)" → Text Search) + cidades do IBGE
// Chave: pro_config 'google_places_key'. Portado de pages_super/prospeccao.php.
// ═══════════════════════════════════════════════════════════

/**
 * Busca no Google Places. Até 60 resultados (3 páginas de 20).
 * @return array ['ok'=>bool, 'erro'=>string, 'lugares'=>[...]]
 */
function proBuscarGoogle(string $chave, string $consulta, int $max = 60): array {
    $campos = 'places.id,places.displayName,places.formattedAddress,places.addressComponents,places.nationalPhoneNumber,'
            . 'places.internationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount,places.businessStatus,places.googleMapsUri,nextPageToken';
    $lugares = []; $token = '';
    for ($pag = 0; $pag < 3 && count($lugares) < $max; $pag++) {
        $corpo = ['textQuery' => $consulta, 'languageCode' => 'pt-BR', 'regionCode' => 'BR', 'pageSize' => 20];
        if ($token !== '') $corpo['pageToken'] = $token;
        $ch = curl_init(defined('PRO_PLACES_URL') ? PRO_PLACES_URL : 'https://places.googleapis.com/v1/places:searchText');   // constante só para teste
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $chave, 'X-Goog-FieldMask: ' . $campos],
        ]);
        $res = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
        if ($res === false) return ['ok' => false, 'erro' => 'Sem conexão com o Google: ' . $cerr, 'lugares' => $lugares];
        $j = json_decode($res, true) ?: [];
        if ($http !== 200) {
            $msg = (string)($j['error']['message'] ?? ('HTTP ' . $http));
            if ($http === 403 || stripos($msg, 'not been used') !== false || stripos($msg, 'disabled') !== false)
                $msg = 'O Google recusou a chave: confira se a "Places API (New)" está ativada no projeto e se o faturamento está ligado. (' . $msg . ')';
            elseif ($http === 400 && stripos($msg, 'API key') !== false) $msg = 'Chave do Google inválida. (' . $msg . ')';
            return ['ok' => false, 'erro' => $msg, 'lugares' => $lugares];
        }
        foreach ($j['places'] ?? [] as $pl) $lugares[] = $pl;
        $token = (string)($j['nextPageToken'] ?? '');
        if ($token === '') break;
        usleep(300000);   // o token da próxima página leva um instante para valer
    }
    return ['ok' => true, 'erro' => '', 'lugares' => array_slice($lugares, 0, $max)];
}

/** Municípios do estado (IBGE), guardados por 30 dias. [] se o IBGE não responder. */
function proCidadesUF(string $uf): array {
    if (!isset(PRO_UFS[$uf])) return [];
    $cache = json_decode(getPConfig('ibge_mun_' . $uf), true);
    if (is_array($cache) && !empty($cache['lista']) && ($cache['em'] ?? 0) > time() - 30 * 86400) return $cache['lista'];
    $ch = curl_init('https://servicodados.ibge.gov.br/api/v1/localidades/estados/' . $uf . '/municipios?orderBy=nome');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
    $res = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = $http === 200 ? json_decode((string)$res, true) : null;
    if (!is_array($j) || !$j) return is_array($cache) ? ($cache['lista'] ?? []) : [];
    $lista = array_values(array_filter(array_map(fn($m) => (string)($m['nome'] ?? ''), $j)));
    setPConfig('ibge_mun_' . $uf, json_encode(['em' => time(), 'lista' => $lista], JSON_UNESCAPED_UNICODE));
    return $lista;
}

/** O lugar fica na cidade/UF escolhidas? (nome exato, sem acento/maiúscula) */
function proNaCidade(array $pl, array $end, string $cidade, string $uf): bool {
    $c = proNorm($cidade);
    $ufOk = $end['uf'] === '' || strtoupper($end['uf']) === $uf || proNorm($end['uf']) === proNorm(PRO_UFS[$uf] ?? '');
    if ($end['cidade'] !== '' && proNorm($end['cidade']) === $c && $ufOk) return true;
    // Endereço formatado do Google no Brasil: "..., São José - SC, 88101-000, Brasil"
    $fa = proNorm((string)($pl['formattedAddress'] ?? ''));
    return $fa !== '' && preg_match('/(^|, )' . preg_quote($c, '/') . ' - ' . strtolower($uf) . '(,|$)/', $fa) === 1;
}

/** Cidade, bairro e UF a partir de addressComponents do Places. */
function proEnderecoPartes(array $comps): array {
    $r = ['cidade' => '', 'bairro' => '', 'uf' => ''];
    foreach ($comps as $c) {
        $t = $c['types'] ?? [];
        if (in_array('administrative_area_level_2', $t, true) && $r['cidade'] === '') $r['cidade'] = (string)($c['longText'] ?? '');
        if (in_array('locality', $t, true)) $r['cidade'] = (string)($c['longText'] ?? '');
        if ((in_array('sublocality_level_1', $t, true) || in_array('sublocality', $t, true) || in_array('neighborhood', $t, true)) && $r['bairro'] === '') $r['bairro'] = (string)($c['longText'] ?? '');
        if (in_array('administrative_area_level_1', $t, true)) $r['uf'] = (string)($c['shortText'] ?? '');
    }
    return $r;
}
