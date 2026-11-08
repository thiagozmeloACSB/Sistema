<?php
// planos.php é página PÚBLICA — não exige login
$desconto = 20;
$trial    = 7;
$planos   = [];
$faqs_db  = [];
$cores    = ['#60a5fa','#78be20','#fb923c'];

// Tenta carregar do banco (opcional — funciona mesmo sem banco)
try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
    $planos = db()->query("SELECT * FROM planos WHERE ativo=1 ORDER BY ordem")->fetchAll();
    $cfgs = [];
    $st = db()->query("SELECT chave, valor FROM configuracoes");
    foreach ($st->fetchAll() as $row) $cfgs[$row['chave']] = $row['valor'];
    $faqs_db  = json_decode($cfgs['faqs']              ?? '[]', true) ?: [];
    $desconto = (int)($cfgs['desconto_anual_pct']      ?? 20);
    $trial    = (int)($cfgs['trial_dias']              ?? 7);
} catch(Exception $e) {
    // Usa preços padrão se banco indisponível
    $planos = [
        ['id'=>1,'nome'=>'START',   'preco_centavos'=>9700,  'destaque'=>0,'ativo'=>1,'ordem'=>1,'max_clientes'=>3,  'max_criativos_mes'=>30],
        ['id'=>2,'nome'=>'PRO',     'preco_centavos'=>19700, 'destaque'=>1,'ativo'=>1,'ordem'=>2,'max_clientes'=>10, 'max_criativos_mes'=>100],
        ['id'=>3,'nome'=>'AGÊNCIA', 'preco_centavos'=>34700, 'destaque'=>0,'ativo'=>1,'ordem'=>3,'max_clientes'=>-1, 'max_criativos_mes'=>-1],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Planos — Opa Studio</title>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0d0d0d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Opa Studio">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;
  --accent:#78be20;--accent2:#4a9912;--text:#e8eaf0;--muted:#585858;--success:#4ade80}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh}
body::before{content:'';position:fixed;inset:0;
  background:radial-gradient(ellipse 80% 50% at 50% -10%,rgba(120,190,32,.08) 0%,transparent 60%);pointer-events:none}
.grid-bg{position:fixed;inset:0;
  background-image:linear-gradient(var(--border) 1px,transparent 1px),
    linear-gradient(90deg,var(--border) 1px,transparent 1px);
  background-size:56px 56px;opacity:.25;pointer-events:none}
nav{max-width:1100px;margin:0 auto;padding:1.5rem 2rem;display:flex;align-items:center;justify-content:space-between}
.logo{font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:3px;color:var(--accent)}
.nav-link{color:var(--muted);text-decoration:none;font-size:.875rem;transition:color .15s}
.nav-link:hover{color:var(--text)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 22px;border-radius:8px;
  font-family:'DM Sans',sans-serif;font-size:.875rem;font-weight:600;cursor:pointer;
  border:none;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--accent);color:#0d0d0d}
.btn-primary:hover{background:#d4e83e;transform:translateY(-1px)}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
.btn-ghost:hover{background:var(--surface2)}
/* HERO */
.hero{text-align:center;padding:4rem 2rem 2rem;position:relative;z-index:1}
.hero-eyebrow{font-family:'JetBrains Mono',monospace;font-size:.75rem;color:var(--accent);
  letter-spacing:3px;text-transform:uppercase;margin-bottom:1.25rem;
  display:inline-flex;align-items:center;gap:8px}
.hero-eyebrow::before,.hero-eyebrow::after{content:'';width:24px;height:1px;background:var(--accent);opacity:.5}
.hero-title{font-family:'Bebas Neue',sans-serif;font-size:clamp(2.5rem,6vw,4.5rem);
  letter-spacing:3px;line-height:.95;margin-bottom:1.25rem}
.hero-title span{color:var(--accent)}
.hero-sub{font-size:1.05rem;color:var(--muted);max-width:520px;margin:0 auto 2.5rem;line-height:1.6}
/* TOGGLE ANUAL */
.toggle-wrap{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:3rem}
.toggle-label{font-size:.875rem;color:var(--muted)}
.toggle-label.active{color:var(--text);font-weight:600}
.toggle{position:relative;width:44px;height:24px;cursor:pointer}
.toggle input{opacity:0;width:0;height:0}
.slider{position:absolute;inset:0;background:var(--surface2);border:1px solid var(--border);border-radius:12px;transition:.3s}
.slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;
  background:var(--muted);border-radius:50%;transition:.3s}
input:checked+.slider{background:var(--accent);border-color:var(--accent)}
input:checked+.slider::before{transform:translateX(20px);background:#0d0d0d}
.desconto-badge{background:rgba(74,222,128,.12);color:var(--success);
  border:1px solid rgba(74,222,128,.25);border-radius:20px;padding:3px 10px;
  font-size:.72rem;font-weight:700;font-family:'JetBrains Mono',monospace;letter-spacing:.5px}
/* CARDS */
.plans-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;
  max-width:1000px;margin:0 auto;padding:0 2rem 5rem;position:relative;z-index:1}
.plan-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;
  padding:2rem;position:relative;overflow:hidden;transition:all .25s}
.plan-card:hover{transform:translateY(-4px);box-shadow:0 20px 60px rgba(0,0,0,.4)}
.plan-card.destaque{border-color:var(--accent);background:linear-gradient(160deg,#141414 0%,#141a0e 100%)}
.plan-card.destaque::before{content:'MAIS POPULAR';position:absolute;top:1.5rem;right:1.5rem;
  font-family:'JetBrains Mono',monospace;font-size:.62rem;font-weight:700;letter-spacing:1.5px;
  color:#0d0d0d;background:var(--accent);padding:3px 10px;border-radius:4px}
.plan-accent-line{position:absolute;top:0;left:0;right:0;height:3px}
.plan-name{font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:2px;margin-bottom:.25rem}
.plan-desc{font-size:.8rem;color:var(--muted);margin-bottom:1.75rem;min-height:36px}
.plan-price{margin-bottom:1.75rem}
.plan-price-prefix{font-size:.8rem;color:var(--muted);font-family:'JetBrains Mono',monospace}
.plan-price-val{font-family:'Bebas Neue',sans-serif;font-size:3rem;line-height:1;color:var(--text)}
.plan-price-val span{font-size:1.2rem;color:var(--muted)}
.plan-price-period{font-size:.75rem;color:var(--muted);margin-top:2px}
.plan-price-old{font-size:.95rem;color:var(--muted);text-decoration:line-through;
  font-family:'JetBrains Mono',monospace;display:none;margin-bottom:2px;opacity:.65}
.plan-price-old.visible{display:block}
.plan-price-discount{display:inline-block;font-size:.65rem;font-family:'JetBrains Mono',monospace;
  font-weight:700;letter-spacing:1px;background:var(--accent);color:#0d0d0d;
  padding:2px 7px;border-radius:4px;margin-left:6px;vertical-align:middle;
  opacity:0;transition:opacity .25s}
.plan-price-discount.visible{opacity:1}
.plan-features{list-style:none;margin-bottom:2rem}
.plan-features li{display:flex;align-items:center;gap:10px;padding:7px 0;
  font-size:.875rem;border-bottom:1px solid var(--border)}
.plan-features li:last-child{border-bottom:none}
.feat-icon{width:18px;height:18px;border-radius:4px;display:flex;align-items:center;
  justify-content:center;font-size:.7rem;flex-shrink:0}
.feat-ok{background:rgba(74,222,128,.15);color:var(--success)}
.feat-no{background:rgba(90,95,112,.1);color:var(--muted)}
.plan-cta{display:block;text-align:center}
/* FAQ */
.faq{max-width:700px;margin:0 auto;padding:0 2rem 5rem;position:relative;z-index:1}
.faq-title{font-family:'Bebas Neue',sans-serif;font-size:2.2rem;letter-spacing:2px;
  text-align:center;margin-bottom:2rem;color:var(--text)}
.faq-item{border:1px solid var(--border);border-radius:12px;margin-bottom:.75rem;overflow:hidden}
.faq-q{padding:1.1rem 1.25rem;cursor:pointer;display:flex;justify-content:space-between;
  align-items:center;font-weight:500;font-size:.9rem;user-select:none}
.faq-q:hover{background:var(--surface2)}
.faq-icon{color:var(--muted);transition:transform .2s;font-size:1.1rem}
.faq-a{max-height:0;overflow:hidden;transition:max-height .3s ease;font-size:.875rem;color:var(--muted);line-height:1.7}
.faq-item.open .faq-a{max-height:200px}
.faq-item.open .faq-icon{transform:rotate(45deg);color:var(--accent)}
.faq-a-inner{padding:.25rem 1.25rem 1.25rem}
@media(max-width:768px){.plans-grid{grid-template-columns:1fr}.plan-card.destaque::before{display:none}}
</style>

<script>
if('serviceWorker' in navigator){
  window.addEventListener('load', ()=>{
    navigator.serviceWorker.register('/sw.js')
      .then(r => console.log('SW registrado:', r.scope))
      .catch(e => console.warn('SW erro:', e));
  });
}
</script>
</head>
<body>
<div class="grid-bg"></div>

<nav>
  <div class="logo">◈ OPA STUDIO</div>
  <div style="display:flex;gap:12px;align-items:center">
    <a href="login.php" class="nav-link">Entrar</a>
    <a href="register.php" class="btn btn-primary">Começar grátis</a>
  </div>
</nav>

<div class="hero">
  <div class="hero-eyebrow">◈ Planos e Preços</div>
  <h1 class="hero-title">Simples, transparente<br><span>sem surpresas</span></h1>
  <p class="hero-sub">7 dias grátis em qualquer plano. Sem cartão de crédito para começar. Cancele quando quiser.</p>
  <div class="toggle-wrap">
    <span class="toggle-label active" id="lblMensal">Mensal</span>
    <label class="toggle">
      <input type="checkbox" id="toggleAnual" onchange="togglePeriodo()">
      <span class="slider"></span>
    </label>
    <span class="toggle-label" id="lblAnual">Anual</span>
    <span class="desconto-badge" id="descontoBadge" style="opacity:.4">-20%</span>
  </div>
</div>

<div class="plans-grid">
<?php foreach ($planos as $i => $p):
  $preco_mensal = $p['preco_centavos'] / 100;
  $preco_anual  = round($preco_mensal * 0.8, 2);
  $max_cli = $p['max_clientes'] == -1 ? 'Ilimitados' : $p['max_clientes'];
  $max_cr  = $p['max_criativos_mes'] == -1 ? 'Ilimitados' : $p['max_criativos_mes'] . '/mês';
  $cor     = $cores[$i] ?? '#78be20';
?>
<div class="plan-card <?= $p['destaque'] ? 'destaque' : '' ?>">
  <div class="plan-accent-line" style="background:<?= $cor ?>"></div>
  <div class="plan-name" style="color:<?= $cor ?>"><?= htmlspecialchars($p['nome']) ?></div>
  <div class="plan-desc"><?= $p['destaque'] ? 'O favorito de quem está crescendo rápido' : ($i===0 ? 'Perfeito para começar e validar o serviço' : 'Para agências sem limite de crescimento') ?></div>
  <div class="plan-price">
    <div class="plan-price-old" id="preco-old-<?= $p['id'] ?>">
      R$ <?= number_format($preco_mensal,0,'','') ?>/mês
    </div>
    <div class="plan-price-prefix">
      R$<span class="plan-price-discount" id="desc-badge-<?= $p['id'] ?>">-<?= $desconto ?>%</span>
    </div>
    <div class="plan-price-val"
         data-mensal="<?= number_format($preco_mensal,0,'','') ?>"
         data-anual="<?= number_format($preco_anual,0,'','') ?>">
      <?= number_format($preco_mensal,0,'','') ?><span>/mês</span>
    </div>
    <div class="plan-price-period" id="periodo-<?= $p['id'] ?>">cobrado mensalmente</div>
  </div>
  <ul class="plan-features">
    <li><span class="feat-icon feat-ok">✓</span><?= $max_cli ?> clientes ativos</li>
    <li><span class="feat-icon feat-ok">✓</span><?= $max_cr ?> criativos</li>
    <li><span class="feat-icon feat-ok">✓</span>Área do cliente com login</li>
    <li><span class="feat-icon feat-ok">✓</span>Dashboard de gestão</li>
    <li><span class="feat-icon <?= $p['destaque'] || $i===2 ? 'feat-ok' : 'feat-no' ?>">
      <?= $p['destaque'] || $i===2 ? '✓' : '–' ?></span>Relatórios avançados</li>
    <li><span class="feat-icon <?= $i===2 ? 'feat-ok' : 'feat-no' ?>">
      <?= $i===2 ? '✓' : '–' ?></span>Suporte prioritário</li>
  </ul>
  <a href="register.php?plano=<?= $p['id'] ?>" class="btn <?= $p['destaque'] ? 'btn-primary' : 'btn-ghost' ?> plan-cta">
    Começar <?= $trial ?> dias grátis →
  </a>
</div>
<?php endforeach ?>
</div>

<div class="faq">
  <div class="faq-title">Perguntas Frequentes</div>
  <?php if ($faqs_db): foreach ($faqs_db as $faq): ?>
  <div class="faq-item" onclick="toggleFaq(this)">
    <div class="faq-q"><?= htmlspecialchars($faq['pergunta']) ?><span class="faq-icon">+</span></div>
    <div class="faq-a"><div class="faq-a-inner"><?= htmlspecialchars($faq['resposta']) ?></div></div>
  </div>
  <?php endforeach; else: ?>
  <div style="color:var(--muted);text-align:center;padding:2rem;font-size:.875rem">Nenhuma FAQ cadastrada ainda.</div>
  <?php endif ?>
</div>

<script>
const DESCONTO_ANUAL = <?= $desconto ?>;
let anual = false;
function togglePeriodo() {
  anual = document.getElementById('toggleAnual').checked;
  document.getElementById('lblMensal').classList.toggle('active', !anual);
  document.getElementById('lblAnual').classList.toggle('active', anual);
  document.getElementById('descontoBadge').textContent = '-' + DESCONTO_ANUAL + '%';
  document.getElementById('descontoBadge').style.opacity = anual ? '1' : '.4';

  document.querySelectorAll('.plan-price-val').forEach(el => {
    const mensal   = parseInt(el.dataset.mensal);
    const anualVal = Math.round(mensal * (1 - DESCONTO_ANUAL / 100));
    const val      = anual ? anualVal : mensal;

    el.innerHTML = val + '<span>/mês</span>';

    // Preço riscado
    const card  = el.closest('.plan-card');
    const oldEl = card ? card.querySelector('.plan-price-old') : null;
    const bdgEl = card ? card.querySelector('.plan-price-discount') : null;

    if (oldEl) {
      oldEl.textContent = 'R$ ' + mensal + '/mês';
      oldEl.classList.toggle('visible', anual);
    }
    if (bdgEl) bdgEl.classList.toggle('visible', anual);
  });

  document.querySelectorAll('[id^="periodo-"]').forEach(el => {
    el.textContent = anual ? 'cobrado anualmente' : 'cobrado mensalmente';
  });
};
}
function toggleFaq(el) { el.classList.toggle('open'); }
</script>
</body>
</html>
