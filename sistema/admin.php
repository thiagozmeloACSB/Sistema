<?php
require_once __DIR__ . '/auth.php';
exigeLogin('admin');
exigeTenantAtivo();
$admin  = usuarioLogado();
$tenant = tenantAtual();
$plano  = planoAtual();
$cores  = ['#78be20','#4a9912','#4ade80','#60a5fa','#f472b6','#a78bfa','#fb923c','#34d399'];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — <?= htmlspecialchars($tenant['nome_empresa']) ?></title>
<script src="/theme.js"></script>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0d0d0d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Opa Studio">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;
  --accent:#78be20;--accent2:#4a9912;--text:#e8eaf0;--muted:#585858;
  --success:#4ade80;--warning:#fbbf24;--danger:#f87171}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh}
header{position:sticky;top:0;z-index:100;background:rgba(13,13,13,.93);backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);padding:0 2rem;display:flex;align-items:center;justify-content:space-between;height:64px}
.logo{display:flex;align-items:center;gap:10px;font-family:'Bebas Neue',sans-serif;font-size:1rem;letter-spacing:1.5px;color:var(--muted)}
.logo span{color:var(--muted);font-size:.82rem;letter-spacing:1px;font-family:'JetBrains Mono',monospace;font-weight:400}
.header-right{display:flex;align-items:center;gap:10px}
.user-chip{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:6px 14px;font-size:.85rem}
.badge-admin{background:rgba(120,190,32,.15);color:var(--accent);font-family:'JetBrains Mono',monospace;font-size:.65rem;padding:2px 8px;border-radius:4px;letter-spacing:1px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.875rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--accent);color:#0d0d0d}.btn-primary:hover{background:#d4e83e;transform:translateY(-1px)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface2);color:var(--text)}
.btn-sm{padding:6px 14px;font-size:.8rem}
.btn-danger{background:transparent;color:var(--danger);border:1px solid transparent;padding:5px 10px;font-size:.8rem}.btn-danger:hover{background:rgba(248,113,113,.1);border-color:var(--danger)}
.nav-tabs{display:flex;gap:4px;padding:0 2rem;background:var(--surface);border-bottom:1px solid var(--border);overflow-x:auto}
.tab{padding:14px 18px;font-size:.875rem;font-weight:500;cursor:pointer;white-space:nowrap;color:var(--muted);border-bottom:2px solid transparent;transition:all .15s;font-family:'DM Sans',sans-serif;background:none;border-top:none;border-left:none;border-right:none}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}.tab:hover:not(.active){color:var(--text)}
main{max-width:1300px;margin:0 auto;padding:2rem}
.section{display:none}.section.active{display:block}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}
.section-title{font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:2px;color:var(--text)}
.banner{border-radius:10px;padding:11px 18px;font-size:.82rem;margin-bottom:1.5rem;font-family:'JetBrains Mono',monospace;display:flex;align-items:center;justify-content:space-between;gap:12px}
.banner-trial{background:rgba(120,190,32,.07);border:1px solid rgba(120,190,32,.2);color:var(--accent)}
.banner-inad{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);color:var(--danger)}
.banner-meta{background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.2);color:var(--danger)}
.stats-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.5rem;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat-card:nth-child(1)::before{background:var(--accent)}.stat-card:nth-child(2)::before{background:var(--warning)}
.stat-card:nth-child(3)::before{background:var(--success)}.stat-card:nth-child(4)::before{background:var(--accent2)}
.stat-label{font-size:.7rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:8px}
.stat-value{font-family:'Bebas Neue',sans-serif;font-size:2.2rem;line-height:1}
.limit-bar-wrap{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:12px;margin-bottom:1.5rem;font-size:.8rem}
.limit-bar-track{flex:1;height:5px;background:var(--surface2);border-radius:3px;overflow:hidden}
.limit-bar-fill{height:100%;border-radius:3px;transition:width .4s ease;background:var(--accent)}
.limit-bar-fill.warn{background:var(--warning)}.limit-bar-fill.crit{background:var(--danger)}
.limit-label{color:var(--muted);font-family:'JetBrains Mono',monospace;white-space:nowrap}
.clients-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(275px,1fr));gap:1.25rem}
.client-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:1.5rem;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.client-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--cor,var(--accent))}
.client-card:hover{border-color:var(--cor,var(--accent));transform:translateY(-2px);box-shadow:0 8px 32px rgba(0,0,0,.3)}
.card-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem}
.card-avatar{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue',sans-serif;font-size:1.3rem;background:rgba(255,255,255,.06);border:1px solid var(--border)}
.card-actions{display:flex;gap:5px}
.icon-btn{background:transparent;border:none;color:var(--muted);cursor:pointer;padding:6px;border-radius:6px;transition:all .15s;font-size:.95rem;line-height:1}.icon-btn:hover{background:var(--surface2);color:var(--text)}
.card-empresa{font-weight:600;font-size:1rem;margin-bottom:2px}
.card-segmento{font-size:.75rem;color:var(--muted);font-family:'JetBrains Mono',monospace}
.card-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:1rem}
.mini-stat{background:var(--surface2);border-radius:8px;padding:8px 10px}
.mini-stat-label{font-size:.62rem;color:var(--muted);letter-spacing:.8px;text-transform:uppercase;font-family:'JetBrains Mono',monospace}
.mini-stat-val{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;line-height:1.1}
.card-footer{display:flex;align-items:center;justify-content:space-between;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)}
.card-email{font-size:.72rem;color:var(--muted);font-family:'JetBrains Mono',monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:155px}
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:1.5rem;flex-wrap:wrap}
.search-input,.filter-select{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:9px 14px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.875rem;outline:none;transition:border-color .15s}
.search-input::placeholder{color:var(--muted)}.search-input:focus,.filter-select:focus{border-color:var(--accent)}
.search-input{min-width:200px;flex:1}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{padding:12px 18px;text-align:left;font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;background:var(--surface2);border-bottom:1px solid var(--border);font-weight:600}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:last-child{border-bottom:none}tbody tr:hover{background:var(--surface2)}
tbody td{padding:13px 18px;font-size:.875rem}
.td-title{font-weight:500;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.td-mono{font-family:'JetBrains Mono',monospace;font-size:.76rem;color:var(--muted)}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600}
.badge-pendente{background:rgba(251,191,36,.12);color:var(--warning);border:1px solid rgba(251,191,36,.25)}
.badge-producao{background:rgba(120,190,32,.1);color:var(--accent);border:1px solid rgba(120,190,32,.2)}
.badge-finalizado{background:rgba(74,222,128,.1);color:var(--success);border:1px solid rgba(74,222,128,.2)}
.badge-cancelado{background:rgba(248,113,113,.1);color:var(--danger);border:1px solid rgba(248,113,113,.2)}
.plat-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:.74rem;background:var(--surface2);border:1px solid var(--border)}
.cli-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:.74rem;font-weight:600}
.status-sel{background:transparent;border:none;font-family:'DM Sans',sans-serif;font-size:.7rem;font-weight:600;cursor:pointer;outline:none;padding:3px 8px;border-radius:20px}
.empty-state{text-align:center;padding:3rem;color:var(--muted)}
.meta-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.meta-badge.ok{background:rgba(74,222,128,.1);color:var(--success);border:1px solid rgba(74,222,128,.2)}
.meta-badge.expirando{background:rgba(251,191,36,.12);color:var(--warning);border:1px solid rgba(251,191,36,.3)}
.meta-badge.expirado{background:rgba(248,113,113,.12);color:var(--danger);border:1px solid rgba(248,113,113,.3)}
.meta-badge.nao_configurado{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}
.dias-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:6px;font-size:.7rem;font-weight:700;font-family:'JetBrains Mono',monospace}
.token-input-wrap{position:relative}.token-input-wrap input{padding-right:44px}
.toggle-vis{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:4px}.toggle-vis:hover{color:var(--text)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:200;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:520px;padding:2rem;animation:modalIn .25s ease;max-height:90vh;overflow-y:auto}
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(12px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.75rem}
.modal-title{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:2px;color:var(--accent)}
.modal-close{background:transparent;border:none;color:var(--muted);font-size:1.4rem;cursor:pointer;transition:color .15s;padding:4px}.modal-close:hover{color:var(--text)}
.form-group{margin-bottom:1.1rem}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
label.lbl{display:block;font-size:.68rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:7px}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:10px 13px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;transition:border-color .15s}
.form-control:focus{border-color:var(--accent)}.form-control::placeholder{color:var(--muted)}
textarea.form-control{resize:vertical;min-height:80px}
.color-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px}
.color-swatch{width:28px;height:28px;border-radius:6px;cursor:pointer;border:2px solid transparent;transition:all .15s}.color-swatch.active{border-color:var(--text);transform:scale(1.15)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:1.5rem}
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:300;display:flex;flex-direction:column;gap:8px}
.toast{background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--accent);color:var(--text);padding:11px 18px;border-radius:10px;font-size:.875rem;font-weight:500;animation:toastIn .3s ease,toastOut .3s ease 2.7s both;box-shadow:0 8px 24px rgba(0,0,0,.4)}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{to{opacity:0;transform:translateX(20px)}}

/* REVISÃO */
.rev-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.rev-badge.aguardando{background:rgba(90,95,112,.15);color:var(--muted);border:1px solid var(--border)}
.rev-badge.aprovado{background:rgba(74,222,128,.1);color:var(--success);border:1px solid rgba(74,222,128,.2)}
.rev-badge.alteracao{background:rgba(251,191,36,.12);color:var(--warning);border:1px solid rgba(251,191,36,.3)}
.rev-badge.reprovado{background:rgba(248,113,113,.12);color:var(--danger);border:1px solid rgba(248,113,113,.3)}
.stars{display:flex;gap:3px;font-size:1rem}
.upload-zone{border:2px dashed var(--border);border-radius:10px;padding:1.5rem;text-align:center;
  cursor:pointer;transition:all .2s;background:var(--surface2)}
.upload-zone:hover,.upload-zone.drag{border-color:var(--accent);background:rgba(120,190,32,.05)}
.upload-zone input{display:none}
.upload-preview{width:100%;max-height:200px;object-fit:contain;border-radius:8px;margin-top:10px;display:none}
.briefing-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:1.25rem;margin-bottom:1rem}
.briefing-label{font-size:.65rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:5px}
.briefing-val{font-size:.88rem;line-height:1.6;color:var(--text)}
.revisao-block{border-radius:10px;padding:1rem 1.25rem;margin-top:.75rem}
.revisao-block.aprovado{background:rgba(74,222,128,.06);border:1px solid rgba(74,222,128,.15)}
.revisao-block.alteracao{background:rgba(251,191,36,.06);border:1px solid rgba(251,191,36,.15)}
.revisao-block.reprovado{background:rgba(248,113,113,.06);border:1px solid rgba(248,113,113,.15)}


/* LIGHTBOX */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:500;
  align-items:center;justify-content:center;cursor:zoom-out;backdrop-filter:blur(4px)}
.lightbox.active{display:flex}
.lightbox img{max-width:90vw;max-height:88vh;object-fit:contain;border-radius:10px;
  box-shadow:0 20px 80px rgba(0,0,0,.6);cursor:default}
.lightbox-close{position:fixed;top:1.25rem;right:1.5rem;background:rgba(255,255,255,.12);
  border:none;color:#fff;font-size:1.5rem;width:42px;height:42px;border-radius:50%;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:background .15s;z-index:501}
.lightbox-close:hover{background:rgba(255,255,255,.25)}
.lightbox-counter{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
  font-family:'JetBrains Mono',monospace;font-size:.78rem;color:rgba(255,255,255,.6);
  background:rgba(0,0,0,.5);padding:5px 14px;border-radius:20px;pointer-events:none}
.lightbox-nav{position:fixed;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.1);
  border:none;color:#fff;font-size:1.5rem;width:46px;height:46px;border-radius:50%;
  cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s;z-index:501}
.lightbox-nav:hover{background:rgba(255,255,255,.25)}
.lightbox-prev{left:1.25rem}
.lightbox-next{right:1.25rem}



/* ── LOGO EDITÁVEL (hover) ── */
.logo-wrap{position:relative;display:flex;align-items:center;gap:10px;cursor:pointer}
.logo-img{height:48px;width:auto;object-fit:contain;display:block;max-width:185px;border-radius:4px;transition:opacity .2s}
@media(max-width:1024px){.logo-img{height:40px;max-width:160px}}
@media(max-width:680px){.logo-img{height:34px;max-width:140px}}
.logo-img{height:38px;width:auto;object-fit:contain;display:block;max-width:160px;border-radius:4px;transition:opacity .2s}
.logo-overlay{
  position:absolute;inset:0;
  display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.55);border-radius:6px;
  opacity:0;transition:opacity .2s;pointer-events:none;
  font-family:'JetBrains Mono',monospace;font-size:.6rem;
  color:#fff;gap:5px;letter-spacing:1px;text-transform:uppercase;
  white-space:nowrap;padding:0 8px;
}
.logo-overlay svg{flex-shrink:0}
.logo-wrap:hover .logo-overlay{opacity:1;pointer-events:auto}
.logo-wrap:hover .logo-img{opacity:.6}
#logoFileInput{display:none}
/* Modal de upload de logo */
.logo-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);z-index:500;align-items:center;justify-content:center;padding:1rem}
.logo-modal.active{display:flex}
.logo-modal-box{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:2rem;width:100%;max-width:420px;animation:modalIn .25s ease}
.logo-upload-zone{border:2px dashed var(--border);border-radius:10px;padding:2rem;text-align:center;cursor:pointer;transition:all .2s}
.logo-upload-zone:hover,.logo-upload-zone.drag{border-color:var(--accent);background:rgba(120,190,32,.04)}
.logo-preview-wrap{display:none;text-align:center;margin-top:1rem}
.logo-preview-wrap img{max-height:80px;max-width:200px;border-radius:8px;border:1px solid var(--border);object-fit:contain}


/* ── UPLOAD COM CÂMERA ── */
.upload-zone{position:relative}
.upload-cam-btns{
  display:flex;gap:8px;justify-content:center;flex-wrap:wrap;
  margin-top:10px;
}
.cam-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:8px;font-size:.78rem;font-weight:600;
  cursor:pointer;border:1px solid var(--border);background:var(--surface2);
  color:var(--muted);transition:all .15s;font-family:'DM Sans',sans-serif;
}
.cam-btn:hover{border-color:var(--accent);color:var(--text);background:rgba(120,190,32,.08)}
.cam-btn svg{flex-shrink:0}
/* Esconde botões de câmera no desktop, mostra no mobile */
.cam-btn-camera{display:none}
@media(max-width:768px){
  .cam-btn-camera{display:inline-flex}
  .cam-btn-galeria{display:inline-flex}
}
@media(min-width:769px){
  .cam-btn-galeria{display:inline-flex}
}

/* ── TEMA CLARO ── */
[data-tema="light"]{
  --bg:#f5f5f0;--surface:#ffffff;--surface2:#f0f0eb;--border:#ddddd8;
  --accent:#3d7a0a;--accent2:#2a5a06;--text:#1a1a1a;--muted:#888880;
  --success:#2d7a4f;--warning:#8a6200;--danger:#b83232
}
[data-tema="light"] header{background:rgba(245,245,240,.93)}
[data-tema="light"] .logo img{filter:none}
.logo img{border-radius:4px}
[data-tema="light"] .nav-tabs{background:var(--surface)}
body{transition:background .25s,color .25s}
/* TOGGLE TEMA */
.theme-toggle-wrap{display:flex;align-items:center;gap:7px;padding:6px 12px;
  background:var(--surface2);border:1px solid var(--border);border-radius:20px;cursor:pointer}
.theme-toggle-wrap span{font-size:.75rem;color:var(--muted);font-family:'JetBrains Mono',monospace}
.theme-switch{position:relative;width:36px;height:20px;flex-shrink:0}
.theme-switch input{opacity:0;width:0;height:0}
.theme-slider{position:absolute;inset:0;background:var(--surface);border:1px solid var(--border);
  border-radius:20px;transition:.3s;cursor:pointer}
.theme-slider::before{content:'';position:absolute;width:14px;height:14px;left:2px;top:2px;
  background:var(--muted);border-radius:50%;transition:.3s}
.theme-switch input:checked + .theme-slider{background:var(--accent)}
.theme-switch input:checked + .theme-slider::before{transform:translateX(16px);background:#0d0d0d}
/* MOBILE UPLOAD FIX */
@media(max-width:768px){
  .upload-zone{padding:1.5rem 1rem;min-height:80px}
  .upload-zone input[type=file]{display:block!important;opacity:0;position:absolute;inset:0;width:100%;height:100%;cursor:pointer}
  .upload-zone{position:relative}
  .modal{padding:1.25rem}
  .modal .form-row{grid-template-columns:1fr}
  table thead{display:none}
  table tbody tr{display:block;border-bottom:2px solid var(--border);padding:.5rem 0}
  table tbody td{display:flex;justify-content:space-between;padding:5px 12px;font-size:.82rem}
  table tbody td::before{content:attr(data-label);font-size:.65rem;color:var(--muted);font-family:'JetBrains Mono',monospace;letter-spacing:1px;text-transform:uppercase;flex-shrink:0;margin-right:8px}
}

@media(max-width:768px){.stats-bar{grid-template-columns:repeat(2,1fr)}main{padding:1rem}.form-row{grid-template-columns:1fr}.clients-grid{grid-template-columns:1fr}}

/* ── HEADER RESPONSIVO ── */
.header-desktop{display:flex;align-items:center;gap:10px}
.hamburger-btn{display:none;flex-direction:column;justify-content:center;gap:5px;
  background:transparent;border:1px solid var(--border);border-radius:8px;
  padding:8px 10px;cursor:pointer;width:40px;height:40px}
.hamburger-btn span{display:block;width:18px;height:2px;background:var(--text);border-radius:2px;transition:all .25s}
.hamburger-btn.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.hamburger-btn.open span:nth-child(2){opacity:0}
.hamburger-btn.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}

/* MENU MOBILE DROPDOWN */
.header-menu-mobile{
  display:none;position:fixed;top:64px;left:0;right:0;z-index:99;
  background:rgba(13,13,13,.97);backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);
  flex-direction:column;padding:.5rem 0;
  animation:slideDown .25s ease;
}
[data-tema="light"] .header-menu-mobile{background:rgba(245,245,240,.97)}
.header-menu-mobile.open{display:flex}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.hmm-user{display:flex;align-items:center;gap:8px;padding:12px 20px;font-weight:600;font-size:.9rem}
.hmm-nome{color:var(--text)}
.hmm-divider{height:1px;background:var(--border);margin:4px 0}
.hmm-item{display:flex;align-items:center;gap:10px;padding:13px 20px;font-size:.9rem;
  color:var(--text);text-decoration:none;cursor:pointer;transition:background .15s}
.hmm-item:hover{background:var(--surface2)}
.hmm-tema{user-select:none}
.hmm-sair{color:var(--danger)}

@media(max-width:680px){
  .header-desktop{display:none}
  .hamburger-btn{display:flex}
  /* Tour responsivo: força caixa no centro bottom em mobile */
  #tourBox{
    position:fixed!important;
    left:12px!important;right:12px!important;
    bottom:20px!important;top:auto!important;
    width:auto!important;max-width:100%!important;
    transform:none!important;
  }
  .tour-highlight{
    box-shadow:0 0 0 3px var(--accent,#78be20), 0 0 0 9999px rgba(0,0,0,.7)!important;
  }
}
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
<link rel="stylesheet" href="menu_lateral.css?v=1">
</head>
<body>
<header>
  <div class="logo-wrap" id="logoWrap" onclick="abrirLogoModal()" title="Clique para trocar a logo">
    <img id="navLogo" class="logo-img" src="" alt="Logo">
    <div class="logo-overlay">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
        <circle cx="12" cy="13" r="4"/>
      </svg>
      Trocar logo
    </div>
  </div>

  <!-- DESKTOP: ações inline -->
  <div class="header-right header-desktop">
    <div class="user-chip"><span><?= htmlspecialchars($admin['nome']) ?></span><span class="badge-admin">ADMIN</span></div>
    <a href="ia.php" class="btn btn-ghost btn-sm" title="IA Criativa">🤖 IA</a>
    <a href="analytics.php" class="btn btn-ghost btn-sm" title="Analytics">📊</a>
    <label class="theme-toggle-wrap" title="Alternar tema">
      <span id="themeIcon">🌙</span>
      <label class="theme-switch">
        <input type="checkbox" id="themeToggle" onchange="if(typeof OpaTheme!=='undefined')OpaTheme.toggle()">
        <span class="theme-slider"></span>
      </label>
    </label>
    <button class="btn btn-ghost btn-sm" onclick="if(typeof OpaTour!=='undefined')OpaTour.reiniciar()" title="Guia">❓</button>
    <a href="logout.php" class="btn btn-ghost btn-sm">Sair</a>
  </div>

  <!-- MOBILE: botão hamburguer -->
  <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleHeaderMenu()" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
</header>

<!-- MENU MOBILE DROPDOWN -->
<div id="headerMenuMobile" class="header-menu-mobile">
  <div class="hmm-user">
    <span class="hmm-nome"><?= htmlspecialchars($admin['nome']) ?></span>
    <span class="badge-admin">ADMIN</span>
  </div>
  <div class="hmm-divider"></div>
  <a href="ia.php" class="hmm-item">🤖 IA Criativa</a>
  <a href="analytics.php" class="hmm-item">📊 Analytics</a>
  <div class="hmm-item hmm-tema" onclick="if(typeof OpaTheme!=='undefined')OpaTheme.toggle();atualizarTemaIcon()">
    <span id="themeIconMobile">🌙</span>
    <span id="themeLabel">Tema Escuro</span>
    <label class="theme-switch" style="margin-left:auto" onclick="event.stopPropagation()">
      <input type="checkbox" id="themeToggleMobile" onchange="if(typeof OpaTheme!=='undefined')OpaTheme.toggle();atualizarTemaIcon()">
      <span class="theme-slider"></span>
    </label>
  </div>
  <div class="hmm-item" onclick="if(typeof OpaTour!=='undefined'){OpaTour.reiniciar();toggleHeaderMenu();}">❓ Guia interativo</div>
  <div class="hmm-divider"></div>
  <a href="logout.php" class="hmm-item hmm-sair">⏻ Sair</a>
</div>

<div class="nav-tabs">
  <button class="tab active" onclick="aba('dashboard',this)">Dashboard</button>
  <button class="tab" onclick="aba('clientes',this)">Clientes</button>
  <button class="tab" onclick="aba('criativos',this)">Criativos</button>
  <button class="tab" onclick="aba('meta',this)">Meta Ads
    <span id="metaAlertaCount" style="display:none;background:var(--danger);color:#fff;border-radius:10px;padding:1px 7px;font-size:.65rem;margin-left:4px;font-family:sans-serif"></span>
  </button>
  <span class="ml-sep"></span>
  <a class="tab" href="ia.php">🤖 IA Criativa</a>
  <a class="tab" href="analytics.php">📊 Analytics</a>
</div>

<main>
<?php
$status = $tenant['status'];
$trial_end = $tenant['trial_ends_at'] ?? null;
if ($status==='trial' && $trial_end):
  $dias = max(0,(int)ceil((strtotime($trial_end)-time())/86400));
?>
<div class="banner banner-trial">
  <span>⏱ Trial: <strong><?= $dias ?> dia(s) restante(s)</strong> — encerra em <?= date('d/m/Y',strtotime($trial_end)) ?></span>
  <a href="planos.php" target="_blank" class="btn btn-primary btn-sm">Assinar agora →</a>
</div>
<?php elseif ($status==='inadimplente'): ?>
<div class="banner banner-inad">⚠️ Pagamento pendente. Regularize sua assinatura para manter o acesso.</div>
<?php endif ?>

<div class="banner banner-meta" id="metaBanner" style="display:none">
  ⚠️ <span id="metaBannerMsg"></span>
</div>

  <!-- DASHBOARD -->
  <div class="section active" id="sec-dashboard">
    <div class="stats-bar">
      <div class="stat-card"><div class="stat-label">Clientes</div><div class="stat-value" id="g-clientes">—</div></div>
      <div class="stat-card"><div class="stat-label">Criativos</div><div class="stat-value" id="g-total">—</div></div>
      <div class="stat-card"><div class="stat-label">Em Produção</div><div class="stat-value" id="g-producao">—</div></div>
      <div class="stat-card"><div class="stat-label">Finalizados</div><div class="stat-value" id="g-finalizado">—</div></div>
    </div>
    <?php if ($plano['max_clientes'] > 0): ?>
    <div class="limit-bar-wrap" id="limitBar"></div>
    <?php endif ?>
    <div class="section-header" style="margin-top:.5rem">
      <div class="section-title">Clientes</div>
      <button class="btn btn-primary" onclick="abrirModalCliente()">+ Novo Cliente</button>
    </div>
    <div class="clients-grid" id="dashCards"></div>
  </div>

  <!-- CLIENTES -->
  <div class="section" id="sec-clientes">
    <div class="section-header">
      <div class="section-title">Clientes</div>
      <button class="btn btn-primary" onclick="abrirModalCliente()">+ Novo Cliente</button>
    </div>
    <div class="clients-grid" id="clientesGrid"></div>
  </div>

  <!-- CRIATIVOS -->
  <div class="section" id="sec-criativos">
    <div class="section-header">
      <div class="section-title">Criativos</div>
      <button class="btn btn-primary" onclick="abrirModalCriativo()">+ Novo Criativo</button>
    </div>
    <div class="toolbar">
      <input type="text" class="search-input" id="searchCr" placeholder="🔍 Buscar título..." oninput="renderCriativos()">
      <select class="filter-select" id="filterCliCr" onchange="renderCriativos()"><option value="">Todos clientes</option></select>
      <select class="filter-select" id="filterStCr" onchange="renderCriativos()">
        <option value="">Todos status</option><option value="pendente">Pendente</option>
        <option value="producao">Em Produção</option><option value="finalizado">Finalizado</option>
        <option value="cancelado">Cancelado</option>
      </select>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Cliente</th><th>Título</th><th>Tipo</th><th>Plataforma</th><th>Status</th><th>Revisão</th><th>Mês/Ano</th><th>Ações</th></tr></thead>
        <tbody id="tabelaCr"></tbody>
      </table>
      <div class="empty-state" id="emptyCr" style="display:none">◈ Nenhum criativo encontrado.</div>
    </div>
  </div>

  <!-- META ADS -->
  <div class="section" id="sec-meta">
    <div class="section-header"><div class="section-title">Meta Ads — Tokens</div></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Cliente</th><th>Status</th><th>Expira em</th><th>Conta Anúncios</th><th>Pixel ID</th><th>Atualizado</th><th>Ação</th></tr></thead>
        <tbody id="metaTabela"></tbody>
      </table>
      <div class="empty-state" id="metaEmpty" style="display:none">◈ Nenhum cliente ainda.</div>
    </div>
  </div>

</main>

<!-- MODAL CLIENTE -->
<div class="modal-overlay" id="modalCliente" onclick="fecharOverlay(event,'modalCliente')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="tituloModalCli">Novo Cliente</div>
      <button class="modal-close" onclick="fecharModal('modalCliente')">✕</button>
    </div>
    <input type="hidden" id="clienteId">
    <div class="form-row">
      <div class="form-group"><label class="lbl">Nome da Empresa</label><input type="text" class="form-control" id="cliEmpresa" placeholder="Ex: Loja X"></div>
      <div class="form-group"><label class="lbl">Segmento</label><input type="text" class="form-control" id="cliSegmento" placeholder="Ex: Moda"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="lbl">Nome do Responsável</label><input type="text" class="form-control" id="cliNome" placeholder="Nome completo"></div>
      <div class="form-group"><label class="lbl">E-mail de Acesso</label><input type="email" class="form-control" id="cliEmail" placeholder="email@cliente.com"></div>
    </div>
    <div class="form-group" id="senhaGroup">
      <label class="lbl">Senha de Acesso</label>
      <input type="password" class="form-control" id="cliSenha" placeholder="Mínimo 6 caracteres">
    </div>
    <div class="form-group">
      <label class="lbl">Cor de Destaque</label>
      <div class="color-row">
        <?php foreach ($cores as $c): ?>
        <div class="color-swatch <?= $c==='#78be20'?'active':'' ?>" style="background:<?= $c ?>" onclick="selecionarCor('<?= $c ?>')"></div>
        <?php endforeach ?>
      </div>
      <input type="hidden" id="cliCor" value="#78be20">
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="fecharModal('modalCliente')">Cancelar</button>
      <button class="btn btn-primary" onclick="salvarCliente()">Salvar</button>
    </div>
  </div>
</div>

<!-- MODAL CRIATIVO -->
<div class="modal-overlay" id="modalCriativo" onclick="fecharOverlay(event,'modalCriativo')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="tituloModalCr">Novo Criativo</div>
      <button class="modal-close" onclick="fecharModal('modalCriativo')">✕</button>
    </div>
    <input type="hidden" id="crId">
    <div class="form-group"><label class="lbl">Título</label><input type="text" class="form-control" id="crTitulo" placeholder="Ex: Story produto X"></div>
    <div class="form-row">
      <div class="form-group"><label class="lbl">Cliente</label><select class="form-control" id="crCliente"></select></div>
      <div class="form-group"><label class="lbl">Tipo</label>
        <select class="form-control" id="crTipo"><option>Arte</option><option>Vídeo</option><option>Reels</option><option>Story</option><option>Carrossel</option><option>Banner</option><option>Post Feed</option><option>Outro</option></select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="lbl">Plataforma</label>
        <select class="form-control" id="crPlat"><option>Instagram</option><option>Facebook</option><option>TikTok</option><option>Pinterest</option><option>WhatsApp</option><option>Email</option><option>Outro</option></select>
      </div>
      <div class="form-group"><label class="lbl">Status</label>
        <select class="form-control" id="crStatus"><option value="pendente">Pendente</option><option value="producao">Em Produção</option><option value="finalizado">Finalizado</option><option value="cancelado">Cancelado</option></select>
      </div>
    </div>
    <div class="form-group"><label class="lbl">Observação</label><textarea class="form-control" id="crObs" placeholder="Briefing, referências..."></textarea></div>

    <!-- UPLOAD DO ARQUIVO — visível só ao editar -->
    <div id="crUploadSec" style="display:none">
      <div style="border-top:1px solid var(--border);margin:1.25rem 0"></div>
      <label class="lbl" style="margin-bottom:.75rem;display:block">📤 Arquivo para o Cliente Revisar</label>

      <!-- Preview do arquivo atual -->
      <div id="crArquivoAtual" style="display:none;margin-bottom:1rem">
        <img id="crArquivoPreviewAtual" src="" alt="Arquivo atual"
             style="width:100%;max-height:180px;object-fit:contain;border-radius:8px;
                    border:1px solid var(--border);cursor:zoom-in;background:var(--surface2)"
             onclick="abrirLightbox(this.src)" title="Clique para ampliar">
        <div style="font-size:.72rem;color:var(--muted);margin-top:5px;font-family:JetBrains Mono,monospace;
                    display:flex;align-items:center;justify-content:space-between">
          <span id="crArquivoNome"></span>
          <span id="crRevisaoStatus"></span>
        </div>
      </div>

      <!-- Zona de upload -->
      <div class="upload-zone" id="crUploadZone"
           ondragover="event.preventDefault();this.classList.add('drag')"
           ondragleave="this.classList.remove('drag')"
           ondrop="crHandleDrop(event)">

        <!-- Input galeria (padrão) -->
        <input type="file" id="crUploadInput"
               accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm"
               onchange="crHandleFile(this.files[0])">
        <!-- Input câmera foto -->
        <input type="file" id="crCamFotoInput"
               accept="image/*" capture="environment"
               onchange="crHandleFile(this.files[0])" style="display:none">
        <!-- Input câmera vídeo -->
        <input type="file" id="crCamVideoInput"
               accept="video/*" capture="environment"
               onchange="crHandleFile(this.files[0])" style="display:none">

        <div id="crUploadZoneText" onclick="document.getElementById('crUploadInput').click()" style="cursor:pointer">
          <div style="font-size:1.4rem;margin-bottom:6px">🖼️</div>
          <div style="font-size:.85rem;font-weight:600" id="crUploadLabel">Clique ou arraste para enviar o criativo</div>
          <div style="font-size:.72rem;color:var(--muted);margin-top:3px">Imagem ou vídeo — máx. 10MB</div>
        </div>

        <!-- Botões de fonte mobile -->
        <div class="upload-cam-btns">
          <button type="button" class="cam-btn cam-btn-galeria"
                  onclick="document.getElementById('crUploadInput').click()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
            Galeria
          </button>
          <button type="button" class="cam-btn cam-btn-camera"
                  onclick="document.getElementById('crCamFotoInput').click()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            Tirar foto
          </button>
          <button type="button" class="cam-btn cam-btn-camera"
                  onclick="document.getElementById('crCamVideoInput').click()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
            Gravar vídeo
          </button>
        </div>

        <img id="crUploadPreview" style="display:none;max-width:100%;max-height:160px;object-fit:contain;border-radius:8px;margin-top:8px" src="" alt="Preview">
        <video id="crVideoPreview" style="display:none;max-width:100%;max-height:160px;border-radius:8px;margin-top:8px" controls></video>
      </div>

      <!-- Progresso -->
      <div id="crUploadProgress" style="display:none;margin-top:.75rem">
        <div style="background:var(--surface2);border-radius:4px;overflow:hidden;height:5px">
          <div id="crUploadBar" style="height:100%;background:var(--accent);width:0%;transition:width .3s"></div>
        </div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:4px;font-family:JetBrains Mono,monospace">Enviando arquivo...</div>
      </div>
    </div>

    <!-- Revisão recebida (visível só quando cliente já revisou) -->
    <div id="crRevisaoBloco" style="display:none">
      <div style="border-top:1px solid var(--border);margin:1.25rem 0"></div>
      <label class="lbl" style="margin-bottom:.75rem;display:block">⭐ Revisão do Cliente</label>
      <div id="crRevisaoConteudo"></div>
    </div>

    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="fecharModal('modalCriativo')">Cancelar</button>
      <button class="btn btn-primary" id="crBtnEnviar" onclick="crEnviarArquivo()" style="display:none">📤 Enviar para Cliente</button>
      <button class="btn btn-primary" onclick="salvarCriativo()">Salvar</button>
    </div>
  </div>
</div>


<!-- MODAL DETALHE CRIATIVO (briefing + upload + revisão) -->
<div class="modal-overlay" id="modalDetalhe" onclick="fecharOverlay(event,'modalDetalhe')">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <div class="modal-title" id="detalhe_titulo">Criativo</div>
      <button class="modal-close" onclick="fecharModal('modalDetalhe')">✕</button>
    </div>
    <input type="hidden" id="detalhe_id">

    <!-- BRIEFING -->
    <div id="detalhe_briefing_sec">
      <div style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:1rem">📋 Briefing do Cliente</div>
      <div id="detalhe_briefing_content"></div>
    </div>

    <div style="border-top:1px solid var(--border);margin:1.25rem 0"></div>

    <!-- UPLOAD -->
    <div style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:1rem">📤 Arquivo para Revisão</div>

    <div id="detalhe_arquivo_atual" style="display:none;margin-bottom:1rem">
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:8px">Arquivo atual:</div>
      <img id="detalhe_preview_atual" src="" alt="Preview" style="max-width:100%;max-height:220px;border-radius:8px;object-fit:contain;border:1px solid var(--border)">
    </div>

    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('uploadInput').click()"
         ondragover="event.preventDefault();this.classList.add('drag')"
         ondragleave="this.classList.remove('drag')"
         ondrop="handleDrop(event)">
      <input type="file" id="uploadInput" accept="image/jpeg,image/png,image/webp" onchange="handleFileSelect(this.files[0])">
      <div id="uploadZoneText">
        <div style="font-size:1.5rem;margin-bottom:8px">🖼️</div>
        <div style="font-size:.875rem;font-weight:600">Clique ou arraste a imagem aqui</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:4px">JPG, PNG ou WebP — máx. 10MB</div>
      </div>
      <img id="uploadPreview" class="upload-preview" src="" alt="Preview">
    </div>

    <div id="uploadProgress" style="display:none;margin-top:1rem">
      <div style="background:var(--surface2);border-radius:6px;overflow:hidden;height:6px">
        <div id="uploadBar" style="height:100%;background:var(--accent);width:0%;transition:width .3s"></div>
      </div>
      <div style="font-size:.75rem;color:var(--muted);margin-top:6px;font-family:JetBrains Mono,monospace">Enviando...</div>
    </div>

    <!-- REVISÃO DO CLIENTE -->
    <div id="detalhe_revisao_sec" style="display:none;margin-top:1.25rem">
      <div style="border-top:1px solid var(--border);margin-bottom:1.25rem"></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:1rem">⭐ Revisão do Cliente</div>
      <div id="detalhe_revisao_content"></div>
    </div>

    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="fecharModal('modalDetalhe')">Fechar</button>
      <button class="btn btn-primary" id="btnEnviarArquivo" onclick="enviarArquivo()" style="display:none">📤 Enviar para Revisão</button>
    </div>
  </div>
</div>

<!-- MODAL META ADS -->
<div class="modal-overlay" id="modalMeta" onclick="fecharOverlay(event,'modalMeta')">
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <div class="modal-title" id="tituloModalMeta">Meta Ads</div>
      <button class="modal-close" onclick="fecharModal('modalMeta')">✕</button>
    </div>
    <input type="hidden" id="metaClienteId">
    <div id="metaCliLabel" style="font-size:.78rem;color:var(--muted);font-family:JetBrains Mono,monospace;margin-bottom:1.5rem;padding:8px 12px;background:var(--surface2);border-radius:8px;border:1px solid var(--border)"></div>
    <div class="form-group">
      <label class="lbl">Token de Acesso Meta Ads</label>
      <div class="token-input-wrap">
        <input type="password" class="form-control" id="metaToken" placeholder="EAAxxxxxx...">
        <button type="button" class="toggle-vis" onclick="toggleVis('metaToken')">👁</button>
      </div>
      <div style="font-size:.7rem;color:var(--muted);margin-top:5px;font-family:JetBrains Mono,monospace">
        Gere em: developers.facebook.com → Graph API Explorer → token de longa duração (~60 dias)
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="lbl">Data de Expiração</label><input type="date" class="form-control" id="metaExpires"></div>
      <div class="form-group"><label class="lbl">ID Conta de Anúncios</label><input type="text" class="form-control" id="metaAdAccount" placeholder="act_0000000"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="lbl">Pixel ID</label><input type="text" class="form-control" id="metaPixel" placeholder="000000000000"></div>
      <div class="form-group"><label class="lbl">App ID (opcional)</label><input type="text" class="form-control" id="metaAppId" placeholder="000000000000"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="lbl">Facebook Page ID</label><input type="text" class="form-control" id="metaPageId" placeholder="Ex: 123456789012"></div>
      <div class="form-group"><label class="lbl">Instagram Business ID</label><input type="text" class="form-control" id="metaIgId" placeholder="Deixe em branco — auto detectado"></div>
    </div>
    <div class="form-group"><label class="lbl">Notas internas</label><textarea class="form-control" id="metaNotas" placeholder="Ex: token gerado em 17/03 pelo Rafael"></textarea></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="fecharModal('modalMeta')">Cancelar</button>
      <button class="btn btn-primary" onclick="salvarMeta()">Salvar Configuração</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const MESES=['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
const PLAT={Instagram:'📸',Facebook:'📘',TikTok:'🎵',Pinterest:'📌',WhatsApp:'💬',Email:'📧',Outro:'🔗'};
const MAX_CLI=<?= (int)$plano['max_clientes'] ?>;
let clientes=[],criativos=[],metaConfigs=[];

async function api(action,data=null){
  const r=await fetch(`api.php?action=${action}`,{method:data?'POST':'GET',credentials:'include',headers:{'Content-Type':'application/json'},body:data?JSON.stringify(data):null});
  if(!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}


// ── LIGHTBOX ──────────────────────────────────────────────
let lbImagens = [];
let lbIndex   = 0;

function abrirLightbox(urls, index = 0) {
  lbImagens = Array.isArray(urls) ? urls : [urls];
  lbIndex   = index;
  lbMostrar();
  document.getElementById('lightbox').classList.add('active');
  document.addEventListener('keydown', lbTecla);
}

function fecharLightbox() {
  document.getElementById('lightbox').classList.remove('active');
  document.removeEventListener('keydown', lbTecla);
}

function lbMostrar() {
  const img     = document.getElementById('lightboxImg');
  const prev    = document.getElementById('lbPrev');
  const next    = document.getElementById('lbNext');
  const counter = document.getElementById('lbCounter');
  img.src = lbImagens[lbIndex];
  const multi = lbImagens.length > 1;
  prev.style.display    = multi && lbIndex > 0                    ? 'flex' : 'none';
  next.style.display    = multi && lbIndex < lbImagens.length - 1 ? 'flex' : 'none';
  counter.style.display = multi ? 'block' : 'none';
  if (multi) counter.textContent = (lbIndex + 1) + ' / ' + lbImagens.length;
}

function lbNavegar(dir) {
  lbIndex = Math.max(0, Math.min(lbImagens.length - 1, lbIndex + dir));
  lbMostrar();
}

function lbTecla(e) {
  if (e.key === 'Escape')     fecharLightbox();
  if (e.key === 'ArrowRight') lbNavegar(1);
  if (e.key === 'ArrowLeft')  lbNavegar(-1);
}


// ── TOUR ─────────────────────────────────────────────────
if(typeof OpaTour !== 'undefined') OpaTour.init([
  { title: 'Bem-vindo ao Opa Studio! 🎉', text: 'Seu assistente diário de criativos. Este guia vai mostrar as principais funcionalidades.', target: null },
  { title: 'Dashboard', text: 'Aqui você vê todos os seus clientes em cards, com estatísticas rápidas de cada um.', target: '#sec-dashboard' },
  { title: 'Novo Cliente', text: 'Clique aqui para cadastrar um novo cliente. Ele receberá acesso à área exclusiva dele.', target: '.btn-primary' },
  { title: 'Criativos', text: 'Na aba Criativos você vê todos os pedidos, faz upload das peças prontas e acompanha as revisões dos clientes.', target: '[onclick*="criativos"]' },
  { title: 'Meta Ads', text: 'Configure os tokens de cada cliente aqui. Você verá alertas quando um token estiver expirando.', target: '[onclick*="meta"]' },
  { title: 'IA Criativa 🤖', text: 'Use a IA para gerar legendas, hashtags, imagens com DALL-E 3 e analisar briefings automaticamente.', target: '[href="ia.php"]' },
  { title: 'Analytics', text: 'Acesse métricas do Instagram, Facebook e Meta Ads de cada cliente diretamente pelo sistema.', target: '[href="analytics.php"]' },
  { title: 'Modo Claro/Escuro', text: 'Alterne entre o tema escuro e claro conforme sua preferência. A configuração fica salva.', target: '.theme-toggle-wrap' },
]);

// ── MENU MOBILE ──────────────────────────────────────────
function toggleHeaderMenu(){
  const btn  = document.getElementById('hamburgerBtn');
  const menu = document.getElementById('headerMenuMobile');
  btn.classList.toggle('open');
  menu.classList.toggle('open');
  if(menu.classList.contains('open')){
    setTimeout(()=>document.addEventListener('click', fecharMenuFora, {once:true}), 50);
  }
}
function fecharMenuFora(e){
  const menu = document.getElementById('headerMenuMobile');
  const btn  = document.getElementById('hamburgerBtn');
  if(!menu.contains(e.target) && !btn.contains(e.target)){
    menu.classList.remove('open');
    btn.classList.remove('open');
  }
}
function atualizarTemaIcon(){
  setTimeout(()=>{
    const isLight = typeof OpaTheme!=='undefined' && OpaTheme.get()==='light';
    ['themeIcon','themeIconMobile'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent=isLight?'☀️':'🌙';});
    const lb=document.getElementById('themeLabel'); if(lb) lb.textContent=isLight?'Tema Claro':'Tema Escuro';
    const tgM=document.getElementById('themeToggleMobile'); if(tgM) tgM.checked=isLight;
    const tgD=document.getElementById('themeToggle'); if(tgD) tgD.checked=isLight;
  }, 50);
}
document.addEventListener('DOMContentLoaded', ()=>{ atualizarTemaIcon(); });


// ══════════════ LOGO DINÂMICA ══════════════
const LOGO_DARK_B64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAEsCAYAAAA1u0HIAAAAtGVYSWZJSSoACAAAAAYAEgEDAAEAAAABAAAAGgEFAAEAAABWAAAAGwEFAAEAAABeAAAAKAEDAAEAAAACAAAAEwIDAAEAAAABAAAAaYcEAAEAAABmAAAAAAAAAGAAAAABAAAAYAAAAAEAAAAGAACQBwAEAAAAMDIxMAGRBwAEAAAAAQIDAACgBwAEAAAAMDEwMAGgAwABAAAA//8AAAKgBAABAAAA9AEAAAOgBAABAAAALAEAAAAAAADVGa7tAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAFRmlUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSfvu78nIGlkPSdXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQnPz4KPHg6eG1wbWV0YSB4bWxuczp4PSdhZG9iZTpuczptZXRhLyc+CjxyZGY6UkRGIHhtbG5zOnJkZj0naHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyc+CgogPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9JycKICB4bWxuczpBdHRyaWI9J2h0dHA6Ly9ucy5hdHRyaWJ1dGlvbi5jb20vYWRzLzEuMC8nPgogIDxBdHRyaWI6QWRzPgogICA8cmRmOlNlcT4KICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0nUmVzb3VyY2UnPgogICAgIDxBdHRyaWI6Q3JlYXRlZD4yMDI2LTAzLTE5PC9BdHRyaWI6Q3JlYXRlZD4KICAgICA8QXR0cmliOkRhdGE+eyZxdW90O2RvYyZxdW90OzomcXVvdDtEQUhFWkEzcGM3NCZxdW90OywmcXVvdDt1c2VyJnF1b3Q7OiZxdW90O1VBREc1T2IzeDRrJnF1b3Q7LCZxdW90O2JyYW5kJnF1b3Q7OiZxdW90O0JBREc1QzRMVC1vJnF1b3Q7fTwvQXR0cmliOkRhdGE+CiAgICAgPEF0dHJpYjpFeHRJZD4yOTQ4NDk3YS04NjA4LTRhODQtOWVkNy1kMTNlOGRjNDFhMGQ8L0F0dHJpYjpFeHRJZD4KICAgICA8QXR0cmliOkZiSWQ+NTI1MjY1OTE0MTc5NTgwPC9BdHRyaWI6RmJJZD4KICAgICA8QXR0cmliOlRvdWNoVHlwZT4yPC9BdHRyaWI6VG91Y2hUeXBlPgogICAgPC9yZGY6bGk+CiAgIDwvcmRmOlNlcT4KICA8L0F0dHJpYjpBZHM+CiA8L3JkZjpEZXNjcmlwdGlvbj4KCiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0nJwogIHhtbG5zOmRjPSdodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyc+CiAgPGRjOnRpdGxlPgogICA8cmRmOkFsdD4KICAgIDxyZGY6bGkgeG1sOmxhbmc9J3gtZGVmYXVsdCc+RGVzaWduIHNlbSBub21lIC0gMTwvcmRmOmxpPgogICA8L3JkZjpBbHQ+CiAgPC9kYzp0aXRsZT4KIDwvcmRmOkRlc2NyaXB0aW9uPgoKIDxyZGY6RGVzY3JpcHRpb24gcmRmOmFib3V0PScnCiAgeG1sbnM6cGRmPSdodHRwOi8vbnMuYWRvYmUuY29tL3BkZi8xLjMvJz4KICA8cGRmOkF1dGhvcj5OaW5hIFppbW1lcm1hbm48L3BkZjpBdXRob3I+CiA8L3JkZjpEZXNjcmlwdGlvbj4KCiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0nJwogIHhtbG5zOnhtcD0naHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wLyc+CiAgPHhtcDpDcmVhdG9yVG9vbD5DYW52YSBkb2M9REFIRVpBM3BjNzQgdXNlcj1VQURHNU9iM3g0ayBicmFuZD1CQURHNUM0TFQtbzwveG1wOkNyZWF0b3JUb29sPgogPC9yZGY6RGVzY3JpcHRpb24+CjwvcmRmOlJERj4KPC94OnhtcG1ldGE+Cjw/eHBhY2tldCBlbmQ9J3InPz7MEE3LAAAgAElEQVR4nOydB3wdxbX/TSCFl0AqISGE0N2rbMu2iiW5AAlpJCSBR0gj5CUheS/9/WmipEBeKElIwGDAvciWiySr2UauuCDbsq1muQgbN9Vbtk3Zu/M/s7uzdyVLsnTvlSVZ8/PneK9u2Z2d3Z3vnClnhgyRkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKS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';

function carregarLogoNav(){
  const el  = document.getElementById('navLogo');
  if(!el) return;
  // Tenta URL do servidor primeiro
  const url = '<?= htmlspecialchars($tenant["logo_url"] ?? "") ?>';
  if(url){
    el.src = url + '?v=' + Date.now();
    el.onerror = ()=>{ el.src = LOGO_DARK_B64; el.onerror=null; };
  } else {
    el.src = LOGO_DARK_B64;
  }
}

let _logoFile = null;

function abrirLogoModal(){
  const m = document.getElementById('logoModal');
  if(!m) return;
  m.classList.add('active');
  document.getElementById('logoPreviewWrap').style.display    = 'none';
  document.getElementById('logoZoneText').style.display       = 'block';
  document.getElementById('logoFileInput').value              = '';
  document.getElementById('logoBtnEnviar').disabled           = true;
  document.getElementById('logoErro').style.display           = 'none';
  document.getElementById('logoUploadProgress').style.display = 'none';
  _logoFile = null;
}
function fecharLogoModal(){
  const m = document.getElementById('logoModal');
  if(m) m.classList.remove('active');
}
function logoHandleDrop(e){
  e.preventDefault();
  document.getElementById('logoZone').classList.remove('drag');
  const f = e.dataTransfer.files[0];
  if(f) logoHandleFile(f);
}
function logoHandleFile(file){
  if(!file) return;
  if(file.size > 2*1024*1024){
    document.getElementById('logoErro').textContent = 'Arquivo muito grande. Máximo: 2MB.';
    document.getElementById('logoErro').style.display='block'; return;
  }
  _logoFile = file;
  const reader = new FileReader();
  reader.onload = ev => {
    document.getElementById('logoPreviewImg').src = ev.target.result;
    document.getElementById('logoPreviewNome').textContent = file.name;
    document.getElementById('logoPreviewWrap').style.display = 'block';
    document.getElementById('logoZoneText').style.display    = 'none';
    document.getElementById('logoBtnEnviar').disabled        = false;
    document.getElementById('logoErro').style.display        = 'none';
  };
  reader.readAsDataURL(file);
}
async function logoEnviar(){
  if(!_logoFile) return;
  const btn  = document.getElementById('logoBtnEnviar');
  const prog = document.getElementById('logoUploadProgress');
  const bar  = document.getElementById('logoProgressBar');
  btn.disabled=true; prog.style.display='block'; bar.style.width='40%';
  const fd = new FormData();
  fd.append('logo', _logoFile);
  try{
    bar.style.width='75%';
    const r    = await fetch('upload_logo.php',{method:'POST',credentials:'include',body:fd});
    const data = await r.json();
    bar.style.width='100%';
    if(!data.ok){
      document.getElementById('logoErro').textContent = data.erro||'Erro ao enviar.';
      document.getElementById('logoErro').style.display='block';
      btn.disabled=false; prog.style.display='none'; return;
    }
    const novaUrl = data.logo_url + '?v=' + data.cache_bust;
    ['navLogo','logoMain','logoReg'].forEach(id=>{
      const el=document.getElementById(id);
      if(el&&el.tagName==='IMG') el.src=novaUrl;
    });
    fecharLogoModal();
    toast('Logo atualizada ✓');
  }catch(e){
    document.getElementById('logoErro').textContent='Erro de conexão.';
    document.getElementById('logoErro').style.display='block';
    btn.disabled=false; prog.style.display='none';
  }
}

async function init(){
  try{
    const[rC,rCr,rM]=await Promise.all([api('clientes_lista'),api('criativos_admin'),api('meta_lista')]);
    clientes=rC.clientes||[];criativos=rCr.criativos||[];metaConfigs=rM.configs||[];
    renderDashboard();renderClientesGrid();renderCriativos();renderMeta();popularSelects();
  }catch(e){toast('Erro ao carregar dados','danger');console.error(e);}
}

function aba(nome,el){
  document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.getElementById('sec-'+nome).classList.add('active');
  if(el)el.classList.add('active');
}

function renderDashboard(){
  document.getElementById('g-clientes').textContent=clientes.length;
  document.getElementById('g-total').textContent=criativos.length;
  document.getElementById('g-producao').textContent=criativos.filter(c=>c.status==='producao').length;
  document.getElementById('g-finalizado').textContent=criativos.filter(c=>c.status==='finalizado').length;
  const lb=document.getElementById('limitBar');
  if(lb&&MAX_CLI>0){
    const pct=Math.min(100,Math.round((clientes.length/MAX_CLI)*100));
    const cls=pct>=100?'crit':pct>=80?'warn':'';
    lb.innerHTML=`<span class="limit-label">Clientes: ${clientes.length}/${MAX_CLI}</span><div class="limit-bar-track"><div class="limit-bar-fill ${cls}" style="width:${pct}%"></div></div><span class="limit-label" style="color:${pct>=100?'var(--danger)':pct>=80?'var(--warning)':'var(--muted)'}">${pct}%</span>`;
  }
  document.getElementById('dashCards').innerHTML=clientes.map(c=>clienteCard(c)).join('')||'<div class="empty-state">Nenhum cliente cadastrado ainda.</div>';
}

function clienteCard(c){
  const cr=criativos.filter(x=>x.cliente_id==c.id);
  const ini=c.nome_empresa.split(' ').slice(0,2).map(w=>w[0]||'').join('').toUpperCase();
  return`<div class="client-card" style="--cor:${esc(c.cor)}" onclick="filtrarCli(${c.id})">
    <div class="card-header">
      <div class="card-avatar" style="color:${esc(c.cor)}">${esc(ini)}</div>
      <div class="card-actions" onclick="event.stopPropagation()">
        <button class="icon-btn" title="Meta Ads" onclick="abrirMeta(${c.id})">📡</button>
        <button class="icon-btn" title="Editar" onclick="editarCliente(${c.id})">✏️</button>
        <button class="icon-btn" title="Excluir" onclick="deletarCliente(${c.id})">🗑</button>
      </div>
    </div>
    <div class="card-empresa">${esc(c.nome_empresa)}</div>
    <div class="card-segmento">${esc(c.segmento||'—')}</div>
    <div class="card-stats">
      <div class="mini-stat"><div class="mini-stat-label">Total</div><div class="mini-stat-val">${cr.length}</div></div>
      <div class="mini-stat"><div class="mini-stat-label">Pendentes</div><div class="mini-stat-val" style="color:var(--warning)">${cr.filter(x=>x.status==='pendente').length}</div></div>
      <div class="mini-stat"><div class="mini-stat-label">Produção</div><div class="mini-stat-val" style="color:var(--accent)">${cr.filter(x=>x.status==='producao').length}</div></div>
      <div class="mini-stat"><div class="mini-stat-label">Finalizados</div><div class="mini-stat-val" style="color:var(--success)">${cr.filter(x=>x.status==='finalizado').length}</div></div>
    </div>
    <div class="card-footer">
      <span class="card-email">${esc(c.email||'')}</span>
      <span style="font-size:.72rem;background:${esc(c.cor)};color:#0d0d0d;padding:4px 10px;border-radius:6px;font-weight:700">Ver →</span>
    </div>
  </div>`;
}

function renderClientesGrid(){
  document.getElementById('clientesGrid').innerHTML=clientes.map(c=>clienteCard(c)).join('')||'<div class="empty-state">Nenhum cliente cadastrado.</div>';
}

function filtrarCli(id){
  aba('criativos',document.querySelectorAll('.tab')[2]);
  document.getElementById('filterCliCr').value=id;renderCriativos();
}

function renderCriativos(){
  const s=document.getElementById('searchCr').value.toLowerCase();
  const fc=document.getElementById('filterCliCr').value;
  const fs=document.getElementById('filterStCr').value;
  let lista=criativos;
  if(s)lista=lista.filter(c=>c.titulo.toLowerCase().includes(s));
  if(fc)lista=lista.filter(c=>c.cliente_id==fc);
  if(fs)lista=lista.filter(c=>c.status===fs);
  const tbody=document.getElementById('tabelaCr');
  const empty=document.getElementById('emptyCr');
  if(!lista.length){tbody.innerHTML='';empty.style.display='block';return;}
  empty.style.display='none';
  tbody.innerHTML=lista.map(c=>{
    const cli=clientes.find(x=>x.id==c.cliente_id)||{};
    return`<tr>
      <td class="td-mono">#${String(c.id).padStart(3,'0')}</td>
      <td><span class="cli-badge" style="background:${esc(cli.cor||'#78be20')}22;color:${esc(cli.cor||'#78be20')}">${esc(cli.nome_empresa||'—')}</span></td>
      <td><div class="td-title" title="${esc(c.titulo)}">${esc(c.titulo)}</div></td>
      <td class="td-mono">${esc(c.tipo)}</td>
      <td><span class="plat-badge">${PLAT[c.plataforma]||'🔗'} ${esc(c.plataforma)}</span></td>
      <td><select class="badge badge-${c.status} status-sel" onchange="mudarStatus(${c.id},this.value)">
        <option value="pendente" ${c.status==='pendente'?'selected':''}>⏳ Pendente</option>
        <option value="producao" ${c.status==='producao'?'selected':''}>⚡ Em Produção</option>
        <option value="finalizado" ${c.status==='finalizado'?'selected':''}>✅ Finalizado</option>
        <option value="cancelado" ${c.status==='cancelado'?'selected':''}>❌ Cancelado</option>
      </select></td>
      <td><span class="rev-badge ${c.revisao_status||'aguardando'}">${REV_LABELS[c.revisao_status||'aguardando']||'—'}</span></td>
      <td class="td-mono">${MESES[c.mes-1]} ${c.ano}</td>
      <td style="display:flex;gap:5px">
        <button class="btn btn-ghost btn-sm" onclick="abrirDetalhe(${c.id})" title="Briefing / Upload / Revisão">📋</button>
        <button class="btn btn-ghost btn-sm" onclick="editarCriativo(${c.id})">✏️</button>
        <button class="btn btn-danger" onclick="deletarCriativo(${c.id})">🗑</button>
      </td>
    </tr>`;
  }).join('');
}

function popularSelects(){
  const opts=clientes.map(c=>`<option value="${c.id}">${esc(c.nome_empresa)}</option>`).join('');
  document.getElementById('crCliente').innerHTML=opts;
  document.getElementById('filterCliCr').innerHTML='<option value="">Todos clientes</option>'+opts;
}

// Clientes
function abrirModalCliente(c=null){
  document.getElementById('tituloModalCli').textContent=c?'Editar Cliente':'Novo Cliente';
  document.getElementById('clienteId').value=c?c.id:'';
  document.getElementById('cliEmpresa').value=c?c.nome_empresa:'';
  document.getElementById('cliSegmento').value=c?c.segmento:'';
  document.getElementById('cliNome').value=c?c.nome:'';
  document.getElementById('cliEmail').value=c?c.email:'';
  document.getElementById('cliSenha').value='';
  document.getElementById('cliCor').value=c?c.cor:'#78be20';
  document.getElementById('senhaGroup').style.display=c?'none':'block';
  document.querySelectorAll('.color-swatch').forEach(sw=>sw.classList.toggle('active',sw.style.background===(c?c.cor:'#78be20')));
  document.getElementById('modalCliente').classList.add('active');
}
function editarCliente(id){abrirModalCliente(clientes.find(c=>c.id==id));}
async function salvarCliente(){
  const id=document.getElementById('clienteId').value;
  const dados={id:id||null,nome_empresa:document.getElementById('cliEmpresa').value.trim(),segmento:document.getElementById('cliSegmento').value.trim(),nome:document.getElementById('cliNome').value.trim(),email:document.getElementById('cliEmail').value.trim(),senha:document.getElementById('cliSenha').value,cor:document.getElementById('cliCor').value};
  if(!dados.nome_empresa||!dados.email){toast('Empresa e e-mail obrigatórios','danger');return;}
  if(!id&&dados.senha.length<6){toast('Senha mínima de 6 caracteres','danger');return;}
  try{
    const r=await api(id?'cliente_update':'cliente_create',dados);
    if(!r.ok){toast(r.erro||'Erro','danger');return;}
    clientes=r.clientes;renderDashboard();renderClientesGrid();popularSelects();fecharModal('modalCliente');toast(id?'Cliente atualizado ✓':'Cliente criado ✓');
  }catch(e){toast('Erro na requisição','danger');}
}
async function deletarCliente(id){
  if(!confirm('Excluir este cliente e todos os seus criativos?'))return;
  const r=await api('cliente_delete',{id});
  if(r.ok){clientes=r.clientes;criativos=criativos.filter(c=>c.cliente_id!=id);metaConfigs=metaConfigs.filter(m=>m.cliente_id!=id);renderDashboard();renderClientesGrid();renderCriativos();renderMeta();popularSelects();toast('Cliente removido','danger');}
}

// Criativos
function abrirModalCriativo(c=null){
  document.getElementById('tituloModalCr').textContent=c?'Editar Criativo':'Novo Criativo';
  document.getElementById('crId').value=c?c.id:'';
  document.getElementById('crTitulo').value=c?c.titulo:'';
  document.getElementById('crTipo').value=c?c.tipo:'Arte';
  document.getElementById('crPlat').value=c?c.plataforma:'Instagram';
  document.getElementById('crStatus').value=c?c.status:'pendente';
  document.getElementById('crObs').value=c?(c.observacao||''):'';
  if(c)document.getElementById('crCliente').value=c.cliente_id;

  // Reset upload
  const uploadSec   = document.getElementById('crUploadSec');
  const arquivoAtual= document.getElementById('crArquivoAtual');
  const btnEnviar   = document.getElementById('crBtnEnviar');
  const progress    = document.getElementById('crUploadProgress');
  document.getElementById('crUploadPreview').style.display='none';
  document.getElementById('crUploadZoneText').style.display='block';
  document.getElementById('crUploadLabel').textContent='Clique ou arraste para enviar o criativo';
  document.getElementById('crUploadInput').value='';
  progress.style.display='none';
  btnEnviar.style.display='none';
  crUploadFile=null;

  // Upload sempre visível
  uploadSec.style.display='block';

  if(c){
    // Editando — mostra arquivo atual se houver
    if(c.arquivo_url){
      arquivoAtual.style.display='block';
      document.getElementById('crArquivoPreviewAtual').src=c.arquivo_url;
      document.getElementById('crArquivoNome').textContent=c.arquivo_nome||'arquivo';
      const REV={aguardando:'⏳ Aguardando revisão',aprovado:'✅ Aprovado',alteracao:'⚠️ Alteração solicitada',reprovado:'❌ Reprovado'};
      const cor={aguardando:'var(--muted)',aprovado:'var(--success)',alteracao:'var(--warning)',reprovado:'var(--danger)'};
      const rs=c.revisao_status||'aguardando';
      document.getElementById('crRevisaoStatus').innerHTML=`<span style="color:${cor[rs]};font-weight:700">${REV[rs]||''}</span>`;
      document.getElementById('crUploadLabel').textContent='Substituir arquivo atual';
    } else {
      arquivoAtual.style.display='none';
    }
    // Revisão
    const revBloco  = document.getElementById('crRevisaoBloco');
    const revConteudo = document.getElementById('crRevisaoConteudo');
    const REV_LABELS={aguardando:'Aguardando',aprovado:'✅ Aprovado',alteracao:'⚠️ Alteração solicitada',reprovado:'❌ Reprovado'};
    if(c.arquivo_url && c.revisao_status && c.revisao_status!=='aguardando'){
      const stars=c.revisao_nota?'⭐'.repeat(c.revisao_nota)+'☆'.repeat(5-c.revisao_nota):'';
      const corMap={aprovado:'rgba(74,222,128,.06)',alteracao:'rgba(251,191,36,.06)',reprovado:'rgba(248,113,113,.06)'};
      const bordMap={aprovado:'rgba(74,222,128,.2)',alteracao:'rgba(251,191,36,.2)',reprovado:'rgba(248,113,113,.2)'};
      revConteudo.innerHTML=`<div style="background:${corMap[c.revisao_status]||'var(--surface2)'};border:1px solid ${bordMap[c.revisao_status]||'var(--border)'};border-radius:10px;padding:1rem">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:${c.revisao_comentario?'10px':'0'}">
          <span style="font-weight:700;font-size:.85rem">${REV_LABELS[c.revisao_status]}</span>
          <span style="font-size:1rem">${stars}</span>
          ${c.revisao_data?`<span style="font-size:.72rem;color:var(--muted);font-family:JetBrains Mono,monospace">${new Date(c.revisao_data).toLocaleDateString('pt-BR')}</span>`:''}
        </div>
        ${c.revisao_comentario?`<div style="font-size:.875rem;line-height:1.6;color:var(--text)">${esc(c.revisao_comentario)}</div>`:''}
      </div>`;
      revBloco.style.display='block';
    } else {
      revBloco.style.display='none';
    }
  } else {
    // Novo criativo — sem arquivo ainda, sem revisão
    arquivoAtual.style.display='none';
    document.getElementById('crRevisaoBloco').style.display='none';
  }

  document.getElementById('modalCriativo').classList.add('active');
}
function editarCriativo(id){abrirModalCriativo(criativos.find(c=>c.id==id));}
async function salvarCriativo(){
  const id=document.getElementById('crId').value;
  const dados={id:id||null,cliente_id:document.getElementById('crCliente').value,titulo:document.getElementById('crTitulo').value.trim(),tipo:document.getElementById('crTipo').value,plataforma:document.getElementById('crPlat').value,status:document.getElementById('crStatus').value,observacao:document.getElementById('crObs').value.trim()};
  if(!dados.titulo){toast('Título obrigatório','danger');return;}
  try{
    const r=await api(id?'criativo_update':'criativo_save',dados);
    if(!r.ok){toast(r.erro||'Erro','danger');return;}
    criativos=r.criativos;renderCriativos();renderDashboard();

    // Se tem arquivo pendente e era novo criativo, faz upload automaticamente
    if(!id && crUploadFile){
      // Pega o ID do criativo recém-criado (primeiro da lista)
      const novoId = r.criativos[0]?.id;
      if(novoId){
        document.getElementById('crId').value = novoId;
        document.getElementById('tituloModalCr').textContent = 'Enviando arquivo...';
        await crEnviarArquivo();
        return; // crEnviarArquivo já fecha o modal
      }
    }

    fecharModal('modalCriativo');
    toast(id?'Criativo atualizado ✓':'Criativo criado ✓');
  }catch(e){toast('Erro','danger');}
}
async function mudarStatus(id,status){
  const r=await api('status_update',{id,status});
  if(r.ok){const i=criativos.findIndex(c=>c.id==id);if(i!==-1)criativos[i].status=status;renderCriativos();renderDashboard();toast('Status atualizado');}
}
async function deletarCriativo(id){
  if(!confirm('Remover este criativo?'))return;
  const r=await api('criativo_delete',{id});
  if(r.ok){criativos=r.criativos;renderCriativos();renderDashboard();toast('Criativo removido','danger');}
}

// Meta Ads
function renderMeta(){
  const tbody=document.getElementById('metaTabela');const empty=document.getElementById('metaEmpty');
  const alertas=metaConfigs.filter(m=>m.token_status==='expirado'||m.token_status==='expirando');
  const cnt=document.getElementById('metaAlertaCount');const banner=document.getElementById('metaBanner');const bannerMsg=document.getElementById('metaBannerMsg');
  if(alertas.length){
    cnt.textContent=alertas.length;cnt.style.display='inline';banner.style.display='flex';
    const exp=alertas.filter(m=>m.token_status==='expirado').length;const prox=alertas.filter(m=>m.token_status==='expirando').length;
    bannerMsg.textContent=[exp&&`${exp} token(s) expirado(s)`,prox&&`${prox} expirando em breve`].filter(Boolean).join(' • ')+' — clique em Editar para renovar.';
  }else{cnt.style.display='none';banner.style.display='none';}
  if(!metaConfigs.length){tbody.innerHTML='';empty.style.display='block';return;}
  empty.style.display='none';
  const labels={ok:'✅ OK',expirando:'⚠️ Expirando',expirado:'❌ Expirado',nao_configurado:'— Não config.'};
  tbody.innerHTML=metaConfigs.map(m=>{
    const cli=clientes.find(c=>c.id==m.cliente_id)||{};
    const dias=m.token_expires_at?Math.ceil((new Date(m.token_expires_at)-new Date())/86400000):null;
    const cor=dias===null?'var(--muted)':dias<=0?'var(--danger)':dias<=10?'var(--warning)':'var(--success)';
    const diasTxt=dias===null?'—':`<span class="dias-badge" style="background:${cor}22;color:${cor};border:1px solid ${cor}44">${dias<=0?'Vencido':dias+'d'}</span>`;
    return`<tr>
      <td><span class="cli-badge" style="background:${esc(cli.cor||'#78be20')}22;color:${esc(cli.cor||'#78be20')}">${esc(cli.nome_empresa||'—')}</span></td>
      <td><span class="meta-badge ${m.token_status}">${labels[m.token_status]||'—'}</span></td>
      <td>${diasTxt}</td>
      <td class="td-mono">${esc(m.ad_account_id||'—')}</td>
      <td class="td-mono">${esc(m.pixel_id||'—')}</td>
      <td class="td-mono">${m.atualizado_em?new Date(m.atualizado_em).toLocaleDateString('pt-BR'):'—'}</td>
      <td><button class="btn btn-ghost btn-sm" onclick="abrirMeta(${m.cliente_id})">${m.token_status==='nao_configurado'?'⚙️ Configurar':'✏️ Editar'}</button></td>
    </tr>`;
  }).join('');
}
function abrirMeta(clienteId){
  const m=metaConfigs.find(x=>x.cliente_id==clienteId)||{};const cli=clientes.find(c=>c.id==clienteId)||{};
  document.getElementById('metaClienteId').value=clienteId;
  document.getElementById('metaCliLabel').textContent='📌 '+(cli.nome_empresa||'');
  document.getElementById('metaToken').value=m.access_token||'';document.getElementById('metaToken').type='password';
  document.getElementById('metaExpires').value=m.token_expires_at||'';
  document.getElementById('metaAdAccount').value=m.ad_account_id||'';
  document.getElementById('metaPixel').value=m.pixel_id||'';
  document.getElementById('metaAppId').value=m.app_id||'';
  document.getElementById('metaPageId').value=m.page_id||'';
  document.getElementById('metaIgId').value=m.ig_business_id||'';
  document.getElementById('tituloModalMeta').textContent='Meta Ads — '+(cli.nome_empresa||'');
  document.getElementById('modalMeta').classList.add('active');
}
async function salvarMeta(){
  const dados={
    cliente_id:document.getElementById('metaClienteId').value,
    access_token:document.getElementById('metaToken').value.trim(),
    token_expires_at:document.getElementById('metaExpires').value,
    ad_account_id:document.getElementById('metaAdAccount').value.trim(),
    pixel_id:document.getElementById('metaPixel').value.trim(),
    app_id:document.getElementById('metaAppId').value.trim(),
    page_id:document.getElementById('metaPageId').value.trim(),
    ig_business_id:document.getElementById('metaIgId').value.trim(),
    notas:document.getElementById('metaNotas').value.trim()
  };
  try{
    const r=await api('meta_save',dados);if(!r.ok){toast(r.erro||'Erro','danger');return;}
    metaConfigs=r.configs;renderMeta();fecharModal('modalMeta');toast('Meta Ads salvo ✓');
  }catch(e){toast('Erro','danger');}
}

// Helpers

// ============================================================
// DETALHE / UPLOAD / REVISÃO
// ============================================================
let uploadFile = null;

const REV_LABELS = {
  aguardando: '— Aguardando',
  aprovado:   '✅ Aprovado',
  alteracao:  '⚠️ Alteração',
  reprovado:  '❌ Reprovado'
};

async function abrirDetalhe(id) {
  document.getElementById('detalhe_id').value = id;
  uploadFile = null;
  document.getElementById('uploadPreview').style.display = 'none';
  document.getElementById('uploadZoneText').style.display = 'block';
  document.getElementById('btnEnviarArquivo').style.display = 'none';
  document.getElementById('uploadProgress').style.display = 'none';

  try {
    const r = await api('criativo_briefing&id=' + id);
    const c = r.criativo;
    document.getElementById('detalhe_titulo').textContent = c.titulo;

    // Briefing — campos de texto
    const campos = [
      ['Objetivo da Peça',    c.briefing_objetivo],
      ['Público-alvo',        c.briefing_publico],
      ['Texto / Copy',        c.briefing_copy],
      ['Referências Visuais', c.briefing_referencias],
      ['Paleta de Cores',     c.briefing_cores],
    ];
    const temBriefing = campos.some(([,v]) => v) || c.briefing_imagens;
    const briefingEl  = document.getElementById('detalhe_briefing_content');

    if (temBriefing) {
      let html = campos.filter(([,v]) => v).map(([label, val]) => `
        <div class="briefing-card">
          <div class="briefing-label">${label}</div>
          <div class="briefing-val">${esc(val)}</div>
        </div>`).join('');

      // Imagens de referência — galeria
      if (c.briefing_imagens) {
        let imgs = [];
        try { imgs = JSON.parse(c.briefing_imagens); } catch(e) {}
        if (imgs.length) {
          // Guarda as URLs num array global acessível pelo onclick
          window._briefingImgs = imgs;
          html += `<div class="briefing-card">
            <div class="briefing-label">🖼️ Imagens de Referência (${imgs.length})</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-top:8px">
              ${imgs.map((url, i) => `
                <div onclick="abrirLightbox(window._briefingImgs,${i})"
                     title="Clique para ampliar"
                     style="cursor:zoom-in;border-radius:8px;overflow:hidden;border:1px solid var(--border)">
                  <img src="${esc(url)}" alt="Referência ${i+1}"
                       style="width:100%;height:100px;object-fit:cover;display:block;transition:opacity .2s"
                       onmouseover="this.style.opacity='.75'"
                       onmouseout="this.style.opacity='1'"
                       onerror="this.parentElement.style.display='none'">
                </div>`).join('')}
            </div>
            <div style="font-size:.7rem;color:var(--muted);margin-top:8px;font-family:JetBrains Mono,monospace">
              Clique em uma imagem para ampliar
            </div>
          </div>`;
        }
      }
      briefingEl.innerHTML = html;
    } else {
      briefingEl.innerHTML = '<div style="color:var(--muted);font-size:.85rem">Nenhum briefing preenchido.</div>';
    }

    // Arquivo atual
    const arquivoSec = document.getElementById('detalhe_arquivo_atual');
    const previewAtual = document.getElementById('detalhe_preview_atual');
    if (c.arquivo_url) {
      arquivoSec.style.display = 'block';
      previewAtual.src = c.arquivo_url;
    } else {
      arquivoSec.style.display = 'none';
    }

    // Revisão
    const revSec = document.getElementById('detalhe_revisao_sec');
    const revContent = document.getElementById('detalhe_revisao_content');
    if (c.arquivo_url && c.revisao_status && c.revisao_status !== 'aguardando') {
      revSec.style.display = 'block';
      const stars = c.revisao_nota ? '⭐'.repeat(c.revisao_nota) + '☆'.repeat(5 - c.revisao_nota) : '—';
      const data  = c.revisao_data ? new Date(c.revisao_data).toLocaleDateString('pt-BR') : '';
      revContent.innerHTML = `
        <div class="revisao-block ${c.revisao_status}">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <span class="rev-badge ${c.revisao_status}">${REV_LABELS[c.revisao_status]||c.revisao_status}</span>
            <span style="font-size:.78rem;color:var(--muted);font-family:JetBrains Mono,monospace">${data}</span>
            <span style="font-size:1rem;letter-spacing:1px">${stars}</span>
          </div>
          ${c.revisao_comentario ? `<div style="font-size:.875rem;line-height:1.6">${esc(c.revisao_comentario)}</div>` : ''}
        </div>`;
    } else if (c.arquivo_url) {
      revSec.style.display = 'block';
      revContent.innerHTML = '<div style="color:var(--muted);font-size:.85rem">Aguardando revisão do cliente.</div>';
    } else {
      revSec.style.display = 'none';
    }

    document.getElementById('modalDetalhe').classList.add('active');
  } catch(e) { toast('Erro ao carregar criativo','danger'); }
}

function handleFileSelect(file) {
  if (!file) return;
  uploadFile = file;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('uploadPreview');
    img.src = e.target.result;
    img.style.display = 'block';
    document.getElementById('uploadZoneText').style.display = 'none';
    document.getElementById('btnEnviarArquivo').style.display = 'inline-flex';
  };
  reader.readAsDataURL(file);
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.remove('drag');
  const file = e.dataTransfer.files[0];
  if (file) handleFileSelect(file);
}

async function enviarArquivo() {
  if (!uploadFile) return;
  const id = document.getElementById('detalhe_id').value;
  const btn = document.getElementById('btnEnviarArquivo');
  const prog = document.getElementById('uploadProgress');
  const bar  = document.getElementById('uploadBar');

  btn.disabled = true;
  btn.textContent = 'Enviando...';
  prog.style.display = 'block';

  const fd = new FormData();
  fd.append('criativo_id', id);
  fd.append('arquivo', uploadFile);

  const xhr = new XMLHttpRequest();
  xhr.upload.onprogress = e => {
    if (e.lengthComputable) bar.style.width = Math.round(e.loaded/e.total*100) + '%';
  };
  xhr.onload = () => {
    try {
      const r = JSON.parse(xhr.responseText);
      if (r.ok) {
        toast('Arquivo enviado! Cliente pode revisar agora ✓');
        fecharModal('modalDetalhe');
        // Atualiza lista
        api('criativos_admin').then(r => { criativos = r.criativos || []; renderCriativos(); renderDashboard(); });
      } else {
        toast(r.erro || 'Erro no upload','danger');
      }
    } catch { toast('Erro no upload','danger'); }
    btn.disabled = false;
    btn.textContent = '📤 Enviar para Revisão';
    prog.style.display = 'none';
  };
  xhr.onerror = () => { toast('Erro de conexão','danger'); btn.disabled = false; prog.style.display = 'none'; };
  xhr.open('POST', 'upload.php');
  xhr.withCredentials = true;
  xhr.send(fd);
}



// Upload inline no modal criativo
let crUploadFile = null;

function crHandleFile(file){
  if(!file) return;
  uploadFile = file;
  const isVideo = file.type.startsWith('video/');
  const vid = document.getElementById('crVideoPreview');
  if(vid){ vid.style.display = isVideo ? 'block' : 'none'; if(isVideo){ vid.src=URL.createObjectURL(file); document.getElementById('crUploadPreview').style.display='none'; document.getElementById('crUploadZoneText').style.display='none'; document.getElementById('crBtnEnviar').style.display='block'; return; } }
  // fallback imagem
  if(!file) return;
  crUploadFile=file;
  const reader=new FileReader();
  reader.onload=e=>{
    const img=document.getElementById('crUploadPreview');
    img.src=e.target.result; img.style.display='block';
    document.getElementById('crUploadZoneText').style.display='none';
    document.getElementById('crBtnEnviar').style.display='inline-flex';
  };
  reader.readAsDataURL(file);
}

function crHandleDrop(e){
  e.preventDefault();
  document.getElementById('crUploadZone').classList.remove('drag');
  const file=e.dataTransfer.files[0];
  if(file) crHandleFile(file);
}

async function crEnviarArquivo(){
  if(!crUploadFile) return;
  const id=document.getElementById('crId').value;
  const btn=document.getElementById('crBtnEnviar');
  const prog=document.getElementById('crUploadProgress');
  const bar=document.getElementById('crUploadBar');
  btn.disabled=true; btn.textContent='Enviando...'; prog.style.display='block';
  const fd=new FormData();
  fd.append('criativo_id',id); fd.append('arquivo',crUploadFile);
  const xhr=new XMLHttpRequest();
  xhr.upload.onprogress=e=>{if(e.lengthComputable)bar.style.width=Math.round(e.loaded/e.total*100)+'%';};
  xhr.onload=()=>{
    try{
      const r=JSON.parse(xhr.responseText);
      if(r.ok){
        toast('Arquivo enviado! Cliente pode revisar ✓');
        // Atualiza preview imediatamente
        document.getElementById('crArquivoPreviewAtual').src=r.arquivo_url;
        document.getElementById('crArquivoAtual').style.display='block';
        document.getElementById('crArquivoNome').textContent=r.arquivo_nome||'arquivo';
        document.getElementById('crRevisaoStatus').innerHTML='<span style="color:var(--muted);font-weight:700">⏳ Aguardando revisão</span>';
        document.getElementById('crUploadPreview').style.display='none';
        document.getElementById('crUploadZoneText').style.display='block';
        document.getElementById('crUploadLabel').textContent='Substituir arquivo atual';
        crUploadFile=null;
        // Atualiza lista em background
        api('criativos_admin').then(r=>{criativos=r.criativos||[];renderCriativos();renderDashboard();});
      } else { toast(r.erro||'Erro no upload','danger'); }
    } catch{ toast('Erro no upload','danger'); }
    btn.disabled=false; btn.textContent='📤 Enviar para Cliente';
    btn.style.display='none'; prog.style.display='none';
  };
  xhr.onerror=()=>{ toast('Erro de conexão','danger'); btn.disabled=false; prog.style.display='none'; };
  xhr.open('POST','upload.php'); xhr.withCredentials=true; xhr.send(fd);
}

function fecharModal(id){document.getElementById(id).classList.remove('active');}
function fecharOverlay(e,id){if(e.target===document.getElementById(id))fecharModal(id);}
function selecionarCor(cor){document.getElementById('cliCor').value=cor;document.querySelectorAll('.color-swatch').forEach(sw=>sw.classList.toggle('active',sw.style.background===cor));}
function toggleVis(id){const el=document.getElementById(id);el.type=el.type==='password'?'text':'password';}
function toast(msg,tipo='success'){const c=document.getElementById('toastContainer');const t=document.createElement('div');t.className='toast';if(tipo==='danger')t.style.borderLeftColor='var(--danger)';t.textContent=msg;c.appendChild(t);setTimeout(()=>t.remove(),3200);}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')['modalCliente','modalCriativo','modalMeta'].forEach(fecharModal);});
init();
</script>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="if(event.target===this)fecharLightbox()">
  <button class="lightbox-close" onclick="fecharLightbox()">✕</button>
  <button class="lightbox-nav lightbox-prev" id="lbPrev" onclick="lbNavegar(-1)" style="display:none">‹</button>
  <img id="lightboxImg" src="" alt="">
  <button class="lightbox-nav lightbox-next" id="lbNext" onclick="lbNavegar(1)" style="display:none">›</button>
  <div class="lightbox-counter" id="lbCounter" style="display:none"></div>
</div>

<script>
(function(){
  const DARK  = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAEsCAYAAAA1u0HIAAAAtGVYSWZJSSoACAAAAAYAEgEDAAEAAAABAAAAGgEFAAEAAABWAAAAGwEFAAEAAABeAAAAKAEDAAEAAAACAAAAEwIDAAEAAAABAAAAaYcEAAEAAABmAAAAAAAAAGAAAAABAAAAYAAAAAEAAAAGAACQBwAEAAAAMDIxMAGRBwAEAAAAAQIDAACgBwAEAAAAMDEwMAGgAwABAAAA//8AAAKgBAABAAAA9AEAAAOgBAABAAAALAEAAAAAAADVGa7tAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAFRmlUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSfvu78nIGlkPSdXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQnPz4KPHg6eG1wbWV0YSB4bWxuczp4PSdhZG9iZTpuczptZXRhLyc+CjxyZGY6UkRGIHhtbG5zOnJkZj0naHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyc+CgogPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9JycKICB4bWxuczpBdHRyaWI9J2h0dHA6Ly9ucy5hdHRyaWJ1dGlvbi5jb20vYWRzLzEuMC8nPgogIDxBdHRyaWI6QWRzPgogICA8cmRmOlNlcT4KICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0nUmVzb3VyY2UnPgogICAgIDxBdHRyaWI6Q3JlYXRlZD4yMDI2LTAzLTE5PC9BdHRyaWI6Q3JlYXRlZD4KICAgICA8QXR0cmliOkRhdGE+eyZxdW90O2RvYyZxdW90OzomcXVvdDtEQUhFWkEzcGM3NCZxdW90OywmcXVvdDt1c2VyJnF1b3Q7OiZxdW90O1VBREc1T2IzeDRrJnF1b3Q7LCZxdW90O2JyYW5kJnF1b3Q7OiZxdW90O0JBREc1QzRMVC1vJnF1b3Q7fTwvQXR0cmliOkRhdGE+CiAgICAgPEF0dHJpYjpFeHRJZD4yOTQ4NDk3YS04NjA4LTRhODQtOWVkNy1kMTNlOGRjNDFhMGQ8L0F0dHJpYjpFeHRJZD4KICAgICA8QXR0cmliOkZiSWQ+NTI1MjY1OTE0MTc5NTgwPC9BdHRyaWI6RmJJZD4KICAgICA8QXR0cmliOlRvdWNoVHlwZT4yPC9BdHRyaWI6VG91Y2hUeXBlPgogICAgPC9yZGY6bGk+CiAgIDwvcmRmOlNlcT4KICA8L0F0dHJpYjpBZHM+CiA8L3JkZjpEZXNjcmlwdGlvbj4KCiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0nJwogIHhtbG5zOmRjPSdodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyc+CiAgPGRjOnRpdGxlPgogICA8cmRmOkFsdD4KICAgIDxyZGY6bGkgeG1sOmxhbmc9J3gtZGVmYXVsdCc+RGVzaWduIHNlbSBub21lIC0gMTwvcmRmOmxpPgogICA8L3JkZjpBbHQ+CiAgPC9kYzp0aXRsZT4KIDwvcmRmOkRlc2NyaXB0aW9uPgoKIDxyZGY6RGVzY3JpcHRpb24gcmRmOmFib3V0PScnCiAgeG1sbnM6cGRmPSdodHRwOi8vbnMuYWRvYmUuY29tL3BkZi8xLjMvJz4KICA8cGRmOkF1dGhvcj5OaW5hIFppbW1lcm1hbm48L3BkZjpBdXRob3I+CiA8L3JkZjpEZXNjcmlwdGlvbj4KCiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0nJwogIHhtbG5zOnhtcD0naHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wLyc+CiAgPHhtcDpDcmVhdG9yVG9vbD5DYW52YSBkb2M9REFIRVpBM3BjNzQgdXNlcj1VQURHNU9iM3g0ayBicmFuZD1CQURHNUM0TFQtbzwveG1wOkNyZWF0b3JUb29sPgogPC9yZGY6RGVzY3JpcHRpb24+CjwvcmRmOlJERj4KPC94OnhtcG1ldGE+Cjw/eHBhY2tldCBlbmQ9J3InPz7MEE3LAAAgAElEQVR4nOydB3wdxbX/TSCFl0AqISGE0N2rbMu2iiW5AAlpJCSBR0gj5CUheS/9/WmipEBeKElIwGDAvciWiySr2UauuCDbsq1muQgbN9Vbtk3Zu/M/s7uzdyVLsnTvlSVZ8/PneK9u2Z2d3Z3vnClnhgyRkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKS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";
  const LIGHT = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAEsCAYAAAA1u0HIAAAAtGVYSWZJSSoACAAAAAYAEgEDAAEAAAABAAAAGgEFAAEAAABWAAAAGwEFAAEAAABeAAAAKAEDAAEAAAACAAAAEwIDAAEAAAABAAAAaYcEAAEAAABmAAAAAAAAAGAAAAABAAAAYAAAAAEAAAAGAACQBwAEAAAAMDIxMAGRBwAEAAAAAQIDAACgBwAEAAAAMDEwMAGgAwABAAAA//8AAAKgBAABAAAA9AEAAAOgBAABAAAALAEAAAAAAADVGa7tAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAFRmlUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSfvu78nIGlkPSdXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQnPz4KPHg6eG1wbWV0YSB4bWxuczp4PSdhZG9iZTpuczptZXRhLyc+CjxyZGY6UkRGIHhtbG5zOnJkZj0naHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyc+CgogPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9JycKICB4bWxuczpBdHRyaWI9J2h0dHA6Ly9ucy5hdHRyaWJ1dGlvbi5jb20vYWRzLzEuMC8nPgogIDxBdHRyaWI6QWRzPgogICA8cmRmOlNlcT4KICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0nUmVzb3VyY2UnPgogICAgIDxBdHRyaWI6Q3JlYXRlZD4yMDI2LTAzLTE5PC9BdHRyaWI6Q3JlYXRlZD4KICAgICA8QXR0cmliOkRhdGE+eyZxdW90O2RvYyZxdW90OzomcXVvdDtEQUhFWkEzcGM3NCZxdW90OywmcXVvdDt1c2VyJnF1b3Q7OiZxdW90O1VBREc1T2IzeDRrJnF1b3Q7LCZxdW90O2JyYW5kJnF1b3Q7OiZxdW90O0JBREc1QzRMVC1vJnF1b3Q7fTwvQXR0cmliOkRhdGE+CiAgICAgPEF0dHJpYjpFeHRJZD4yOTQ4NDk3YS04NjA4LTRhODQtOWVkNy1kMTNlOGRjNDFhMGQ8L0F0dHJpYjpFeHRJZD4KICAgICA8QXR0cmliOkZiSWQ+NTI1MjY1OTE0MTc5NTgwPC9BdHRyaWI6RmJJZD4KICAgICA8QXR0cmliOlRvdWNoVHlwZT4yPC9BdHRyaWI6VG91Y2hUeXBlPgogICAgPC9yZGY6bGk+CiAgIDwvcmRmOlNlcT4KICA8L0F0dHJpYjpBZHM+CiA8L3JkZjpEZXNjcmlwdGlvbj4KCiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0nJwogIHhtbG5zOmRjPSdodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyc+CiAgPGRjOnRpdGxlPgogICA8cmRmOkFsdD4KICAgIDxyZGY6bGkgeG1sOmxhbmc9J3gtZGVmYXVsdCc+RGVzaWduIHNlbSBub21lIC0gMTwvcmRmOmxpPgogICA8L3JkZjpBbHQ+CiAgPC9kYzp0aXRsZT4KIDwvcmRmOkRlc2NyaXB0aW9uPgoKIDxyZGY6RGVzY3JpcHRpb24gcmRmOmFib3V0PScnCiAgeG1sbnM6cGRmPSdodHRwOi8vbnMuYWRvYmUuY29tL3BkZi8xLjMvJz4KICA8cGRmOkF1dGhvcj5OaW5hIFppbW1lcm1hbm48L3BkZjpBdXRob3I+CiA8L3JkZjpEZXNjcmlwdGlvbj4KCiA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0nJwogIHhtbG5zOnhtcD0naHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wLyc+CiAgPHhtcDpDcmVhdG9yVG9vbD5DYW52YSBkb2M9REFIRVpBM3BjNzQgdXNlcj1VQURHNU9iM3g0ayBicmFuZD1CQURHNUM0TFQtbzwveG1wOkNyZWF0b3JUb29sPgogPC9yZGY6RGVzY3JpcHRpb24+CjwvcmRmOlJERj4KPC94OnhtcG1ldGE+Cjw/eHBhY2tldCBlbmQ9J3InPz7MEE3LAAAgAElEQVR4nOydB3wdxbX/TSCFl0AqISGE0N2rbMu2iiW5AAlpJCSBR0gj5CUheS/9/WmipEBeKElIwGDAvciWiySr2UauuCDbsq1muQgbN9Vbtk3Zu/M/s7uzdyVLsnTvlSVZ8/PneK9u2Z2d3Z3vnClnhgyRkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKSkpKS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";
  function syncLogos(){
    const isLight = window.matchMedia('(prefers-color-scheme:light)').matches;
    const src = isLight ? LIGHT : DARK;
    ['navLogo','logoMain','logoReg','heroLogo','logoFb'].forEach(id=>{
      const el = document.getElementById(id);
      if(el && el.tagName==='IMG') el.src = src;
    });
  }
  window.matchMedia('(prefers-color-scheme:light)').addEventListener('change', syncLogos);
  document.addEventListener('DOMContentLoaded', syncLogos);
  syncLogos();
})();
</script>

<!-- MODAL TROCA DE LOGO -->
<div class="logo-modal" id="logoModal" onclick="if(event.target===this)fecharLogoModal()">
  <div class="logo-modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.3rem;letter-spacing:2px;color:var(--accent)">Trocar Logo</div>
      <button onclick="fecharLogoModal()" style="background:none;border:none;color:var(--muted);font-size:1.4rem;cursor:pointer">✕</button>
    </div>
    <div class="logo-upload-zone" id="logoZone"
         onclick="document.getElementById('logoFileInput').click()"
         ondragover="event.preventDefault();this.classList.add('drag')"
         ondragleave="this.classList.remove('drag')"
         ondrop="logoHandleDrop(event)">
      <input type="file" id="logoFileInput" accept="image/jpeg,image/png,image/webp,image/svg+xml"
             onchange="logoHandleFile(this.files[0])">
      <div id="logoZoneText">
        <div style="font-size:2rem;margin-bottom:.75rem">🖼️</div>
        <div style="font-weight:600;font-size:.9rem;margin-bottom:.35rem">Clique ou arraste sua logo aqui</div>
        <div style="font-size:.75rem;color:var(--muted);font-family:'JetBrains Mono',monospace">JPG, PNG, WebP ou SVG · Máx. 2MB</div>
      </div>
    </div>
    <div class="logo-preview-wrap" id="logoPreviewWrap">
      <img id="logoPreviewImg" src="" alt="Preview">
      <div style="font-size:.72rem;color:var(--muted);margin-top:.5rem;font-family:'JetBrains Mono',monospace" id="logoPreviewNome"></div>
    </div>
    <div id="logoUploadProgress" style="display:none;margin-top:1rem">
      <div style="background:var(--surface2);border-radius:4px;height:4px;overflow:hidden">
        <div style="height:100%;background:var(--accent);width:0%;transition:width .3s" id="logoProgressBar"></div>
      </div>
      <div style="font-size:.72rem;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace">Enviando...</div>
    </div>
    <div id="logoErro" style="display:none;color:var(--danger);font-size:.8rem;margin-top:.75rem;font-family:'JetBrains Mono',monospace"></div>
    <div style="display:flex;gap:10px;margin-top:1.5rem;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="fecharLogoModal()">Cancelar</button>
      <button class="btn btn-primary" id="logoBtnEnviar" onclick="logoEnviar()" disabled>📤 Aplicar logo</button>
    </div>
  </div>
</div>

<script src="menu_lateral.js?v=1" defer></script>
</body>
</html>
