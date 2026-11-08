<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// Painel: funil por campanha
$camps = $db->query('SELECT * FROM pro_campanhas ORDER BY ativa DESC, id DESC')->fetchAll();
$cont = [];
foreach ($db->query('SELECT campanha_id, status, COUNT(*) AS n FROM pro_prospects GROUP BY campanha_id, status') as $r) $cont[(int)$r['campanha_id']][$r['status']] = (int)$r['n'];
$hoje = [];
$q = $db->prepare('SELECT campanha_id, COUNT(*) AS n FROM pro_prospects WHERE contatado_em >= ? GROUP BY campanha_id');
$q->execute([date('Y-m-d 00:00:00')]);
foreach ($q as $r) $hoje[(int)$r['campanha_id']] = (int)$r['n'];

$tot = array_fill_keys(array_keys(PRO_STATUS), 0);
foreach ($cont as $c) foreach ($c as $s => $n) $tot[$s] = ($tot[$s] ?? 0) + $n;
$base = array_sum($tot);
$contatados = $tot['enviado'] + $tot['respondeu'] + $tot['interessado'] + $tot['cliente'] + $tot['perdido'];
$respostas = $tot['respondeu'] + $tot['interessado'] + $tot['cliente'];
$taxa = $contatados ? round(100 * $respostas / $contatados) : 0;

proHeader('Painel', 'painel');
?>
<?php if (!$camps): ?>
  <div class="painel"><div class="vazio">
    Nenhuma campanha ainda. Uma campanha junta <b>o que você vende</b> + <b>o ramo de negócio</b> que vai abordar.<br><br>
    <a class="btn btn-p" href="<?= e(proUrl('campanhas', ['nova' => 1])) ?>">Criar a primeira campanha</a>
  </div></div>
<?php else: ?>
  <div class="kpis">
    <div class="kpi"><b><?= $base ?></b><span>Contatos na base</span></div>
    <div class="kpi"><b><?= $tot['novo'] ?></b><span>Novos (a abordar)</span></div>
    <div class="kpi"><b><?= $contatados ?></b><span>Já abordados</span></div>
    <div class="kpi"><b><?= $taxa ?>%</b><span>Taxa de resposta</span></div>
    <div class="kpi"><b><?= $tot['interessado'] ?></b><span>Interessados</span></div>
    <div class="kpi"><b><?= $tot['cliente'] ?></b><span>Clientes</span></div>
  </div>
  <div class="painel"><div class="painel-h"><div class="painel-t">Campanhas</div>
    <a class="btn btn-sm" href="<?= e(proUrl('campanhas')) ?>">Gerenciar</a></div>
    <div class="tabwrap"><table class="tab">
      <tr><th>Campanha</th><th>Ramo</th><th>Novos</th><th>Enviados</th><th>Respondeu</th><th>Interessado</th><th>Clientes</th><th>Resposta</th><th>Hoje</th><th></th></tr>
      <?php foreach ($camps as $c): $k = $cont[(int)$c['id']] ?? [];
        $ct = ($k['enviado'] ?? 0) + ($k['respondeu'] ?? 0) + ($k['interessado'] ?? 0) + ($k['cliente'] ?? 0) + ($k['perdido'] ?? 0);
        $rp = ($k['respondeu'] ?? 0) + ($k['interessado'] ?? 0) + ($k['cliente'] ?? 0); ?>
      <tr style="<?= $c['ativa'] ? '' : 'opacity:.55' ?>">
        <td><b><?= e($c['nome']) ?></b><div class="dica"><?= e($c['produto']) ?></div></td>
        <td><?= e($c['ramo']) ?></td>
        <td><?= (int)($k['novo'] ?? 0) ?></td><td><?= (int)($k['enviado'] ?? 0) ?></td><td><?= (int)($k['respondeu'] ?? 0) ?></td>
        <td><?= (int)($k['interessado'] ?? 0) ?></td><td><?= (int)($k['cliente'] ?? 0) ?></td>
        <td><?= $ct ? round(100 * $rp / $ct) . '%' : '—' ?></td>
        <td><?= (int)($hoje[(int)$c['id']] ?? 0) ?>/<?= (int)$c['limite_dia'] ?></td>
        <td><a class="btn btn-sm btn-p" href="<?= e(proUrl('prospeccao', ['c' => $c['id']])) ?>">Abrir</a></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
<?php endif; ?>
<?php proFooter();
