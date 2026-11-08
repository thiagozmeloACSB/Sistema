<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// Campanhas: cada uma = um produto/serviço + um ramo de negócio + como abordar
$lista = $db->query('SELECT c.*, (SELECT COUNT(*) FROM pro_prospects p WHERE p.campanha_id = c.id) AS total FROM pro_campanhas c ORDER BY ativa DESC, id DESC')->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfCheck($_POST['csrf'] ?? '')) { flash('Sessão expirada.', 'error'); redirect(proUrl('campanhas')); }
    $acao = (string)($_POST['acao'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar') {
        $t = fn($k, $n) => mb_substr(trim(str_replace("\r", '', (string)($_POST[$k] ?? ''))), 0, $n);
        $d = ['nome' => $t('nome', 120), 'produto' => $t('produto', 190), 'ramo' => $t('ramo', 120), 'persona' => $t('persona', 80),
              'pitch' => $t('pitch', 4000), 'dor_comum' => $t('dor_comum', 2000), 'oferta' => $t('oferta', 255), 'link' => $t('link', 255),
              'tom' => $t('tom', 255), 'contexto' => $t('contexto', 2000), 'busca_sugestoes' => $t('busca_sugestoes', 2000), 'segmentos' => $t('segmentos', 3000),
              'limite_dia' => max(5, min(200, (int)($_POST['limite_dia'] ?? 25))),
              'ia_objetivo' => in_array($_POST['ia_objetivo'] ?? '', ['link', 'conversa', 'proposta'], true) ? $_POST['ia_objetivo'] : 'link',
              'ia_precos' => $t('ia_precos', 3000), 'ia_proibido' => $t('ia_proibido', 3000), 'ia_extra' => $t('ia_extra', 3000)];
        if ($d['nome'] === '') $d['nome'] = $d['produto'] . ' · ' . $d['ramo'];
        if ($d['produto'] === '' || $d['ramo'] === '' || $d['pitch'] === '') {
            flash('Preencha o produto, o ramo e a descrição do que você vende.', 'error');
            redirect(proUrl('campanhas', $id ? ['id' => $id] : ['nova' => 1]));
        }
        if ($d['link'] !== '' && !preg_match('~^https?://~i', $d['link'])) $d['link'] = 'https://' . $d['link'];
        if ($id && proCampanha($db, $id)) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $db->prepare("UPDATE pro_campanhas SET $set, atualizado_em = ? WHERE id = ?")->execute([...array_values($d), proAgora(), $id]);
            flash('Campanha salva.');
        } else {
            $cols = implode(', ', array_keys($d));
            $db->prepare("INSERT INTO pro_campanhas ($cols, criado_em) VALUES (" . implode(',', array_fill(0, count($d), '?')) . ',?)')->execute([...array_values($d), proAgora()]);
            $id = (int)$db->lastInsertId();
            flash('Campanha criada. Agora é só buscar contatos.');
            redirect(proUrl('prospeccao', ['c' => $id]));
        }
        redirect(proUrl('campanhas'));
    }
    if ($acao === 'alternar' && $id) {
        $db->prepare('UPDATE pro_campanhas SET ativa = 1 - ativa WHERE id = ?')->execute([$id]);
        flash('Campanha atualizada.'); redirect(proUrl('campanhas'));
    }
    if ($acao === 'duplicar' && ($c = proCampanha($db, $id))) {
        unset($c['id'], $c['total']);
        $c['nome'] = mb_substr($c['nome'] . ' (cópia)', 0, 120); $c['criado_em'] = proAgora(); $c['atualizado_em'] = null;
        $db->prepare('INSERT INTO pro_campanhas (' . implode(',', array_keys($c)) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($c));
        flash('Campanha duplicada (sem os contatos). Ajuste ramo e textos.'); redirect(proUrl('campanhas', ['id' => (int)$db->lastInsertId()]));
    }
    if ($acao === 'excluir' && $id) {
        $n = $db->prepare('SELECT COUNT(*) FROM pro_prospects WHERE campanha_id = ?'); $n->execute([$id]);
        if ((int)$n->fetchColumn() > 0) { flash('Esta campanha tem contatos. Arquive-a em vez de excluir (os contatos e o "não contatar" ficam protegidos).', 'error'); }
        else { $db->prepare('DELETE FROM pro_campanhas WHERE id = ?')->execute([$id]); flash('Campanha excluída.'); }
        redirect(proUrl('campanhas'));
    }
    redirect(proUrl('campanhas'));
}

$edit = null; $novo = !empty($_GET['nova']);
if (!empty($_GET['id'])) $edit = proCampanha($db, (int)$_GET['id']);
if (!$edit && !$lista) $novo = true;   // primeira vez: abre o formulário
$f = $edit ?: ['id' => 0, 'nome' => '', 'produto' => '', 'ramo' => '', 'persona' => $_SESSION['usuario']['nome'] ?? '', 'pitch' => '', 'dor_comum' => '', 'oferta' => '',
               'link' => '', 'tom' => '', 'contexto' => '', 'busca_sugestoes' => '', 'segmentos' => '', 'limite_dia' => 25,
               'ia_objetivo' => 'link', 'ia_precos' => '', 'ia_proibido' => '', 'ia_extra' => ''];

proHeader('Campanhas', 'campanhas');
?>
<?php if (!$edit && !$novo): ?>
  <div style="margin-bottom:14px;"><a class="btn btn-p" href="<?= e(proUrl('campanhas', ['nova' => 1])) ?>">➕ Nova campanha</a></div>
  <?php foreach ($lista as $c): ?>
  <div class="pcard" style="<?= $c['ativa'] ? '' : 'opacity:.6' ?>">
    <div class="ptop">
      <div><div class="pnome"><?= e($c['nome']) ?> <?= $c['ativa'] ? '' : '<span class="tag">arquivada</span>' ?></div>
        <div class="pmeta">Vende: <b><?= e($c['produto']) ?></b> · Ramo: <b><?= e($c['ramo']) ?></b> · <?= (int)$c['total'] ?> contato(s) · limite <?= (int)$c['limite_dia'] ?>/dia</div></div>
      <div class="pacoes" style="margin:0;">
        <a class="btn btn-sm btn-p" href="<?= e(proUrl('prospeccao', ['c' => $c['id']])) ?>">Prospectar</a>
        <a class="btn btn-sm" href="<?= e(proUrl('campanhas', ['id' => $c['id']])) ?>">✏️ Editar</a>
        <form method="POST" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-sm" name="acao" value="duplicar">Duplicar</button>
          <button class="btn btn-sm" name="acao" value="alternar"><?= $c['ativa'] ? 'Arquivar' : 'Reativar' ?></button>
          <?php if (!(int)$c['total']): ?><button class="btn btn-sm btn-perigo" name="acao" value="excluir" onclick="return confirm('Excluir esta campanha?');">Excluir</button><?php endif; ?>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php else: ?>
<form method="POST">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">

  <div class="painel"><div class="painel-h"><div class="painel-t">1 · O que você vende e para quem</div></div><div class="painel-b">
    <div class="grid-2">
      <div class="campo"><label class="rotulo">Produto / serviço *</label><input class="input" name="produto" value="<?= e($f['produto']) ?>" required maxlength="190" placeholder="O que está sendo vendido (nome curto)"></div>
      <div class="campo"><label class="rotulo">Ramo de negócio alvo *</label><input class="input" name="ramo" value="<?= e($f['ramo']) ?>" required maxlength="120" placeholder="Quem compra (ex.: o tipo de negócio)"></div>
    </div>
    <div class="campo"><label class="rotulo">Nome da campanha (opcional — senão usa "produto · ramo")</label><input class="input" name="nome" value="<?= e($f['nome']) ?>" maxlength="120"></div>
    <div class="campo"><label class="rotulo">Descrição do que você vende (base de todas as mensagens) *</label>
      <textarea class="input" name="pitch" rows="4" required placeholder="O que é, o que resolve, diferenciais reais. A IA só usa o que estiver escrito aqui."><?= e($f['pitch']) ?></textarea></div>
    <div class="campo"><label class="rotulo">Dores comuns deste ramo (a mensagem menciona UMA)</label>
      <textarea class="input" name="dor_comum" rows="3" placeholder="Uma por linha"><?= e($f['dor_comum']) ?></textarea></div>
    <div class="grid-2">
      <div class="campo"><label class="rotulo">Oferta / isca (opcional)</label><input class="input" name="oferta" value="<?= e($f['oferta']) ?>" maxlength="255" placeholder="Ex.: diagnóstico grátis, teste, orçamento sem compromisso"></div>
      <div class="campo"><label class="rotulo">Link (site, demo, portfólio)</label><input class="input" name="link" value="<?= e($f['link']) ?>" maxlength="255" placeholder="https://"></div>
    </div>
  </div></div>

  <div class="painel"><div class="painel-h"><div class="painel-t">2 · Como a mensagem é escrita</div></div><div class="painel-b">
    <div class="grid-2">
      <div class="campo"><label class="rotulo">Quem assina (nome da pessoa que fala)</label><input class="input" name="persona" value="<?= e($f['persona']) ?>" maxlength="80"></div>
      <div class="campo"><label class="rotulo">Tom de voz (opcional)</label><input class="input" name="tom" value="<?= e($f['tom']) ?>" maxlength="255" placeholder="Ex.: descontraído, direto, formal"></div>
    </div>
    <div class="campo"><label class="rotulo">Contexto sobre você que ajuda a soar natural (opcional)</label>
      <textarea class="input" name="contexto" rows="2" placeholder="Ex.: área em comum com o ramo, tempo de experiência, cidade"><?= e($f['contexto']) ?></textarea></div>
    <div class="campo"><label class="rotulo">Limite sugerido de contatos por dia nesta campanha</label><input class="input" type="number" name="limite_dia" value="<?= (int)$f['limite_dia'] ?>" min="5" max="200" style="max-width:140px;"></div>
  </div></div>

  <div class="painel"><div class="painel-h"><div class="painel-t">3 · Busca e segmentos</div></div><div class="painel-b">
    <div class="campo"><label class="rotulo">Termos de busca sugeridos no Google (um por linha)</label>
      <textarea class="input" name="busca_sugestoes" rows="3" placeholder="Aparecem como sugestão no campo &quot;Tipo de negócio&quot; da Prospecção"><?= e($f['busca_sugestoes']) ?></textarea></div>
    <div class="campo"><label class="rotulo">Segmentos (opcional) — uma linha por segmento: <code>Rótulo: palavra, palavra</code></label>
      <textarea class="input" name="segmentos" rows="3" placeholder="Ex.:&#10;Segmento A: palavra1, palavra2&#10;Segmento B: palavra3"><?= e($f['segmentos']) ?></textarea>
      <div class="dica">Usado para etiquetar contatos importados por CSV pelo nome/categoria. Na busca do Google, o segmento é o próprio "tipo de negócio" que você digitou.</div></div>
  </div></div>

  <div class="painel"><div class="painel-h"><div class="painel-t">4 · Vendedor IA (só vale se o módulo estiver ligado)</div></div><div class="painel-b">
    <div class="campo"><label class="rotulo">Objetivo da conversa quando o contato responde</label>
      <select class="input" name="ia_objetivo" style="max-width:380px;">
        <?php foreach (['link' => 'Levar para o link da campanha', 'conversa' => 'Marcar uma conversa comigo', 'proposta' => 'Reunir informações e passar para mim montar a proposta'] as $k => $r): ?>
        <option value="<?= $k ?>" <?= $f['ia_objetivo'] === $k ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
      </select></div>
    <div class="campo"><label class="rotulo">O que a IA pode dizer sobre preço (vazio = nunca fala valores, passa para você)</label>
      <textarea class="input" name="ia_precos" rows="2"><?= e($f['ia_precos']) ?></textarea></div>
    <div class="campo"><label class="rotulo">O que a IA NUNCA deve dizer ou prometer</label>
      <textarea class="input" name="ia_proibido" rows="2" placeholder="Ex.: desconto, prazo, garantia"><?= e($f['ia_proibido']) ?></textarea></div>
    <div class="campo"><label class="rotulo">Fatos extras que a IA pode usar (diferenciais, casos reais)</label>
      <textarea class="input" name="ia_extra" rows="3"><?= e($f['ia_extra']) ?></textarea></div>
  </div></div>

  <button class="btn btn-p">💾 Salvar campanha</button>
  <a class="btn" href="<?= e(proUrl('campanhas')) ?>">Cancelar</a>
</form>
<?php endif; ?>
<?php proFooter();
