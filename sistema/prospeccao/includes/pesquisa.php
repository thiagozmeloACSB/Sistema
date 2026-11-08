<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════
// Pesquisa do contato (Prospecção › 🔎 Pesquisar) — portado do DojoSystem, adaptado para a Opa.
// Monta uma "ficha" do negócio a partir de material PÚBLICO:
//   • dados do Google já gravados (nota, avaliações, local, segmento);
//   • texto do site do negócio (lido pelo servidor, com proteção contra acesso à rede interna);
//   • prints do Instagram/Facebook que você anexa (as redes exigem login e bloqueiam robôs).
// O Claude lê tudo e devolve HIPÓTESES (porte, presença digital, como o negócio opera hoje, oportunidades)
// e ganchos de conversa. É só da EMPRESA: nada de identificar pessoas nem registrar menores.
// A ficha vai para pro_prospects.pesquisa (JSON) e o Vendedor IA a usa como hipótese, nunca como certeza.
// ═══════════════════════════════════════════════════════════
if (!function_exists('ppPesquisar')) {

/** true se o IP é público (nem privado, nem reservado, nem loopback). */
function ppIpPublico(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * URL http(s) em porta 80/443 cujo host resolve só para IP público (o servidor não acessa a rede interna).
 * Devolve o IP validado (para fixar na conexão e evitar troca de DNS entre a checagem e o acesso) ou null.
 */
function ppResolverSeguro(string $url): ?string {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return null;
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) return null;
    if (!empty($p['user']) || !empty($p['pass'])) return null;
    $host = trim($p['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) return ppIpPublico($host) ? $host : null;
    $ips = @gethostbynamel($host);
    if (!$ips) return null;
    foreach ($ips as $ip) if (!ppIpPublico($ip)) return null;
    return $ips[0];
}
function ppUrlSegura(string $url): bool { return ppResolverSeguro($url) !== null; }

/** Redes sociais não são lidas pelo servidor (login/bloqueio). */
function ppEhRedeSocial(string $url): bool {
    return (bool)preg_match('~(^|\.)((instagram|facebook|fb|tiktok|x|twitter|wa|whatsapp|linktr)\.(com|me|ee)|fb\.com|wa\.me)(/|$|:)~i', (string)parse_url($url, PHP_URL_HOST) . '/');
}

/** Extrai título, descrição, texto visível e links de redes de um HTML. */
function ppExtrairSite(string $html): array {
    $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
    $titulo = ''; $desc = '';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) $titulo = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    if (preg_match('~<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)~is', $html, $m)
        || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description~is', $html, $m)) $desc = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    $ig = ''; $fb = '';
    if (preg_match('~instagram\.com/([A-Za-z0-9_.]{2,30})(?:[/?"\'#]|$)~i', $html, $m) && !in_array(strtolower($m[1]), ['p', 'reel', 'explore', 'accounts', 'tv'], true)) $ig = '@' . $m[1];
    if (preg_match('~(https?://(?:www\.)?facebook\.com/[A-Za-z0-9_.\-/]{2,80})~i', $html, $m)) $fb = rtrim($m[1], '/');
    $sem = preg_replace(['~<(script|style|noscript|svg|iframe)\b.*?</\1>~is', '~<!--.*?-->~s'], ' ', $html);
    $sem = preg_replace('~</(p|div|li|h[1-6]|section|tr|br)>~i', "\n", $sem);
    $txt = html_entity_decode(strip_tags($sem), ENT_QUOTES, 'UTF-8');
    $txt = preg_replace('~[ \t\x{00A0}]+~u', ' ', $txt);
    $txt = preg_replace('~\s*\n\s*~', "\n", trim($txt));
    return ['titulo' => $titulo, 'descricao' => $desc, 'texto' => mb_substr($txt, 0, 7000), 'instagram' => $ig, 'facebook' => $fb];
}

/** Lê a página inicial do site (até 3 redirecionamentos; cada destino é validado e o IP validado é o que se usa). */
function ppBuscarSite(string $url): array {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'erro' => 'sem site'];
    if (!preg_match('~^https?://~i', $url)) $url = 'http://' . $url;
    if (ppEhRedeSocial($url)) return ['ok' => false, 'erro' => 'o "site" é uma rede social'];
    for ($i = 0; $i <= 3; $i++) {
        $ip = ppResolverSeguro($url);
        if ($ip === null) return ['ok' => false, 'erro' => 'endereço não permitido'];
        $pu = parse_url($url);
        $porta = (int)($pu['port'] ?? (strtolower($pu['scheme']) === 'https' ? 443 : 80));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8, CURLOPT_MAXFILESIZE => 1500000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [trim($pu['host'], '[]') . ':' . $porta . ':' . $ip],   // conecta no IP já validado
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OpaPesquisaBot/1.0; +https://opadesign.com.br)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: pt-BR,pt;q=0.9'],
        ]);
        $res = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $err = curl_error($ch); curl_close($ch);
        if ($res === false) return ['ok' => false, 'erro' => 'não abriu: ' . $err];
        $cab = substr($res, 0, $hs); $corpo = substr($res, $hs);
        if ($http >= 300 && $http < 400 && preg_match('~^Location:\s*(\S+)~im', $cab, $m)) {
            $dest = trim($m[1]);
            if (!preg_match('~^https?://~i', $dest)) {
                $b = parse_url($url);
                $dest = ($b['scheme'] ?? 'http') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '') . '/' . ltrim($dest, '/');
            }
            $url = $dest; continue;
        }
        if ($http !== 200) return ['ok' => false, 'erro' => 'o site respondeu HTTP ' . $http];
        $r = ppExtrairSite($corpo);
        if (mb_strlen($r['texto']) < 80) return ['ok' => false, 'erro' => 'o site quase não tem texto (pode ser feito só com imagens ou script)'] + $r;
        return ['ok' => true, 'erro' => '', 'url' => $url] + $r;
    }
    return ['ok' => false, 'erro' => 'redirecionamentos demais'];
}

/**
 * Prints anexados (campo prints[]): valida, reduz (GD) e devolve blocos base64
 * ['imgs' => [['media_type'=>..., 'data'=>...], ...], 'avisos' => [...]]. No máximo 4 imagens.
 * $ehUpload=false só nos testes (is_uploaded_file não vale para arquivo criado à mão).
 */
function ppImagens(array $files, bool $ehUpload = true): array {
    $out = []; $avisos = [];
    if (empty($files['name'])) return ['imgs' => [], 'avisos' => []];
    $n = is_array($files['name']) ? count($files['name']) : 1;
    for ($i = 0; $i < $n && count($out) < 4; $i++) {
        $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $nome = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK || ($ehUpload && !is_uploaded_file($tmp))) { $avisos[] = "$nome: falhou o envio"; continue; }
        if (filesize($tmp) > 10 * 1024 * 1024) { $avisos[] = "$nome: maior que 10 MB"; continue; }
        $raw = file_get_contents($tmp);
        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) { $avisos[] = "$nome: não é imagem JPG/PNG/WEBP/GIF"; continue; }
        $mt = image_type_to_mime_type($info[2]);
        $maior = max($info[0], $info[1]);
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && ($maior > 1568 || strlen($raw) > 3500000)) {
            $im = @imagecreatefromstring($raw);
            if ($im) {
                $esc = $maior > 1568 ? 1568 / $maior : 1;
                $nw = max(1, (int)round($info[0] * $esc)); $nh = max(1, (int)round($info[1] * $esc));
                $dst = imagecreatetruecolor($nw, $nh);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $info[0], $info[1]);
                ob_start(); imagejpeg($dst, null, 82); $raw = ob_get_clean(); $mt = 'image/jpeg';
                imagedestroy($im); imagedestroy($dst);
            }
        }
        if (strlen($raw) > 4800000) { $avisos[] = "$nome: ficou grande demais (reduza o print)"; continue; }
        $out[] = ['media_type' => $mt, 'data' => base64_encode($raw)];
    }
    return ['imgs' => $out, 'avisos' => $avisos];
}

/** Prompt de sistema da análise (usa a campanha: o que é vendido e para qual ramo). */
function ppPromptSistema(array $camp): string {
    $empresa = getPConfig('empresa_nome', 'Opa Tecnologia e Design');
    return "Você prepara a abordagem comercial da {$empresa} ({$camp['produto']}) analisando UM negócio do ramo de {$camp['ramo']} (a empresa). "
        . "Use APENAS o material fornecido: dados do Google, texto do site e imagens de prints do Instagram/Facebook do negócio.\n"
        . "REGRAS:\n"
        . "1. Só afirme o que o material mostra. O que for estimativa deve dizer que é estimativa e a BASE (ex.: \"3 unidades citadas no site; ~200 avaliações no Google\"). Sem evidência: escreva \"sem evidência\". Não chute.\n"
        . "2. Fale só da EMPRESA. NÃO identifique pessoas pelo rosto, NÃO registre nomes de clientes, alunos ou crianças.\n"
        . "3. Presença digital = o que existe e o que falta (site próprio, site moderno/responsivo, loja online, agendamento, cardápio digital, redes ativas).\n"
        . "4. Operação = como o negócio parece trabalhar hoje (pedidos, agenda, cobrança, estoque), só se o material mostrar.\n"
        . "5. Oportunidades = até 3 pontos em que o que a Opa oferece ({$camp['produto']}) poderia ajudar, ligados a algo que o material mostra.\n"
        . "6. Ganchos = até 3 assuntos naturais e respeitosos para puxar conversa (ex.: uma promoção citada, uma unidade nova, o jeito de atender). Não finja intimidade nem cite que viu o perfil.\n"
        . "Responda SOMENTE com JSON, sem texto fora dele, neste formato:\n"
        . "{\"resumo\":\"2 frases\",\"atividades\":[\"o que o negócio faz/vende\"],"
        . "\"porte\":{\"valor\":\"pequeno|médio|grande|sem evidência\",\"base\":\"...\"},"
        . "\"presenca_digital\":{\"valor\":\"resumo curto do que existe e do que falta\",\"base\":\"...\"},"
        . "\"operacao\":{\"valor\":\"como parece operar hoje ou sem evidência\",\"base\":\"...\"},"
        . "\"unidades\":{\"valor\":\"1|mais de 1|sem evidência\",\"base\":\"...\"},"
        . "\"oportunidades\":[\"...\"],\"sinais\":[\"fatos concretos observados\"],\"ganchos\":[\"...\"]}";
}

/** Texto do usuário (dados do contato + site). */
function ppPromptDados(array $p, array $site, int $nImgs): string {
    $t = "NEGÓCIO: " . $p['nome'] . "\n";
    if (!empty($p['segmento'])) $t .= "Segmento cadastrado: " . $p['segmento'] . "\n";
    $loc = trim(($p['bairro'] ?? '') . ', ' . ($p['cidade'] ?? '') . ' ' . ($p['uf'] ?? ''), ', ');
    if ($loc !== '') $t .= "Local: $loc\n";
    if ($p['nota'] !== null && $p['nota'] !== '') $t .= "Google: nota " . $p['nota'] . (!empty($p['avaliacoes']) ? " em " . (int)$p['avaliacoes'] . " avaliações" : '') . "\n";
    if (!empty($p['obs'])) $t .= "Anotação do vendedor: " . $p['obs'] . "\n";
    if (!empty($p['instagram'])) $t .= "Instagram: " . $p['instagram'] . "\n";
    if (!empty($site['ok'])) {
        $t .= "\nSITE (" . ($site['url'] ?? '') . ")\nTítulo: " . ($site['titulo'] ?? '') . "\nDescrição: " . ($site['descricao'] ?? '') . "\nTexto da página:\n" . ($site['texto'] ?? '') . "\n";
    } else {
        $t .= "\nSITE: não foi lido (" . ($site['erro'] ?? 'sem site') . ").\n";
    }
    $t .= $nImgs ? "\nIMAGENS ANEXAS: $nImgs print(s) do Instagram/Facebook do negócio (postagens, perfil). Analise o que o negócio faz, como se apresenta e o movimento que aparece.\n" : "\nSem prints de redes sociais.\n";
    return $t;
}

/** Interpreta o JSON do Claude; devolve array normalizado ou null. */
function ppInterpretar(string $txt): ?array {
    if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];
    $j = json_decode($txt, true);
    if (!is_array($j) || !isset($j['resumo'])) return null;
    $par = fn($k) => ['valor' => trim((string)($j[$k]['valor'] ?? 'sem evidência')), 'base' => trim((string)($j[$k]['base'] ?? ''))];
    $lista = fn($k) => array_values(array_filter(array_map(fn($x) => trim((string)$x), is_array($j[$k] ?? null) ? $j[$k] : []), 'strlen'));
    return [
        'resumo' => trim((string)$j['resumo']), 'atividades' => $lista('atividades'),
        'porte' => $par('porte'), 'presenca_digital' => $par('presenca_digital'), 'operacao' => $par('operacao'), 'unidades' => $par('unidades'),
        'oportunidades' => array_slice($lista('oportunidades'), 0, 3), 'sinais' => $lista('sinais'), 'ganchos' => array_slice($lista('ganchos'), 0, 3),
    ];
}

/** Executa a pesquisa de um contato. Retorna ['ok','ficha'?, 'erro'?, 'avisos'=>[]]. */
function ppPesquisar(PDO $db, array $p, array $camp, array $arquivos): array {
    $avisos = [];
    $site = ['ok' => false, 'erro' => 'sem site'];
    if (!empty($p['site'])) $site = ppBuscarSite((string)$p['site']);
    if (empty($site['ok']) && !empty($p['site'])) $avisos[] = 'Site: ' . $site['erro'];
    $im = ppImagens($arquivos);
    $avisos = array_merge($avisos, $im['avisos']);
    if (empty($site['ok']) && !$im['imgs']) {
        return ['ok' => false, 'erro' => 'Sem site legível e sem prints: não há o que analisar. Anexe prints do Instagram/Facebook (perfil e postagens).', 'avisos' => $avisos];
    }
    // Instagram achado no site e ainda não cadastrado
    if (!empty($site['instagram']) && empty($p['instagram'])) {
        $db->prepare("UPDATE pro_prospects SET instagram = ? WHERE id = ? AND (instagram IS NULL OR instagram = '')")->execute([$site['instagram'], (int)$p['id']]);
        $p['instagram'] = $site['instagram'];
    }
    if (!function_exists('claudeTextoComImagens')) require_once __DIR__ . '/claude_api.php';
    iaContexto('pesquisa', (int)$p['id'], (int)($p['campanha_id'] ?? 0) ?: null);
    $r = claudeTextoComImagens(ppPromptSistema($camp), ppPromptDados($p, $site, count($im['imgs'])), $im['imgs'], 1800, 'low');
    if (!$r['ok']) return ['ok' => false, 'erro' => $r['erro'], 'avisos' => $avisos];
    $f = ppInterpretar($r['texto']);
    if (!$f) return ['ok' => false, 'erro' => 'A resposta do Claude veio fora do formato. Tente de novo.', 'avisos' => $avisos];
    $f['fontes'] = ['google' => $p['nota'] !== null && $p['nota'] !== '', 'site' => !empty($site['ok']), 'prints' => count($im['imgs'])];
    $f['em'] = date('d/m/Y H:i');
    $db->prepare('UPDATE pro_prospects SET pesquisa = ?, pesquisa_em = ? WHERE id = ?')->execute([json_encode($f, JSON_UNESCAPED_UNICODE), proAgora(), (int)$p['id']]);
    return ['ok' => true, 'ficha' => $f, 'avisos' => $avisos];
}

/** HTML da ficha (tudo escapado). */
function ppFichaHtml(?array $f): string {
    if (!$f) return '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $lin = fn($rot, $d) => '<div class="pr-f-l"><b>' . $e($rot) . ':</b> ' . $e($d['valor'] ?? '') . (($d['base'] ?? '') !== '' ? ' <span>— ' . $e($d['base']) . '</span>' : '') . '</div>';
    $fontes = [];
    if (!empty($f['fontes']['google'])) $fontes[] = 'Google';
    if (!empty($f['fontes']['site'])) $fontes[] = 'site';
    if (!empty($f['fontes']['prints'])) $fontes[] = (int)$f['fontes']['prints'] . ' print(s)';
    $h = '<div class="pr-f-t">🔎 Pesquisa' . (!empty($f['em']) ? ' · ' . $e($f['em']) : '') . ($fontes ? ' · ' . $e(implode(' + ', $fontes)) : '') . '</div>';
    $h .= '<div class="pr-f-l">' . $e($f['resumo'] ?? '') . '</div>';
    if (!empty($f['atividades'])) $h .= '<div class="pr-f-l"><b>O que faz:</b> ' . $e(implode(', ', $f['atividades'])) . '</div>';
    $h .= $lin('Porte (estimativa)', $f['porte'] ?? []) . $lin('Presença digital', $f['presenca_digital'] ?? []) . $lin('Operação', $f['operacao'] ?? []) . $lin('Unidades', $f['unidades'] ?? []);
    if (!empty($f['oportunidades'])) $h .= '<div class="pr-f-l"><b>Oportunidades:</b> ' . $e(implode(' · ', $f['oportunidades'])) . '</div>';
    if (!empty($f['sinais'])) $h .= '<div class="pr-f-l"><b>Sinais:</b> ' . $e(implode(' · ', $f['sinais'])) . '</div>';
    if (!empty($f['ganchos'])) $h .= '<div class="pr-f-l"><b>Ganchos de conversa:</b> ' . $e(implode(' | ', $f['ganchos'])) . '</div>';
    $h .= '<div class="pr-f-n">Hipóteses a partir de material público: confirme na conversa, não trate como fato.</div>';
    return $h;
}

/** Texto para o Vendedor IA / gerador da 1ª mensagem (hipóteses, nunca certezas). '' se não houver pesquisa. */
function ppTextoParaIA(?string $json): string {
    if (!$json) return '';
    $f = json_decode($json, true);
    if (!is_array($f) || empty($f['resumo'])) return '';
    $t = "\nNOTAS INTERNAS (pesquisa pública; são HIPÓTESES, você NÃO tem certeza): use só para escolher a próxima pergunta e CONFIRMAR com a pessoa (ex.: \"vocês já têm site próprio?\"). NUNCA afirme isso como fato, NUNCA cite números estimados e NUNCA diga que pesquisou o perfil dela.\n"
       . "- Resumo: " . $f['resumo'] . "\n";
    if (!empty($f['atividades'])) $t .= "- O que parece fazer: " . implode(', ', $f['atividades']) . "\n";
    foreach (['porte' => 'Porte (estimativa)', 'presenca_digital' => 'Presença digital', 'operacao' => 'Operação', 'unidades' => 'Unidades'] as $k => $rot)
        if (!empty($f[$k]['valor'])) $t .= "- {$rot}: " . $f[$k]['valor'] . "\n";
    if (!empty($f['oportunidades'])) $t .= "- Onde podemos ajudar (hipótese): " . implode(' | ', $f['oportunidades']) . "\n";
    if (!empty($f['ganchos'])) $t .= "- Assuntos naturais: " . implode(' | ', $f['ganchos']) . "\n";
    return $t;
}

}
