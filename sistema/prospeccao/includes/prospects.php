<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════
// Entrada de contatos: uma única regra de "não repetir" para Google, CSV e lista manual.
//  • mesmo telefone (com ou sem o 9º dígito), mesmo place_id do Google ou mesmo Instagram → já existe
//  • telefone na lista de bloqueio: "não contatar" nunca entra; "cliente" entra já marcado como cliente
//  • sem telefone e sem Instagram → descartado (não há como falar)
// A checagem vale para TODAS as campanhas: o mesmo negócio não recebe duas abordagens.
// ═══════════════════════════════════════════════════════════

/** Carrega o que já existe (telefones, place_ids, Instagrams, bloqueados). */
function proDedupe(PDO $db): array {
    $dd = ['tel' => [], 'gid' => [], 'ig' => [], 'bloq' => []];
    foreach ($db->query('SELECT telefone, google_place_id, instagram FROM pro_prospects') as $r) {
        if ($r['telefone']) $dd['tel'][$r['telefone']] = 1;
        if ($r['google_place_id']) $dd['gid'][$r['google_place_id']] = 1;
        if ($r['instagram']) $dd['ig'][mb_strtolower(ltrim($r['instagram'], '@'))] = 1;
    }
    foreach ($db->query('SELECT telefone, tipo FROM pro_bloqueados') as $r) $dd['bloq'][$r['telefone']] = $r['tipo'];
    return $dd;
}

/**
 * Insere um contato se for novo.
 * $d: campanha_id, nome, segmento, cidade, bairro, uf, endereco, telefone (já normalizado), instagram,
 *     site, nota, avaliacoes, origem, google_place_id, maps_url, busca, obs
 * @return string novo | cliente | dup | bloqueado | sem_contato | invalido
 */
function proInserir(PDO $db, array $d, array &$dd): string {
    $nome = trim((string)($d['nome'] ?? ''));
    if ($nome === '') return 'invalido';
    $tel = (string)($d['telefone'] ?? '');
    $ig  = trim((string)($d['instagram'] ?? ''));
    $gid = (string)($d['google_place_id'] ?? '');
    if ($tel === '' && $ig === '') return 'sem_contato';
    if ($gid !== '' && isset($dd['gid'][$gid])) return 'dup';
    $igKey = mb_strtolower(ltrim($ig, '@'));
    if ($tel === '' && $igKey !== '' && isset($dd['ig'][$igKey])) return 'dup';
    $status = 'novo';
    if ($tel !== '') {
        foreach (proTelVariantes($tel) as $v) {
            if (isset($dd['tel'][$v])) return 'dup';
            if (($dd['bloq'][$v] ?? '') === 'nao_contatar') return 'bloqueado';
            if (($dd['bloq'][$v] ?? '') === 'cliente') $status = 'cliente';
        }
    }
    $db->prepare('INSERT INTO pro_prospects (campanha_id, nome, segmento, cidade, bairro, uf, endereco, telefone, instagram, site, nota, avaliacoes,
                    status, obs, origem, busca, google_place_id, maps_url, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([
           (int)$d['campanha_id'], mb_substr($nome, 0, 190), mb_substr((string)($d['segmento'] ?? ''), 0, 80) ?: null,
           mb_substr((string)($d['cidade'] ?? ''), 0, 90) ?: null, mb_substr((string)($d['bairro'] ?? ''), 0, 90) ?: null,
           isset(PRO_UFS[$d['uf'] ?? '']) ? $d['uf'] : null, mb_substr((string)($d['endereco'] ?? ''), 0, 255) ?: null,
           $tel ?: null, mb_substr($ig, 0, 120) ?: null, mb_substr((string)($d['site'] ?? ''), 0, 255) ?: null,
           isset($d['nota']) && $d['nota'] !== null ? min(5, max(0, (float)$d['nota'])) : null,
           isset($d['avaliacoes']) && $d['avaliacoes'] !== null ? (int)$d['avaliacoes'] : null,
           $status, mb_substr((string)($d['obs'] ?? ''), 0, 2000) ?: null, mb_substr((string)($d['origem'] ?? ''), 0, 30) ?: null,
           mb_substr((string)($d['busca'] ?? ''), 0, 160) ?: null, $gid ?: null, mb_substr((string)($d['maps_url'] ?? ''), 0, 255) ?: null, proAgora(),
       ]);
    if ($tel !== '') $dd['tel'][$tel] = 1;
    if ($gid !== '') $dd['gid'][$gid] = 1;
    if ($igKey !== '') $dd['ig'][$igKey] = 1;
    return $status === 'cliente' ? 'cliente' : 'novo';
}

/** Resumo legível dos contadores de proInserir. */
function proResumoInsercao(array $c): string {
    $p = [];
    if (!empty($c['dup']))         $p[] = $c['dup'] . ' já estavam na base';
    if (!empty($c['bloqueado']))   $p[] = $c['bloqueado'] . ' em "não contatar" (ignorados)';
    if (!empty($c['sem_contato'])) $p[] = $c['sem_contato'] . ' sem telefone nem Instagram';
    if (!empty($c['cliente']))     $p[] = $c['cliente'] . ' já são clientes (marcados)';
    return $p ? ' · ' . implode(' · ', $p) : '';
}
