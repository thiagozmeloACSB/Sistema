<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/meta_api.php';
exigeLogin('admin');
exigeTenantAtivo();

$admin  = usuarioLogado();
$tenant = tenantAtual();
$tid    = $tenant['id'];

// Carrega clientes com meta_configs completos
$clientes = db()->prepare("
    SELECT c.id, c.nome_empresa, c.cor,
           m.access_token, m.ad_account_id, m.page_id, m.ig_business_id,
           m.token_status, m.pixel_id
    FROM clientes c
    LEFT JOIN meta_configs m ON m.cliente_id = c.id AND m.tenant_id = ?
    WHERE c.tenant_id = ?
    ORDER BY c.nome_empresa
");
$clientes->execute([$tid, $tid]);
$clientes = $clientes->fetchAll();

// Publicações recentes
$pubs = db()->prepare("
    SELECT p.*, c.nome_empresa, c.cor
    FROM publicacoes p
    JOIN clientes c ON c.id = p.cliente_id
    WHERE p.tenant_id = ?
    ORDER BY p.criado_em DESC LIMIT 20
");
$pubs->execute([$tid]);
$publicacoes = $pubs->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics & Publicação — <?= htmlspecialchars($tenant['nome_empresa']) ?></title>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0d0d0d">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;
  --accent:#78be20;--accent2:#4a9912;--text:#e8eaf0;--muted:#585858;
  --success:#4ade80;--warning:#fbbf24;--danger:#f87171;--ig:#e1306c;--fb:#1877f2}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh}
header{position:sticky;top:0;z-index:100;background:rgba(13,13,13,.93);backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);padding:0 2rem;display:flex;align-items:center;justify-content:space-between;height:64px}
.logo{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:2px;color:var(--accent);display:flex;align-items:center;gap:10px}
.logo small{color:var(--muted);font-size:.8rem;font-family:'JetBrains Mono',monospace}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.875rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--accent);color:#0d0d0d}.btn-primary:hover{background:#d4e83e}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface2);color:var(--text)}
.btn-sm{padding:6px 14px;font-size:.8rem}
.btn-ig{background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);color:#fff;border:none}
.btn-fb{background:#1877f2;color:#fff;border:none}
main{max-width:1300px;margin:0 auto;padding:2rem}
/* CLIENTE SELECTOR */
.cli-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:2rem}
.cli-tab{padding:8px 18px;border-radius:30px;font-size:.85rem;font-weight:600;cursor:pointer;
  border:1px solid var(--border);background:var(--surface);transition:all .15s;color:var(--muted)}
.cli-tab.active{color:#0d0d0d}
.cli-tab:hover:not(.active){background:var(--surface2);color:var(--text)}
.cli-tab.sem-config{opacity:.5}
/* SECTION */
.section-title{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:2px;margin-bottom:1.25rem}
/* STATS GRID */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.5rem;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-ig::before{background:linear-gradient(90deg,#f09433,#e6683c,#dc2743)}
.stat-fb::before{background:#1877f2}
.stat-ads::before{background:#fbbc04}
.stat-label{font-size:.68rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:6px;display:flex;align-items:center;gap:6px}
.stat-value{font-family:'Bebas Neue',sans-serif;font-size:2rem;line-height:1;color:var(--text)}
.stat-sub{font-size:.72rem;color:var(--muted);margin-top:3px;font-family:'JetBrains Mono',monospace}
.loading-spin{display:inline-block;width:20px;height:20px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
/* POSTS GRID */
.posts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:2rem}
.post-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;transition:all .2s;cursor:pointer}
.post-card:hover{transform:translateY(-2px);border-color:var(--accent)}
.post-img{width:100%;height:140px;object-fit:cover;background:var(--surface2);display:block}
.post-img-placeholder{width:100%;height:140px;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:1.5rem}
.post-meta{padding:.75rem}
.post-caption{font-size:.78rem;color:var(--text);line-height:1.4;margin-bottom:.5rem;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.post-stats{display:flex;gap:10px;font-size:.72rem;color:var(--muted);font-family:'JetBrains Mono',monospace}
.post-stat{display:flex;align-items:center;gap:3px}
.post-date{font-size:.65rem;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace}
/* ADS TABLE */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:2rem}
table{width:100%;border-collapse:collapse}
thead th{padding:12px 16px;text-align:left;font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;background:var(--surface2);border-bottom:1px solid var(--border)}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:last-child{border-bottom:none}tbody tr:hover{background:var(--surface2)}
tbody td{padding:12px 16px;font-size:.875rem}
.td-mono{font-family:'JetBrains Mono',monospace;font-size:.75rem;color:var(--muted)}
.campaign-status{display:inline-flex;align-items:center;padding:2px 10px;border-radius:20px;font-size:.7rem;font-weight:700;background:rgba(74,222,128,.1);color:var(--success);border:1px solid rgba(74,222,128,.2)}
/* PUBLICAÇÃO */
.pub-form{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.75rem;margin-bottom:2rem}
.plat-select{display:flex;gap:10px;margin-bottom:1.25rem}
.plat-opt{flex:1;padding:12px;border-radius:10px;border:1px solid var(--border);cursor:pointer;
  text-align:center;font-weight:600;font-size:.85rem;transition:all .15s;background:var(--surface2)}
.plat-opt.ig.active{background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366);color:#fff;border-color:transparent}
.plat-opt.fb.active{background:#1877f2;color:#fff;border-color:transparent}
.plat-opt:hover:not(.active){border-color:var(--accent)}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);
  padding:10px 13px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;transition:border-color .15s}
.form-control:focus{border-color:var(--accent)}.form-control::placeholder{color:var(--muted)}
textarea.form-control{resize:vertical;min-height:100px}
label.lbl{display:block;font-size:.68rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:7px;margin-top:1rem}
.img-preview{width:100%;max-height:240px;object-fit:contain;border-radius:8px;border:1px solid var(--border);display:none;margin-top:8px;background:var(--surface2)}
.upload-zone{border:2px dashed var(--border);border-radius:10px;padding:1.25rem;text-align:center;cursor:pointer;transition:all .2s;background:var(--surface2);margin-top:.5rem}
.upload-zone:hover{border-color:var(--accent)}.upload-zone input{display:none}
/* HISTÓRICO PUBLICAÇÕES */
.pub-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.pub-item:last-child{border-bottom:none}
.pub-thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;background:var(--surface2);flex-shrink:0}
.pub-info{flex:1;min-width:0}
.pub-caption{font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pub-meta{font-size:.72rem;color:var(--muted);font-family:'JetBrains Mono',monospace;margin-top:2px}
.pub-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700}
.pub-badge.publicado{background:rgba(74,222,128,.1);color:var(--success);border:1px solid rgba(74,222,128,.2)}
.pub-badge.erro{background:rgba(248,113,113,.1);color:var(--danger);border:1px solid rgba(248,113,113,.2)}
.pub-badge.rascunho{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}
/* AVISO */
.aviso{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:12px 16px;
  font-size:.82rem;color:var(--warning);margin-bottom:1.25rem;font-family:'JetBrains Mono',monospace;line-height:1.6}
/* SEM CONFIG */
.sem-config-state{text-align:center;padding:3rem;color:var(--muted)}
.sem-config-state a{color:var(--accent);text-decoration:none;font-weight:600}
/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(6px);z-index:200;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.active{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:500px;padding:2rem;animation:modalIn .25s ease;max-height:90vh;overflow-y:auto}
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(12px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}
.modal-title{font-family:'Bebas Neue',sans-serif;font-size:1.3rem;letter-spacing:2px;color:var(--accent)}
.modal-close{background:transparent;border:none;color:var(--muted);font-size:1.4rem;cursor:pointer}.modal-close:hover{color:var(--text)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:1.5rem}
/* TOAST */
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:300;display:flex;flex-direction:column;gap:8px}
.toast{background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--accent);color:var(--text);padding:11px 18px;border-radius:10px;font-size:.875rem;font-weight:500;animation:toastIn .3s ease,toastOut .3s ease 2.7s both}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{to{opacity:0;transform:translateX(20px)}}
@media(max-width:768px){main{padding:1rem}.stats-grid{grid-template-columns:repeat(2,1fr)}.posts-grid{grid-template-columns:repeat(2,1fr)}.plat-select{flex-direction:column}}
</style>
</head>
<body>

<header>
  <div class="logo">◈ <?= htmlspecialchars($tenant['nome_empresa']) ?> <small>analytics & publicação</small></div>
  <div style="display:flex;gap:10px;align-items:center">
    <a href="admin.php" class="btn btn-ghost btn-sm">← Painel</a>
    <a href="logout.php" class="btn btn-ghost btn-sm">Sair</a>
  </div>
</header>

<main>

  <!-- SELETOR DE CLIENTE -->
  <div style="margin-bottom:1.5rem">
    <div style="font-size:.72rem;color:var(--muted);font-family:'JetBrains Mono',monospace;letter-spacing:1px;text-transform:uppercase;margin-bottom:.75rem">Selecione o cliente</div>
    <div class="cli-tabs">
      <?php foreach ($clientes as $c): ?>
      <button class="cli-tab <?= !$c['access_token'] ? 'sem-config' : '' ?>"
              style="<?= $c['access_token'] ? '--cor:'.$c['cor'] : '' ?>"
              onclick="selecionarCliente(<?= $c['id'] ?>)"
              data-id="<?= $c['id'] ?>"
              title="<?= $c['access_token'] ? '' : 'Token Meta não configurado' ?>">
        <?= htmlspecialchars($c['nome_empresa']) ?>
        <?= !$c['access_token'] ? ' ⚠️' : '' ?>
      </button>
      <?php endforeach ?>
    </div>
  </div>

  <!-- CONTEÚDO DINÂMICO -->
  <div id="conteudo">
    <div style="text-align:center;padding:4rem;color:var(--muted)">
      <div style="font-size:2rem;margin-bottom:1rem">◈</div>
      <div>Selecione um cliente para ver os analytics</div>
    </div>
  </div>

</main>

<!-- MODAL DETALHE POST -->
<div class="modal-overlay" id="modalPost" onclick="if(event.target===this)document.getElementById('modalPost').classList.remove('active')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Detalhes do Post</div>
      <button class="modal-close" onclick="document.getElementById('modalPost').classList.remove('active')">✕</button>
    </div>
    <img id="postDetailImg" src="" alt="" style="width:100%;max-height:300px;object-fit:contain;border-radius:10px;background:var(--surface2);display:none;margin-bottom:1rem">
    <div id="postDetailContent"></div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
// ── DATA ──────────────────────────────────────────────────
const CLIENTES = <?= json_encode($clientes, JSON_UNESCAPED_UNICODE) ?>;
const PUBS     = <?= json_encode($publicacoes, JSON_UNESCAPED_UNICODE) ?>;
let clienteAtivo = null;
let pubFile = null;
let pubPlat = 'instagram';

// ── HELPERS ───────────────────────────────────────────────
async function apiFetch(action, data=null){
  const r = await fetch(`api.php?action=${action}`,{
    method:data?'POST':'GET',credentials:'include',
    headers:{'Content-Type':'application/json'},
    body:data?JSON.stringify(data):null
  });
  return r.json();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function num(n){return Number(n||0).toLocaleString('pt-BR');}

function toast(msg,tipo='success'){
  const c=document.getElementById('toastContainer');
  const t=document.createElement('div');
  t.className='toast';if(tipo==='danger')t.style.borderLeftColor='var(--danger)';
  t.textContent=msg;c.appendChild(t);setTimeout(()=>t.remove(),3200);
}

// ── SELEÇÃO DE CLIENTE ────────────────────────────────────
async function selecionarCliente(id){
  document.querySelectorAll('.cli-tab').forEach(b=>{
    b.classList.toggle('active', parseInt(b.dataset.id)===id);
    const c = CLIENTES.find(x=>x.id==id);
    if(parseInt(b.dataset.id)===id && c?.cor){
      b.style.background=c.cor;b.style.color='#0d0d0d';b.style.borderColor=c.cor;
    } else {
      b.style.background='';b.style.color='';b.style.borderColor='';
    }
  });

  clienteAtivo = CLIENTES.find(c=>c.id==id);
  if(!clienteAtivo) return;

  if(!clienteAtivo.access_token){
    document.getElementById('conteudo').innerHTML = `
      <div class="sem-config-state">
        <div style="font-size:2.5rem;margin-bottom:1rem">⚠️</div>
        <div style="font-size:1rem;font-weight:600;margin-bottom:.5rem">Token Meta não configurado</div>
        <div style="font-size:.85rem;margin-bottom:1.5rem">Configure o token na aba Meta Ads do painel principal.</div>
        <a href="admin.php" class="btn btn-ghost">← Ir para Meta Ads</a>
      </div>`;
    return;
  }

  renderLoading();
  await carregarAnalytics(clienteAtivo);
}

function renderLoading(){
  document.getElementById('conteudo').innerHTML = `
    <div style="text-align:center;padding:4rem">
      <div class="loading-spin" style="width:40px;height:40px;margin:0 auto 1rem"></div>
      <div style="color:var(--muted);font-family:JetBrains Mono,monospace;font-size:.82rem">Buscando dados do Meta...</div>
    </div>`;
}

// ── ANALYTICS ─────────────────────────────────────────────
async function carregarAnalytics(cli){
  try{
    const r = await apiFetch('meta_analytics&cliente_id=' + cli.id);
    if(!r.ok){ renderErro(r.erro); return; }
    renderAnalytics(cli, r);
  }catch(e){ renderErro('Erro de conexão'); }
}

function renderErro(msg){
  document.getElementById('conteudo').innerHTML = `
    <div class="sem-config-state">
      <div style="font-size:2rem;margin-bottom:1rem">❌</div>
      <div style="font-weight:600;margin-bottom:.5rem">Erro ao carregar dados</div>
      <div style="font-size:.82rem;color:var(--danger);font-family:JetBrains Mono,monospace">${esc(msg)}</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:.75rem">Verifique se o token está válido e tem as permissões corretas.</div>
    </div>`;
}

function renderAnalytics(cli, data){
  const ig   = data.instagram || {};
  const fb   = data.facebook  || {};
  const ads  = data.ads       || {};
  const pubs = PUBS.filter(p => p.cliente_id == cli.id);

  let html = '';

  // ── INSTAGRAM ──
  if(ig.info && !ig.error){
    const info = ig.info;
    html += `<div class="section-title">📸 Instagram — @${esc(info.username||'')}</div>`;

    // Stats
    let impressoes = 0, alcance = 0, visitas = 0;
    (ig.insights||[]).forEach(ins => {
      const v = ins.values?.[0]?.value || 0;
      if(ins.name==='impressions') impressoes=v;
      if(ins.name==='reach') alcance=v;
      if(ins.name==='profile_views') visitas=v;
    });

    html += `<div class="stats-grid">
      <div class="stat-card stat-ig">
        <div class="stat-label">📸 Seguidores</div>
        <div class="stat-value">${num(info.followers_count)}</div>
        <div class="stat-sub">Seguindo: ${num(info.follows_count)}</div>
      </div>
      <div class="stat-card stat-ig">
        <div class="stat-label">📊 Impressões (28d)</div>
        <div class="stat-value">${num(impressoes)}</div>
      </div>
      <div class="stat-card stat-ig">
        <div class="stat-label">👁 Alcance (28d)</div>
        <div class="stat-value">${num(alcance)}</div>
      </div>
      <div class="stat-card stat-ig">
        <div class="stat-label">🔍 Visitas perfil (28d)</div>
        <div class="stat-value">${num(visitas)}</div>
      </div>
    </div>`;

    // Posts recentes
    if(ig.media?.length){
      html += `<div style="font-family:'Bebas Neue',sans-serif;font-size:1.1rem;letter-spacing:1.5px;color:var(--text);margin-bottom:1rem">Postagens Recentes</div>`;
      html += `<div class="posts-grid">`;
      ig.media.forEach(post => {
        const img = post.media_url || post.thumbnail_url || '';
        const data_post = new Date(post.timestamp).toLocaleDateString('pt-BR');
        const caption = (post.caption||'Sem legenda').substring(0,80);
        html += `<div class="post-card" onclick="verPost(${JSON.stringify(post).replace(/"/g,'&quot;')})">
          ${img
            ? `<img src="${esc(img)}" class="post-img" alt="" onerror="this.style.display='none';this.nextSibling.style.display='flex'">`
            : ''
          }
          <div class="post-img-placeholder" ${img?'style="display:none"':''}>🖼️</div>
          <div class="post-meta">
            <div class="post-caption">${esc(caption)}</div>
            <div class="post-stats">
              <span class="post-stat">❤️ ${num(post.like_count)}</span>
              <span class="post-stat">💬 ${num(post.comments_count)}</span>
            </div>
            <div class="post-date">${data_post}</div>
          </div>
        </div>`;
      });
      html += `</div>`;
    }
  }

  // ── FACEBOOK PAGE ──
  if(fb.info && !fb.error){
    html += `<div class="section-title" style="margin-top:1.5rem">📘 Facebook — ${esc(fb.info.name||'')}</div>`;
    html += `<div class="stats-grid">
      <div class="stat-card stat-fb">
        <div class="stat-label">👍 Curtidas na Página</div>
        <div class="stat-value">${num(fb.info.fan_count)}</div>
      </div>
      <div class="stat-card stat-fb">
        <div class="stat-label">👥 Seguidores</div>
        <div class="stat-value">${num(fb.info.followers_count)}</div>
      </div>
    </div>`;
  }

  // ── META ADS ──
  if(ads.insights && Object.keys(ads.insights).length){
    const a = ads.insights;
    html += `<div class="section-title" style="margin-top:1.5rem">💰 Meta Ads — Últimos 30 dias</div>`;
    html += `<div class="stats-grid">
      <div class="stat-card stat-ads">
        <div class="stat-label">💸 Gasto total</div>
        <div class="stat-value">R$${num(parseFloat(a.spend||0).toFixed(2))}</div>
      </div>
      <div class="stat-card stat-ads">
        <div class="stat-label">👁 Impressões</div>
        <div class="stat-value">${num(a.impressions)}</div>
      </div>
      <div class="stat-card stat-ads">
        <div class="stat-label">🎯 Alcance</div>
        <div class="stat-value">${num(a.reach)}</div>
      </div>
      <div class="stat-card stat-ads">
        <div class="stat-label">🖱 Cliques</div>
        <div class="stat-value">${num(a.clicks)}</div>
      </div>
      <div class="stat-card stat-ads">
        <div class="stat-label">CPC médio</div>
        <div class="stat-value" style="font-size:1.5rem">R$${parseFloat(a.cpc||0).toFixed(2)}</div>
      </div>
      <div class="stat-card stat-ads">
        <div class="stat-label">CTR</div>
        <div class="stat-value" style="font-size:1.5rem">${parseFloat(a.ctr||0).toFixed(2)}%</div>
      </div>
    </div>`;

    // Campanhas ativas
    if(ads.campaigns?.length){
      html += `<div style="font-family:'Bebas Neue',sans-serif;font-size:1.1rem;letter-spacing:1.5px;color:var(--text);margin-bottom:1rem">Campanhas Ativas</div>`;
      html += `<div class="table-wrap"><table>
        <thead><tr><th>Campanha</th><th>Objetivo</th><th>Budget diário</th><th>Status</th></tr></thead>
        <tbody>`;
      ads.campaigns.forEach(camp => {
        const budget = camp.daily_budget ? 'R$' + (parseInt(camp.daily_budget)/100).toFixed(2) : '—';
        html += `<tr>
          <td style="font-weight:500">${esc(camp.name)}</td>
          <td class="td-mono">${esc(camp.objective||'—')}</td>
          <td class="td-mono">${budget}</td>
          <td><span class="campaign-status">ATIVO</span></td>
        </tr>`;
      });
      html += `</tbody></table></div>`;
    }
  }

  // ── PUBLICAÇÃO ──
  html += `<div class="section-title" style="margin-top:2rem">📤 Nova Publicação</div>`;

  const temIg = !!(cli.ig_business_id && cli.access_token);
  const temFb = !!(cli.page_id && cli.access_token);

  if(!temIg && !temFb){
    html += `<div class="aviso">⚠️ Configure o <strong>Instagram Business ID</strong> e/ou <strong>Page ID do Facebook</strong> na aba Meta Ads para habilitar publicação.</div>`;
  } else {
    html += `
    <div class="pub-form">
      ${!temIg ? '<div class="aviso" style="margin-bottom:1rem">⚠️ Instagram Business ID não configurado — só Facebook disponível.</div>' : ''}
      ${!temFb ? '<div class="aviso" style="margin-bottom:1rem">⚠️ Page ID do Facebook não configurado — só Instagram disponível.</div>' : ''}

      <div style="font-family:JetBrains Mono,monospace;font-size:.68rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:.75rem">Plataforma</div>
      <div class="plat-select">
        ${temIg ? `<div class="plat-opt ig active" onclick="pubPlataforma('instagram',this)">📸 Instagram</div>` : ''}
        ${temFb ? `<div class="plat-opt fb ${!temIg?'active':''}" onclick="pubPlataforma('facebook',this)">📘 Facebook</div>` : ''}
      </div>

      <label class="lbl">Legenda / Texto</label>
      <textarea class="form-control" id="pubCaption" placeholder="Digite a legenda do post...&#10;&#10;Inclua hashtags, emojis e chamada para ação"></textarea>

      <label class="lbl">Imagem (obrigatório para Instagram)</label>
      <div class="aviso" style="margin-top:0;margin-bottom:.5rem">
        ⚠️ A imagem precisa estar em uma URL pública acessível pelo Meta (não pode ser local).<br>
        Dica: use um arquivo já upado no sistema ou hospedado no servidor.
      </div>
      <input type="text" class="form-control" id="pubImageUrl" placeholder="https://sistema.opadesign.com.br/uploads/criativos/...">
      <div id="pubImgPreview" style="margin-top:.5rem"></div>

      <div id="pubLoadingBar" style="display:none;margin-top:1rem">
        <div style="background:var(--surface2);border-radius:4px;overflow:hidden;height:5px">
          <div style="height:100%;background:var(--accent);animation:progresso 2s ease infinite;width:60%"></div>
        </div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:4px;font-family:JetBrains Mono,monospace">Publicando...</div>
      </div>
      <style>@keyframes progresso{0%{margin-left:0;width:30%}50%{margin-left:35%;width:30%}100%{margin-left:70%;width:30%}}</style>

      <div style="display:flex;gap:10px;margin-top:1.25rem">
        <button class="btn" id="btnPubPreview" onclick="previewImagem()" style="background:var(--surface2);color:var(--text);border:1px solid var(--border)">👁 Preview</button>
        <button class="btn btn-primary" onclick="publicar()">📤 Publicar Agora</button>
      </div>
    </div>`;
  }

  // ── HISTÓRICO ──
  if(pubs.length){
    html += `<div class="section-title" style="margin-top:1.5rem">📋 Histórico de Publicações</div>`;
    html += `<div class="table-wrap" style="padding:1rem 1.5rem">`;
    pubs.forEach(p => {
      const dataPub = new Date(p.criado_em).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
      html += `<div class="pub-item">
        ${p.image_url ? `<img src="${esc(p.image_url)}" class="pub-thumb" onerror="this.style.display='none'">` : '<div class="pub-thumb" style="display:flex;align-items:center;justify-content:center;color:var(--muted)">🖼️</div>'}
        <div class="pub-info">
          <div class="pub-caption">${esc(p.caption||'(sem legenda)')}</div>
          <div class="pub-meta">${p.plataforma==='instagram'?'📸':'📘'} ${p.plataforma} · ${dataPub}</div>
        </div>
        <span class="pub-badge ${p.status}">${p.status==='publicado'?'✅ Publicado':p.status==='erro'?'❌ Erro':'📝 Rascunho'}</span>
      </div>`;
    });
    html += `</div>`;
  }

  document.getElementById('conteudo').innerHTML = html;
  pubPlat = temIg ? 'instagram' : 'facebook';

  // Preview automático se URL já preenchida
  document.getElementById('pubImageUrl')?.addEventListener('input', ()=>previewImagem());
}

// ── PUBLICAÇÃO ─────────────────────────────────────────────
function pubPlataforma(plat, el){
  pubPlat = plat;
  document.querySelectorAll('.plat-opt').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
}

function previewImagem(){
  const url = document.getElementById('pubImageUrl')?.value.trim();
  const sec = document.getElementById('pubImgPreview');
  if(!url||!sec){ return; }
  sec.innerHTML = `<img src="${esc(url)}" alt="Preview"
    style="max-width:100%;max-height:200px;object-fit:contain;border-radius:8px;border:1px solid var(--border);background:var(--surface2);margin-top:4px"
    onerror="this.parentElement.innerHTML='<div style=color:var(--danger);font-size:.8rem>❌ Imagem não acessível nesta URL</div>'">`;
}

async function publicar(){
  const caption  = document.getElementById('pubCaption')?.value.trim();
  const imageUrl = document.getElementById('pubImageUrl')?.value.trim();

  if(!caption){ toast('Informe a legenda','danger'); return; }
  if(pubPlat==='instagram' && !imageUrl){ toast('Instagram requer imagem','danger'); return; }

  const bar = document.getElementById('pubLoadingBar');
  if(bar) bar.style.display='block';

  try{
    const r = await apiFetch('meta_publicar',{
      cliente_id: clienteAtivo.id,
      plataforma: pubPlat,
      caption, image_url: imageUrl||null
    });
    if(r.ok){
      toast(`✅ Publicado com sucesso no ${pubPlat==='instagram'?'Instagram':'Facebook'}!`);
      document.getElementById('pubCaption').value='';
      document.getElementById('pubImageUrl').value='';
      document.getElementById('pubImgPreview').innerHTML='';
      // Recarrega histórico
      setTimeout(()=>selecionarCliente(clienteAtivo.id),1500);
    } else {
      toast(r.erro||'Erro ao publicar','danger');
    }
  }catch(e){ toast('Erro de conexão','danger'); }
  finally{ if(bar) bar.style.display='none'; }
}

// ── MODAL POST ─────────────────────────────────────────────
function verPost(post){
  if(typeof post === 'string') try{ post=JSON.parse(post); }catch(e){ return; }
  const img = document.getElementById('postDetailImg');
  if(post.media_url||post.thumbnail_url){
    img.src=post.media_url||post.thumbnail_url; img.style.display='block';
  } else { img.style.display='none'; }
  const data = new Date(post.timestamp).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
  document.getElementById('postDetailContent').innerHTML=`
    <div style="display:flex;gap:16px;margin-bottom:1rem">
      <div style="text-align:center"><div style="font-family:'Bebas Neue',sans-serif;font-size:1.5rem">${num(post.like_count)}</div><div style="font-size:.72rem;color:var(--muted)">Curtidas</div></div>
      <div style="text-align:center"><div style="font-family:'Bebas Neue',sans-serif;font-size:1.5rem">${num(post.comments_count)}</div><div style="font-size:.72rem;color:var(--muted)">Comentários</div></div>
    </div>
    <div style="font-size:.78rem;color:var(--muted);font-family:JetBrains Mono,monospace;margin-bottom:.75rem">${data} · ${post.media_type||'POST'}</div>
    ${post.caption?`<div style="font-size:.875rem;line-height:1.6;white-space:pre-wrap">${esc(post.caption)}</div>`:''}
    ${post.permalink?`<a href="${esc(post.permalink)}" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:1rem;display:inline-flex">↗ Ver no Instagram</a>`:''}`;
  document.getElementById('modalPost').classList.add('active');
}
</script>
</body>
</html>
