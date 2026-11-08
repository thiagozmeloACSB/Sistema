<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// Consumo de IA: quantas mensagens o piloto movimenta, quantos tokens o Claude gasta e quanto isso custa.
// Base para repassar o custo das mensagens ao cliente depois dos 90 dias.
$self = proUrl('consumo');

/** Preço em US$ por 1 milhão de tokens [entrada, saída], pela família do modelo (ajustável abaixo). */
function usoPrecoModelo(string $modelo): array {
    $m = strtolower($modelo);
    if (strpos($m, 'haiku') !== false)  return [0.10, 0.50];
    if (strpos($m, 'sonnet') !== false) return [2.00, 10.00];
    return [4.00, 20.00];   // opus e desconhecidos: o mais caro, para não subestimar
}
function usoCusto(array $l, float $cambio, float $pin, float $pout): float {
    if ($pin > 0 || $pout > 0) { $a = $pin; $b = $pout; } else { [$a, $b] = usoPrecoModelo((string)($l['modelo'] ?? '')); }
    $usd = ((int)$l['tokens_in'] * $a + (int)$l['tokens_out'] * $b + (int)$l['cache_leitura'] * $a * 0.10 + (int)$l['cache_escrita'] * $a * 1.25) / 1e6;
    return $usd * $cambio;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfCheck($_POST['csrf'] ?? '')) { flash('Sessão expirada.', 'error'); redirect($self); }
    $num = fn($k) => max(0, (float)str_replace(',', '.', trim((string)($_POST[$k] ?? ''))));
    $cb = $num('cambio');
    setPConfig('uso_cambio', $cb > 0 ? (string)$cb : '');
    setPConfig('uso_preco_in', $num('preco_in') > 0 ? (string)$num('preco_in') : '');
    setPConfig('uso_preco_out', $num('preco_out') > 0 ? (string)$num('preco_out') : '');
    flash('Cotação e preços salvos.'); redirect($self);
}

$cambio = (float)str_replace(',', '.', getPConfig('uso_cambio')) ?: 5.40;
$pin  = (float)str_replace(',', '.', getPConfig('uso_preco_in'));
$pout = (float)str_replace(',', '.', getPConfig('uso_preco_out'));

// Período: 30 dias (padrão), 90 dias (o piloto), ou tudo
$per = (string)($_GET['per'] ?? '30');
if (!in_array($per, ['7', '30', '90', 'tudo'], true)) $per = '30';
$ini = $per === 'tudo' ? '2000-01-01 00:00:00' : date('Y-m-d 00:00:00', strtotime('-' . ((int)$per - 1) . ' days'));

$st = $db->prepare('SELECT * FROM pro_ia_uso WHERE criado_em >= ? ORDER BY id');
$st->execute([$ini]);
$linhas = $st->fetchAll();

$tot = ['n' => 0, 'in' => 0, 'out' => 0, 'rs' => 0.0];
$porTipo = []; $porMes = []; $porCamp = []; $porPros = []; $dias = [];
foreach ($linhas as $l) {
    $rs = usoCusto($l, $cambio, $pin, $pout);
    $tot['n']++; $tot['in'] += (int)$l['tokens_in'] + (int)$l['cache_leitura'] + (int)$l['cache_escrita']; $tot['out'] += (int)$l['tokens_out']; $tot['rs'] += $rs;
    foreach ([[&$porTipo, $l['tipo']], [&$porMes, substr($l['criado_em'], 0, 7)], [&$porCamp, (int)$l['campanha_id']], [&$porPros, (int)$l['prospect_id']]] as [&$arr, $k]) {
        $arr[$k] = $arr[$k] ?? ['n' => 0, 'in' => 0, 'out' => 0, 'rs' => 0.0];
        $arr[$k]['n']++; $arr[$k]['in'] += (int)$l['tokens_in'] + (int)$l['cache_leitura'] + (int)$l['cache_escrita']; $arr[$k]['out'] += (int)$l['tokens_out']; $arr[$k]['rs'] += $rs;
    }
    unset($arr);
    $dias[substr($l['criado_em'], 0, 10)] = true;
}

// Mensagens do período (histórico de conversas)
$q = $db->prepare("SELECT autor, COUNT(*) AS n FROM pro_vd_mensagens WHERE criado_em >= ? GROUP BY autor");
$q->execute([$ini]);
$msg = ['cliente' => 0, 'ia' => 0, 'voce' => 0, 'sistema' => 0];
foreach ($q as $r) $msg[$r['autor']] = (int)$r['n'];
$q = $db->prepare("SELECT COUNT(DISTINCT prospect_id) FROM pro_vd_mensagens WHERE criado_em >= ? AND autor = 'cliente'");
$q->execute([$ini]);
$conversas = (int)$q->fetchColumn();

$respIA = $msg['ia'];
$custoResp = $respIA ? $tot['rs'] / $respIA : 0;
$custoMsg  = ($msg['cliente'] + $msg['ia']) ? $tot['rs'] / ($msg['cliente'] + $msg['ia']) : 0;   // por mensagem trocada (as do contato + as da IA)
$nDias = max(1, count($dias));
$mediaDia = $tot['rs'] / $nDias;
$rotTipo = ['vendedor' => 'Vendedor IA (respostas)', 'abordagem' => '1ª mensagem (prospecção)', 'pesquisa' => 'Pesquisa do contato', 'outro' => 'Outros'];
$camps = [];
foreach ($db->query('SELECT id, nome FROM pro_campanhas') as $c) $camps[(int)$c['id']] = $c['nome'];
$nomes = [];
if ($porPros) {
    $ids = array_filter(array_keys($porPros));
    if ($ids) { $in = implode(',', array_map('intval', $ids)); foreach ($db->query("SELECT id, nome FROM pro_prospects WHERE id IN ($in)") as $r) $nomes[(int)$r['id']] = $r['nome']; }
}
uasort($porPros, fn($a, $b) => $b['rs'] <=> $a['rs']);
$brl = fn($v) => 'R$ ' . number_format($v, $v < 1 ? 3 : 2, ',', '.');

proHeader('Consumo de IA', 'consumo');
?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
  <?php foreach (['7' => '7 dias', '30' => '30 dias', '90' => '90 dias (piloto)', 'tudo' => 'Tudo'] as $k => $rot): ?>
    <a class="btn btn-sm <?= $per === (string)$k ? 'btn-p' : '' ?>" href="<?= e(proUrl('consumo', ['per' => $k])) ?>"><?= e($rot) ?></a>
  <?php endforeach; ?>
</div>

<div class="kpis">
  <div class="kpi"><b><?= $msg['cliente'] ?></b><span>Mensagens recebidas</span></div>
  <div class="kpi"><b><?= $msg['ia'] ?></b><span>Respostas da IA</span></div>
  <div class="kpi"><b><?= $conversas ?></b><span>Conversas ativas</span></div>
  <div class="kpi"><b><?= $tot['n'] ?></b><span>Chamadas ao Claude</span></div>
  <div class="kpi"><b><?= number_format($tot['in'] + $tot['out'], 0, ',', '.') ?></b><span>Tokens (entrada + saída)</span></div>
  <div class="kpi"><b><?= e($brl($tot['rs'])) ?></b><span>Custo estimado</span></div>
  <div class="kpi"><b><?= e($brl($custoResp)) ?></b><span>Por resposta da IA</span></div>
  <div class="kpi"><b><?= e($brl($custoMsg)) ?></b><span>Por mensagem trocada</span></div>
</div>

<div class="painel"><div class="painel-h"><div class="painel-t">Projeção para 90 dias</div></div><div class="painel-b">
  <?php if ($tot['n'] === 0): ?>
    <div class="dica">Ainda sem chamadas registradas. O consumo começa a ser gravado a partir desta versão: cada resposta do Vendedor IA, 1ª mensagem e pesquisa entra aqui com seus tokens.</div>
  <?php else: ?>
    <p>Em <b><?= $nDias ?></b> dia(s) com atividade, a média foi <b><?= e($brl($mediaDia)) ?>/dia</b> (<?= e($brl($mediaDia * 30)) ?> por mês).
       Mantido esse ritmo, o piloto de 90 dias consome cerca de <b><?= e($brl($mediaDia * 90)) ?></b> em IA
       (≈ <?= e(number_format($mediaDia * 90 / $cambio, 2, ',', '.')) ?> US$).</p>
    <div class="dica">É uma média simples: o 1º mês costuma ter menos conversas que o 3º. Use o período "90 dias" ao fim do piloto para o valor real a repassar.</div>
  <?php endif; ?>
</div></div>

<div class="painel"><div class="painel-h"><div class="painel-t">Por tipo de uso</div></div>
  <div class="tabwrap"><table class="tab"><tr><th>Uso</th><th>Chamadas</th><th>Tokens entrada</th><th>Tokens saída</th><th>Custo</th></tr>
  <?php foreach ($porTipo as $k => $a): ?><tr><td><?= e($rotTipo[$k] ?? $k) ?></td><td><?= $a['n'] ?></td><td><?= number_format($a['in'], 0, ',', '.') ?></td><td><?= number_format($a['out'], 0, ',', '.') ?></td><td><?= e($brl($a['rs'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$porTipo): ?><tr><td colspan="5" class="dica">Sem dados no período.</td></tr><?php endif; ?>
  </table></div></div>

<div class="painel"><div class="painel-h"><div class="painel-t">Por mês</div></div>
  <div class="tabwrap"><table class="tab"><tr><th>Mês</th><th>Chamadas</th><th>Tokens</th><th>Custo</th></tr>
  <?php krsort($porMes); foreach ($porMes as $k => $a): ?><tr><td><?= e($k) ?></td><td><?= $a['n'] ?></td><td><?= number_format($a['in'] + $a['out'], 0, ',', '.') ?></td><td><?= e($brl($a['rs'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$porMes): ?><tr><td colspan="4" class="dica">Sem dados no período.</td></tr><?php endif; ?>
  </table></div></div>

<div class="painel"><div class="painel-h"><div class="painel-t">Por campanha</div></div>
  <div class="tabwrap"><table class="tab"><tr><th>Campanha</th><th>Chamadas</th><th>Tokens</th><th>Custo</th></tr>
  <?php foreach ($porCamp as $k => $a): ?><tr><td><?= e($camps[$k] ?? 'Sem campanha') ?></td><td><?= $a['n'] ?></td><td><?= number_format($a['in'] + $a['out'], 0, ',', '.') ?></td><td><?= e($brl($a['rs'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$porCamp): ?><tr><td colspan="4" class="dica">Sem dados no período.</td></tr><?php endif; ?>
  </table></div></div>

<div class="painel"><div class="painel-h"><div class="painel-t">Conversas que mais consumiram</div></div>
  <div class="tabwrap"><table class="tab"><tr><th>Contato</th><th>Chamadas</th><th>Tokens</th><th>Custo</th></tr>
  <?php $i = 0; foreach ($porPros as $k => $a): if (++$i > 15) break; ?><tr><td><?= $k ? e($nomes[$k] ?? ('#' . $k)) : '—' ?></td><td><?= $a['n'] ?></td><td><?= number_format($a['in'] + $a['out'], 0, ',', '.') ?></td><td><?= e($brl($a['rs'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$porPros): ?><tr><td colspan="4" class="dica">Sem dados no período.</td></tr><?php endif; ?>
  </table></div></div>

<form method="POST" class="painel"><div class="painel-h"><div class="painel-t">Cotação e preços</div></div><div class="painel-b">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
  <div class="dica" style="margin-bottom:10px;">O custo é estimado: tokens retornados pela API × preço do modelo (<code><?= e(claudeModelo()) ?></code>: US$ <?= e(implode(' / ', array_map(fn($x) => number_format($x, 2), usoPrecoModelo(claudeModelo())))) ?> por milhão de tokens, entrada / saída). Confira na fatura da Anthropic e ajuste se mudar.</div>
  <div class="campo"><label class="rotulo">Cotação do dólar (R$)</label><input class="input" name="cambio" value="<?= e(getPConfig('uso_cambio')) ?>" placeholder="5,40"></div>
  <div class="campo"><label class="rotulo">Preço manual de entrada (US$/milhão) — vazio = automático</label><input class="input" name="preco_in" value="<?= e(getPConfig('uso_preco_in')) ?>"></div>
  <div class="campo"><label class="rotulo">Preço manual de saída (US$/milhão) — vazio = automático</label><input class="input" name="preco_out" value="<?= e(getPConfig('uso_preco_out')) ?>"></div>
  <button class="btn btn-p btn-sm">Salvar</button>
</div></form>
<?php proFooter();
