<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════════
// Chamada à API do Claude (Anthropic Messages API) — cURL direto, sem Composer.
// Chave: pro_config 'claude_api_key' (Configurações). Modelo: pro_config 'claude_modelo'
// (padrão: o mesmo do DojoSystem, claude-opus-5-5). A requisição é a mesma do DojoSystem.
//
//   $r = claudeTexto($system, $pergunta, 600);
//   if ($r['ok']) echo $r['texto']; else echo $r['erro'];
// ═══════════════════════════════════════════════════════════════
if (!function_exists('claudeTexto')) {

/** Quem está chamando a IA (vendedor, mensagem inicial, pesquisa): o chamador define antes de usar. */
function iaContexto(string $tipo, ?int $prospectId = null, ?int $campanhaId = null): void {
    $GLOBALS['pro_ia_ctx'] = ['tipo' => $tipo, 'prospect_id' => $prospectId, 'campanha_id' => $campanhaId];
}

/** Grava os tokens de uma chamada bem-sucedida. Nunca derruba a resposta ao contato se falhar. */
function iaRegistrarUso(array $j): void {
    try {
        $u = $j['usage'] ?? null;
        if (!is_array($u)) return;
        $c = $GLOBALS['pro_ia_ctx'] ?? ['tipo' => 'outro', 'prospect_id' => null, 'campanha_id' => null];
        getDB()->prepare('INSERT INTO pro_ia_uso (criado_em, tipo, prospect_id, campanha_id, modelo, tokens_in, tokens_out, cache_leitura, cache_escrita) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([date('Y-m-d H:i:s'), $c['tipo'], $c['prospect_id'], $c['campanha_id'], mb_substr((string)($j['model'] ?? claudeModelo()), 0, 60),
                (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), (int)($u['cache_read_input_tokens'] ?? 0), (int)($u['cache_creation_input_tokens'] ?? 0)]);
    } catch (Throwable $e) { error_log('[claude_api] uso: ' . $e->getMessage()); }
}

function claudeModelo(): string { return trim(getPConfig('claude_modelo')) ?: 'claude-opus-5-5'; }

/**
 * Gera um texto curto. $esforco: 'low' (textos simples, mais rápido e
 * barato) a 'high'. Retorna ['ok'=>bool, 'texto'=>string, 'erro'=>string].
 */
function claudeTexto(string $system, $usuario, int $maxTokens = 1024, string $esforco = 'low', int $timeout = 45): array {   // $usuario: texto ou lista de blocos (imagens + texto)
    $chave = trim(getPConfig('claude_api_key'));
    if ($chave === '') return ['ok' => false, 'texto' => '', 'erro' => 'Chave da API do Claude não configurada (Configurações → Claude).'];

    $corpo = json_encode([
        'model'         => claudeModelo(),
        'max_tokens'    => $maxTokens,
        'system'        => $system,
        'messages'      => [['role' => 'user', 'content' => $usuario]],
        'output_config' => ['effort' => $esforco],
        // Se o filtro de segurança recusar, a API tenta outro modelo sozinha.
        'fallbacks'     => 'default',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
        $ch = curl_init(defined('PRO_CLAUDE_URL') ? PRO_CLAUDE_URL : 'https://api.anthropic.com/v1/messages');   // constante só para teste
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corpo,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,   // padrão 45 s, abaixo dos ~60 s do servidor web
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $chave,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: server-side-fallback-2026-07-01',
            ],
        ]);
        $res  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            if ($tentativa === 1) { sleep(2); continue; }
            return ['ok' => false, 'texto' => '', 'erro' => 'Sem conexão com a API do Claude: ' . $cerr];
        }
        $j = json_decode($res, true) ?: [];
        // 429 (limite) e 5xx/529 (sobrecarga): uma nova tentativa depois de 2 s
        if (($http === 429 || $http >= 500) && $tentativa === 1) { sleep(2); continue; }
        if ($http !== 200) {
            $msg = $j['error']['message'] ?? ('HTTP ' . $http);
            return ['ok' => false, 'texto' => '', 'erro' => ($http === 401 ? 'Chave da API do Claude inválida. ' : '') . $msg];
        }
        if (($j['stop_reason'] ?? '') === 'refusal') {
            return ['ok' => false, 'texto' => '', 'erro' => 'O Claude recusou este pedido.'];
        }
        iaRegistrarUso($j);   // a chamada custou, mesmo que o texto venha vazio
        $texto = '';
        foreach ($j['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $texto .= $b['text'];
        $texto = trim($texto);
        if ($texto === '') return ['ok' => false, 'texto' => '', 'erro' => 'Resposta vazia do Claude.'];
        return ['ok' => true, 'texto' => $texto, 'erro' => ''];
    }
    return ['ok' => false, 'texto' => '', 'erro' => 'Falha ao chamar a API do Claude.'];
}

/**
 * Texto + imagens (prints). $imagens = [['media_type'=>'image/jpeg','data'=>base64], ...].
 * Mesmo retorno de claudeTexto().
 */
function claudeTextoComImagens(string $system, string $usuario, array $imagens, int $maxTokens = 1500, string $esforco = 'low'): array {
    $blocos = [];
    foreach ($imagens as $im) $blocos[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $im['media_type'], 'data' => $im['data']]];
    $blocos[] = ['type' => 'text', 'text' => $usuario];
    return claudeTexto($system, $blocos, $maxTokens, $esforco, 55);
}

/**
 * Lê a resposta JSON da IA. O modelo às vezes escreve um JSON, comenta e escreve outro corrigido:
 * cada objeto é separado (respeitando aspas) e vale o ÚLTIMO válido que tenha $chave.
 * null se não houver — e resposta que ainda carrega pedaço de JSON nunca vai para o cliente.
 */
function iaJsonResposta(string $texto, string $chave = 'resposta'): ?array {
    $achado = null; $n = strlen($texto);
    for ($i = 0; $i < $n; $i++) {
        if ($texto[$i] !== '{') continue;
        $prof = 0; $str = false; $esc = false;
        for ($k = $i; $k < $n; $k++) {
            $ch = $texto[$k];
            if ($str) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $str = false; continue; }
            if ($ch === '"') $str = true;
            elseif ($ch === '{') $prof++;
            elseif ($ch === '}' && --$prof === 0) {
                $o = json_decode(substr($texto, $i, $k - $i + 1), true);
                if (is_array($o) && isset($o[$chave]) && is_string($o[$chave]) && trim($o[$chave]) !== '') $achado = $o;
                $i = $k;
                break;
            }
        }
    }
    if ($achado === null) return null;
    if (preg_match('/[{}]|"(acao|resposta|motivo)"\s*:/u', (string)$achado[$chave])) return null;
    return $achado;
}

/** Chama a IA e garante o formato: se vier fora do JSON, pede de novo uma vez. ['ok','obj','erro'] */
function iaPerguntarJson(string $system, string $usuario, int $maxTokens = 600, string $chave = 'resposta'): array {
    $r = claudeTexto($system, $usuario, $maxTokens, 'low');
    if (!$r['ok']) return ['ok' => false, 'obj' => null, 'erro' => $r['erro']];
    $o = iaJsonResposta((string)$r['texto'], $chave);
    if ($o !== null) return ['ok' => true, 'obj' => $o, 'erro' => ''];
    error_log('[claude_api] resposta fora do formato, pedindo de novo: ' . mb_substr((string)$r['texto'], 0, 300));
    $r2 = claudeTexto($system, $usuario . "\n\nATENÇÃO: responda com UM ÚNICO objeto JSON válido, já revisado, e nada mais — sem texto antes ou depois, sem comentários e sem correções.", $maxTokens, 'low');
    if (!$r2['ok']) return ['ok' => false, 'obj' => null, 'erro' => $r2['erro']];
    $o = iaJsonResposta((string)$r2['texto'], $chave);
    return $o !== null ? ['ok' => true, 'obj' => $o, 'erro' => ''] : ['ok' => false, 'obj' => null, 'erro' => 'a IA respondeu fora do formato duas vezes'];
}

}
