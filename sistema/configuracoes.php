<?php
require_once __DIR__ . '/auth.php';
exigeLogin('superadmin');

// ── SALVAR ─────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Salva plano
    if ($acao === 'plano') {
        $id      = (int)($_POST['id'] ?? 0);
        $nome    = trim($_POST['nome'] ?? '');
        $preco   = (int)(floatval(str_replace(',','.', $_POST['preco'] ?? 0)) * 100);
        $max_cli = $_POST['max_clientes'] === '-1' ? -1 : (int)$_POST['max_clientes'];
        $max_cr  = $_POST['max_criativos_mes'] === '-1' ? -1 : (int)$_POST['max_criativos_mes'];
        $dest    = isset($_POST['destaque']) ? 1 : 0;
        $ativo   = isset($_POST['ativo']) ? 1 : 0;
        if ($id) {
            db()->prepare("UPDATE planos SET nome=?,preco_centavos=?,max_clientes=?,max_criativos_mes=?,destaque=?,ativo=? WHERE id=?")
               ->execute([$nome,$preco,$max_cli,$max_cr,$dest,$ativo,$id]);
            $msg = "✓ Plano '{$nome}' atualizado.";
        }
    }

    // Novo plano
    if ($acao === 'novo_plano') {
        $nome  = trim($_POST['nome'] ?? '');
        $preco = (int)(floatval(str_replace(',','.', $_POST['preco'] ?? 0)) * 100);
        $maxc  = $_POST['max_clientes'] === '-1' ? -1 : (int)$_POST['max_clientes'];
        $maxcr = $_POST['max_criativos_mes'] === '-1' ? -1 : (int)$_POST['max_criativos_mes'];
        if ($nome && $preco) {
            $ord = (int)db()->query("SELECT COALESCE(MAX(ordem),0)+1 FROM planos")->fetchColumn();
            db()->prepare("INSERT INTO planos (nome,preco_centavos,max_clientes,max_criativos_mes,ativo,ordem) VALUES (?,?,?,?,1,?)")
               ->execute([$nome,$preco,$maxc,$maxcr,$ord]);
            $msg = "✓ Plano '{$nome}' criado.";
        }
    }

    // Excluir plano
    if ($acao === 'excluir_plano') {
        $id = (int)($_POST['id'] ?? 0);
        // Verifica se há tenants usando
        $uso = db()->prepare("SELECT COUNT(*) FROM tenants WHERE plano_id=?");
        $uso->execute([$id]);
        if ($uso->fetchColumn() > 0) {
            $msg = "❌ Não é possível excluir — há tenants usando este plano.";
        } else {
            db()->prepare("DELETE FROM planos WHERE id=?")->execute([$id]);
            $msg = "✓ Plano excluído.";
        }
    }

    // Salva FAQs
    if ($acao === 'faqs') {
        $perguntas = $_POST['pergunta'] ?? [];
        $respostas = $_POST['resposta'] ?? [];
        $faqs = [];
        foreach ($perguntas as $i => $p) {
            $p = trim($p); $r = trim($respostas[$i] ?? '');
            if ($p && $r) $faqs[] = ['pergunta' => $p, 'resposta' => $r];
        }
        db()->prepare("INSERT INTO configuracoes (chave,valor,label) VALUES ('faqs',?,'Perguntas Frequentes') ON DUPLICATE KEY UPDATE valor=?")
           ->execute([json_encode($faqs, JSON_UNESCAPED_UNICODE), json_encode($faqs, JSON_UNESCAPED_UNICODE)]);
        $msg = "✓ FAQs salvas (" . count($faqs) . " perguntas).";
    }

    // Salva config geral
    if ($acao === 'config_geral') {
        $campos = ['desconto_anual_pct', 'trial_dias', 'contato_email'];
        foreach ($campos as $c) {
            $v = trim($_POST[$c] ?? '');
            db()->prepare("INSERT INTO configuracoes (chave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
               ->execute([$c, $v, $v]);
        }
        $msg = "✓ Configurações salvas.";
    }
}

// ── CARREGA ────────────────────────────────────────────────
$planos = db()->query("SELECT * FROM planos ORDER BY ordem")->fetchAll();

$cfgs = [];
$st = db()->query("SELECT chave, valor FROM configuracoes");
foreach ($st->fetchAll() as $row) $cfgs[$row['chave']] = $row['valor'];

$faqs = json_decode($cfgs['faqs'] ?? '[]', true) ?: [];
$desconto = $cfgs['desconto_anual_pct'] ?? '20';
$trial    = $cfgs['trial_dias'] ?? '7';
$email    = $cfgs['contato_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configurações — Opa Studio</title>
<script src="/theme.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;--accent:#78be20;--text:#e8eaf0;--muted:#585858;--success:#4ade80;--warning:#fbbf24;--danger:#f87171}
[data-tema="light"]{--bg:#f5f5f0;--surface:#fff;--surface2:#f0f0eb;--border:#ddddd8;--text:#1a1a1a;--muted:#888880;--accent:#3d7a0a;--accent2:#2a5a06;--success:#2d7a4f;--warning:#8a6200;--danger:#b83232}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;transition:background .25s,color .25s}
header{position:sticky;top:0;z-index:100;background:rgba(13,13,13,.93);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);padding:0 2rem;display:flex;align-items:center;justify-content:space-between;height:64px}
[data-tema="light"] header{background:rgba(245,245,240,.93)}
.logo{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:2px;color:var(--accent)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.875rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--accent);color:#0d0d0d}.btn-primary:hover{filter:brightness(1.05)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface2);color:var(--text)}
.btn-danger{background:rgba(248,113,113,.12);color:var(--danger);border:1px solid rgba(248,113,113,.25)}.btn-danger:hover{background:rgba(248,113,113,.2)}
.btn-sm{padding:6px 14px;font-size:.8rem}
main{max-width:1000px;margin:0 auto;padding:2rem}
/* TABS */
.tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:4px;margin-bottom:2rem}
.tab-btn{flex:1;padding:10px;border-radius:9px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;color:var(--muted);background:transparent;transition:all .2s;white-space:nowrap}
.tab-btn.active{background:var(--accent);color:#0d0d0d}
.tab-btn:hover:not(.active){background:var(--surface2);color:var(--text)}
.tab-panel{display:none}.tab-panel.active{display:block;animation:fadeUp .3s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.75rem;margin-bottom:1.25rem}
.card-title{font-family:'Bebas Neue',sans-serif;font-size:1.2rem;letter-spacing:1.5px;color:var(--accent);margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between}
/* PLANO CARD */
.plano-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1rem;position:relative;overflow:hidden;transition:border-color .2s}
.plano-card:hover{border-color:var(--accent)}
.plano-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent)}
.plano-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:1rem}
.plano-preco-preview{font-family:'Bebas Neue',sans-serif;font-size:2rem;color:var(--accent);line-height:1}
/* FORM */
.form-group{margin-bottom:1rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
label.lbl{display:block;font-size:.68rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:7px}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:10px 13px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;transition:border-color .15s}
.form-control:focus{border-color:var(--accent)}.form-control::placeholder{color:var(--muted)}
textarea.form-control{resize:vertical;min-height:80px}
.checkbox-row{display:flex;align-items:center;gap:8px;margin-top:.5rem}
.checkbox-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}
/* FAQ */
.faq-item{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:1rem;margin-bottom:.75rem;position:relative}
.faq-remove{position:absolute;top:10px;right:10px;background:transparent;border:none;color:var(--danger);cursor:pointer;font-size:1.1rem;padding:4px;border-radius:4px;transition:background .15s}
.faq-remove:hover{background:rgba(248,113,113,.1)}
/* MSG */
.msg-ok{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);color:var(--success);padding:12px 16px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:.82rem;margin-bottom:1.5rem}
.msg-err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);color:var(--danger);padding:12px 16px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:.82rem;margin-bottom:1.5rem}
/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(6px);z-index:200;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.active{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:460px;padding:2rem;animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}
.modal-title{font-family:'Bebas Neue',sans-serif;font-size:1.3rem;letter-spacing:2px;color:var(--accent)}
.modal-close{background:transparent;border:none;color:var(--muted);font-size:1.4rem;cursor:pointer}.modal-close:hover{color:var(--text)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:1.5rem}
@media(max-width:600px){.form-row{grid-template-columns:1fr}.plano-grid{grid-template-columns:1fr}.tabs{flex-direction:column}}
</style>
</head>
<body>

<header>
  <div class="logo">◈ Opa Studio — Configurações</div>
  <div style="display:flex;gap:10px">
    <a href="superadmin.php" class="btn btn-ghost btn-sm">← Painel</a>
    <a href="planos.php" target="_blank" class="btn btn-ghost btn-sm">👁 Ver página</a>
  </div>
</header>

<main>

<?php if ($msg): ?>
<div class="<?= str_starts_with($msg,'✓')?'msg-ok':'msg-err' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif ?>

<div class="tabs">
  <button class="tab-btn active" onclick="aba('planos',this)">💰 Planos e Preços</button>
  <button class="tab-btn" onclick="aba('faqs',this)">❓ Perguntas Frequentes</button>
  <button class="tab-btn" onclick="aba('geral',this)">⚙️ Configurações Gerais</button>
</div>

<!-- ═══ PLANOS ═══ -->
<div class="tab-panel active" id="panel-planos">

  <?php foreach ($planos as $p): ?>
  <div class="plano-card">
    <form method="POST">
      <input type="hidden" name="acao" value="plano">
      <input type="hidden" name="id" value="<?= $p['id'] ?>">

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:10px">
        <div>
          <div class="plano-preco-preview" id="preview_<?= $p['id'] ?>">
            R$<?= number_format($p['preco_centavos']/100, 0, ',', '.') ?><span style="font-size:1rem;color:var(--muted)">/mês</span>
          </div>
          <div style="font-size:.75rem;color:var(--muted);font-family:'JetBrains Mono',monospace">Plano #<?= $p['id'] ?></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($p['destaque']): ?><span style="background:rgba(120,190,32,.15);color:var(--accent);font-family:'JetBrains Mono',monospace;font-size:.65rem;padding:3px 10px;border-radius:4px;letter-spacing:1px">DESTAQUE</span><?php endif ?>
          <?php if (!$p['ativo']): ?><span style="background:rgba(248,113,113,.12);color:var(--danger);font-family:'JetBrains Mono',monospace;font-size:.65rem;padding:3px 10px;border-radius:4px">INATIVO</span><?php endif ?>
        </div>
      </div>

      <div class="plano-grid">
        <div class="form-group">
          <label class="lbl">Nome do Plano</label>
          <input type="text" class="form-control" name="nome" value="<?= htmlspecialchars($p['nome']) ?>" required>
        </div>
        <div class="form-group">
          <label class="lbl">Preço Mensal (R$)</label>
          <input type="text" class="form-control" name="preco"
                 value="<?= number_format($p['preco_centavos']/100, 2, ',', '.') ?>"
                 oninput="atualizarPreview(<?= $p['id'] ?>, this.value)"
                 placeholder="Ex: 197,00">
        </div>
        <div class="form-group">
          <label class="lbl">Máx. Clientes (-1 = ilimitado)</label>
          <input type="number" class="form-control" name="max_clientes" value="<?= $p['max_clientes'] ?>">
        </div>
        <div class="form-group">
          <label class="lbl">Máx. Criativos/mês (-1 = ilimitado)</label>
          <input type="number" class="form-control" name="max_criativos_mes" value="<?= $p['max_criativos_mes'] ?>">
        </div>
      </div>

      <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1rem">
        <div class="checkbox-row">
          <input type="checkbox" name="destaque" id="dest_<?= $p['id'] ?>" <?= $p['destaque']?'checked':'' ?>>
          <label for="dest_<?= $p['id'] ?>" style="font-size:.85rem;cursor:pointer">Destacar (mais popular)</label>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" name="ativo" id="ativo_<?= $p['id'] ?>" <?= $p['ativo']?'checked':'' ?>>
          <label for="ativo_<?= $p['id'] ?>" style="font-size:.85rem;cursor:pointer">Plano ativo</label>
        </div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary btn-sm">💾 Salvar Alterações</button>
        <button type="button" class="btn btn-danger btn-sm"
                onclick="excluirPlano(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nome']) ?>')">🗑 Excluir</button>
      </div>
    </form>
  </div>
  <?php endforeach ?>

  <!-- NOVO PLANO -->
  <div class="card" style="border-style:dashed">
    <div class="card-title">+ Criar Novo Plano</div>
    <form method="POST">
      <input type="hidden" name="acao" value="novo_plano">
      <div class="form-row">
        <div class="form-group">
          <label class="lbl">Nome</label>
          <input type="text" class="form-control" name="nome" placeholder="Ex: Enterprise">
        </div>
        <div class="form-group">
          <label class="lbl">Preço Mensal (R$)</label>
          <input type="text" class="form-control" name="preco" placeholder="Ex: 497,00">
        </div>
        <div class="form-group">
          <label class="lbl">Máx. Clientes</label>
          <input type="number" class="form-control" name="max_clientes" value="-1" placeholder="-1 = ilimitado">
        </div>
        <div class="form-group">
          <label class="lbl">Máx. Criativos/mês</label>
          <input type="number" class="form-control" name="max_criativos_mes" value="-1" placeholder="-1 = ilimitado">
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">+ Criar Plano</button>
    </form>
  </div>

</div>

<!-- ═══ FAQS ═══ -->
<div class="tab-panel" id="panel-faqs">
  <div class="card">
    <div class="card-title">
      Perguntas Frequentes
      <button class="btn btn-ghost btn-sm" onclick="addFaq()">+ Adicionar pergunta</button>
    </div>

    <form method="POST" id="formFaqs">
      <input type="hidden" name="acao" value="faqs">

      <div id="faqsContainer">
        <?php foreach ($faqs as $i => $faq): ?>
        <div class="faq-item" id="faq_<?= $i ?>">
          <button type="button" class="faq-remove" onclick="removeFaq(this)" title="Remover">✕</button>
          <div class="form-group">
            <label class="lbl">Pergunta</label>
            <input type="text" class="form-control" name="pergunta[]"
                   value="<?= htmlspecialchars($faq['pergunta']) ?>" placeholder="Ex: Posso cancelar a qualquer momento?" required>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="lbl">Resposta</label>
            <textarea class="form-control" name="resposta[]" rows="2" required><?= htmlspecialchars($faq['resposta']) ?></textarea>
          </div>
        </div>
        <?php endforeach ?>
      </div>

      <div style="display:flex;gap:8px;margin-top:1.25rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">💾 Salvar FAQs</button>
        <button type="button" class="btn btn-ghost" onclick="addFaq()">+ Adicionar pergunta</button>
        <a href="planos.php" target="_blank" class="btn btn-ghost">👁 Visualizar página</a>
      </div>
    </form>
  </div>
</div>

<!-- ═══ CONFIGURAÇÕES GERAIS ═══ -->
<div class="tab-panel" id="panel-geral">
  <div class="card">
    <div class="card-title">Configurações Gerais</div>
    <form method="POST">
      <input type="hidden" name="acao" value="config_geral">
      <div class="form-row">
        <div class="form-group">
          <label class="lbl">Desconto Anual (%)</label>
          <input type="number" class="form-control" name="desconto_anual_pct"
                 value="<?= htmlspecialchars($desconto) ?>" min="0" max="80" placeholder="20">
          <div style="font-size:.72rem;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace">
            Aparece na página de planos ao ativar o toggle "Anual"
          </div>
        </div>
        <div class="form-group">
          <label class="lbl">Dias de Trial Gratuito</label>
          <input type="number" class="form-control" name="trial_dias"
                 value="<?= htmlspecialchars($trial) ?>" min="0" max="90" placeholder="7">
          <div style="font-size:.72rem;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace">
            Aplicado no registro de novos tenants
          </div>
        </div>
      </div>
      <div class="form-group">
        <label class="lbl">E-mail de Suporte</label>
        <input type="email" class="form-control" name="contato_email"
               value="<?= htmlspecialchars($email) ?>" placeholder="contato@opastudio.com.br">
        <div style="font-size:.72rem;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace">
          Exibido na página de conta suspensa e e-mails do sistema
        </div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Salvar Configurações</button>
    </form>
  </div>
</div>

</main>

<!-- MODAL EXCLUIR PLANO -->
<div class="modal-overlay" id="modalExcluir" onclick="if(event.target===this)fecharModalExcluir()">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Excluir Plano</div>
      <button class="modal-close" onclick="fecharModalExcluir()">✕</button>
    </div>
    <p style="font-size:.9rem;color:var(--muted);margin-bottom:1.25rem">
      Tem certeza que deseja excluir o plano <strong id="excluirNome" style="color:var(--text)"></strong>?<br>
      Esta ação não pode ser desfeita.
    </p>
    <form method="POST" id="formExcluir">
      <input type="hidden" name="acao" value="excluir_plano">
      <input type="hidden" name="id" id="excluirId">
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="fecharModalExcluir()">Cancelar</button>
        <button type="submit" class="btn btn-danger">🗑 Excluir</button>
      </div>
    </form>
  </div>
</div>

<script>
let faqCount = <?= count($faqs) ?>;

function aba(nome, el){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel-'+nome).classList.add('active');
  el.classList.add('active');
}

function atualizarPreview(id, val){
  const n = parseFloat(val.replace(',','.')) || 0;
  const el = document.getElementById('preview_'+id);
  if(el) el.innerHTML = 'R$'+n.toLocaleString('pt-BR',{minimumFractionDigits:0,maximumFractionDigits:0})+'<span style="font-size:1rem;color:var(--muted)">/mês</span>';
}

function addFaq(){
  const c = document.getElementById('faqsContainer');
  const i = faqCount++;
  const div = document.createElement('div');
  div.className='faq-item'; div.id='faq_'+i;
  div.innerHTML=`
    <button type="button" class="faq-remove" onclick="removeFaq(this)" title="Remover">✕</button>
    <div class="form-group">
      <label class="lbl">Pergunta</label>
      <input type="text" class="form-control" name="pergunta[]" placeholder="Ex: Como funciona o pagamento?" required>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="lbl">Resposta</label>
      <textarea class="form-control" name="resposta[]" rows="2" required></textarea>
    </div>`;
  c.appendChild(div);
  div.querySelector('input').focus();
  div.scrollIntoView({behavior:'smooth',block:'nearest'});
}

function removeFaq(btn){
  btn.closest('.faq-item').remove();
}

function excluirPlano(id, nome){
  document.getElementById('excluirId').value = id;
  document.getElementById('excluirNome').textContent = nome;
  document.getElementById('modalExcluir').classList.add('active');
}
function fecharModalExcluir(){
  document.getElementById('modalExcluir').classList.remove('active');
}

// Scroll para topo ao salvar
<?php if ($msg): ?>
window.scrollTo({top:0,behavior:'smooth'});
<?php endif ?>
</script>
</body>
</html>
