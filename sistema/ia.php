<?php
require_once __DIR__ . '/auth.php';
exigeLogin('admin');
exigeTenantAtivo();
$tenant = tenantAtual();
$tid    = $tenant['id'];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IA Criativa — Opa Studio</title>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0d0d0d">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script src="/theme.js"></script>
<style>
/* ── TEMA ── */
:root { --bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;--accent:#78be20;--text:#e8eaf0;--muted:#585858;--success:#4ade80;--warning:#fbbf24;--danger:#f87171 }
[data-tema="light"] { --bg:#f5f5f0;--surface:#ffffff;--surface2:#f0f0eb;--border:#ddddd8;--accent:#3d7a0a;--text:#1a1a1a;--muted:#888880;--success:#2d7a4f;--warning:#8a6200;--danger:#b83232 }
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;transition:background .3s,color .3s}
header{position:sticky;top:0;z-index:100;background:rgba(13,13,13,.93);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);padding:0 2rem;display:flex;align-items:center;justify-content:space-between;height:64px}
[data-tema="light"] header{background:rgba(245,245,240,.93)}
.logo{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:2px;color:var(--accent);display:flex;align-items:center;gap:8px}
.logo small{color:var(--muted);font-size:.78rem;font-family:'JetBrains Mono',monospace;font-weight:400}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.875rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--accent);color:#0d0d0d}.btn-primary:hover{filter:brightness(1.05);transform:translateY(-1px)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface2);color:var(--text)}
.btn-sm{padding:6px 14px;font-size:.8rem}
main{max-width:1100px;margin:0 auto;padding:2rem}
/* TABS */
.tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:4px;margin-bottom:2rem;overflow-x:auto}
.tab-btn{flex:1;min-width:120px;padding:10px 16px;border-radius:9px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;color:var(--muted);background:transparent;transition:all .2s;white-space:nowrap}
.tab-btn.active{background:var(--accent);color:#0d0d0d}
.tab-btn:hover:not(.active){background:var(--surface2);color:var(--text)}
.tab-panel{display:none}.tab-panel.active{display:block;animation:fadeUp .3s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
/* FORM */
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.75rem;margin-bottom:1.25rem}
.card-title{font-family:'Bebas Neue',sans-serif;font-size:1.2rem;letter-spacing:1.5px;color:var(--accent);margin-bottom:1.25rem}
.form-group{margin-bottom:1rem}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
label.lbl{display:block;font-size:.68rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:6px}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:10px 13px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;transition:border-color .15s}
.form-control:focus{border-color:var(--accent)}.form-control::placeholder{color:var(--muted)}
textarea.form-control{resize:vertical;min-height:80px}
/* RESULTADO */
.result-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:.75rem;transition:border-color .2s}
.result-card:hover{border-color:var(--accent)}
.result-titulo{font-weight:600;font-size:.875rem;margin-bottom:.5rem;color:var(--accent)}
.result-texto{font-size:.875rem;line-height:1.7;white-space:pre-wrap;color:var(--text)}
.copy-btn{font-size:.72rem;padding:4px 10px;border-radius:6px;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--muted);transition:all .15s;float:right;margin-left:8px}
.copy-btn:hover{background:var(--accent);color:#0d0d0d;border-color:var(--accent)}
/* HASHTAGS */
.hashtag-grid{display:flex;flex-wrap:wrap;gap:6px}
.hashtag-pill{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:4px 12px;font-size:.78rem;font-family:'JetBrains Mono',monospace;cursor:pointer;transition:all .15s;color:var(--text)}
.hashtag-pill:hover,.hashtag-pill.sel{background:var(--accent);color:#0d0d0d;border-color:var(--accent)}
/* IMAGEM */
.img-result{width:100%;max-width:512px;border-radius:12px;border:1px solid var(--border);display:block;margin:0 auto}
.img-actions{display:flex;gap:8px;justify-content:center;margin-top:1rem;flex-wrap:wrap}
/* BRIEFING */
.conceito-card{background:linear-gradient(135deg,var(--surface2),var(--surface));border:1px solid var(--accent);border-radius:12px;padding:1.5rem;margin-bottom:1rem}
.paleta-preview{display:flex;gap:8px;margin-top:.75rem}
.cor-dot{width:36px;height:36px;border-radius:8px;cursor:pointer;border:2px solid transparent;transition:transform .15s;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:#fff}
.cor-dot:hover{transform:scale(1.15)}
.dica-item{display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border);font-size:.85rem}
.dica-item:last-child{border-bottom:none}
/* LOADING */
.loading-wrap{display:flex;flex-direction:column;align-items:center;gap:1rem;padding:3rem;color:var(--muted)}
.loading-spin{width:40px;height:40px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
/* AVISO */
.aviso{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:12px 16px;font-size:.82rem;color:var(--warning);margin-bottom:1.25rem;font-family:'JetBrains Mono',monospace;line-height:1.6}
/* KEY CONFIG */
.key-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:.72rem;font-family:'JetBrains Mono',monospace}
.key-ok{background:rgba(74,222,128,.1);color:var(--success);border:1px solid rgba(74,222,128,.2)}
.key-no{background:rgba(248,113,113,.1);color:var(--danger);border:1px solid rgba(248,113,113,.2)}
/* TOAST */
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:300;display:flex;flex-direction:column;gap:8px}
.toast{background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--accent);color:var(--text);padding:11px 18px;border-radius:10px;font-size:.875rem;font-weight:500;animation:toastIn .3s ease,toastOut .3s ease 2.7s both}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{to{opacity:0;transform:translateX(20px)}}
@media(max-width:768px){main{padding:1rem}.form-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <div class="logo">◈ Opa Studio <small>IA criativa</small></div>
  <div style="display:flex;gap:10px;align-items:center">
    <a href="admin.php" class="btn btn-ghost btn-sm">← Painel</a>
  </div>
</header>

<main>

  <?php
  // Verifica chave OpenAI
  $st = db()->prepare("SELECT openai_key FROM tenants WHERE id=?");
  $st->execute([$tid]);
  $t_row = $st->fetch();
  $tem_key = !empty($t_row['openai_key']);
  ?>

  <?php if (!$tem_key): ?>
  <div class="aviso">
    ⚠️ Chave OpenAI não configurada. <a href="#" onclick="mostrarKeyConfig()" style="color:var(--accent);font-weight:700">Configurar agora →</a>
  </div>
  <?php endif ?>

  <!-- CONFIG KEY (visível se sem chave) -->
  <div id="keyConfig" style="<?= $tem_key ? 'display:none' : '' ?>">
    <div class="card" style="border-color:var(--accent)">
      <div class="card-title">🔑 Configurar Chave OpenAI</div>
      <div style="font-size:.85rem;color:var(--muted);margin-bottom:1rem">
        Acesse <a href="https://platform.openai.com/api-keys" target="_blank" style="color:var(--accent)">platform.openai.com/api-keys</a> para gerar sua chave API.
      </div>
      <div class="form-group">
        <label class="lbl">Chave API OpenAI (sk-...)</label>
        <input type="password" class="form-control" id="inputKey" placeholder="sk-proj-...">
      </div>
      <button class="btn btn-primary" onclick="salvarKey()">Salvar Chave</button>
      <span id="keyStatus" class="key-badge" style="margin-left:10px;display:<?= $tem_key?'inline-flex':'none'?>">
        <?= $tem_key ? '✓ Configurada' : '' ?>
      </span>
    </div>
  </div>

  <?php if ($tem_key): ?>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:1.5rem;flex-wrap:wrap">
    <span class="key-badge key-ok">✓ OpenAI conectada</span>
    <button class="btn btn-ghost btn-sm" onclick="mostrarKeyConfig()">Trocar chave</button>
  </div>
  <?php endif ?>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" onclick="aba('legenda',this)">✍️ Legendas</button>
    <button class="tab-btn" onclick="aba('hashtags',this)">🏷️ Hashtags</button>
    <button class="tab-btn" onclick="aba('imagem',this)">🎨 Imagens</button>
    <button class="tab-btn" onclick="aba('briefing',this)">📋 Briefing IA</button>
  </div>

  <!-- LEGENDAS -->
  <div class="tab-panel active" id="panel-legenda">
    <div class="card">
      <div class="card-title">✍️ Gerar Legendas</div>
      <div class="form-row">
        <div class="form-group">
          <label class="lbl">Produto / Serviço</label>
          <input type="text" class="form-control" id="leg_produto" placeholder="Ex: Consultoria de marketing">
        </div>
        <div class="form-group">
          <label class="lbl">Plataforma</label>
          <select class="form-control" id="leg_plataforma">
            <option>Instagram</option><option>Facebook</option><option>LinkedIn</option>
            <option>TikTok</option><option>WhatsApp</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="lbl">Objetivo da postagem</label>
        <input type="text" class="form-control" id="leg_objetivo" placeholder="Ex: Gerar leads, aumentar engajamento, promover produto">
      </div>
      <div class="form-group">
        <label class="lbl">Tom de voz</label>
        <select class="form-control" id="leg_tom">
          <option value="profissional e engajador">Profissional e engajador</option>
          <option value="descontraído e divertido">Descontraído e divertido</option>
          <option value="inspiracional e motivacional">Inspiracional e motivacional</option>
          <option value="urgente e persuasivo">Urgente e persuasivo</option>
          <option value="educativo e informativo">Educativo e informativo</option>
          <option value="íntimo e pessoal">Íntimo e pessoal</option>
        </select>
      </div>
      <button class="btn btn-primary" onclick="gerarLegendas()" id="btnLegenda">✨ Gerar 3 Opções</button>
    </div>
    <div id="resultLegenda"></div>
  </div>

  <!-- HASHTAGS -->
  <div class="tab-panel" id="panel-hashtags">
    <div class="card">
      <div class="card-title">🏷️ Sugerir Hashtags</div>
      <div class="form-row">
        <div class="form-group">
          <label class="lbl">Nicho / Segmento</label>
          <input type="text" class="form-control" id="ht_nicho" placeholder="Ex: Moda feminina, Gastronomia, Fitness">
        </div>
        <div class="form-group">
          <label class="lbl">Plataforma</label>
          <select class="form-control" id="ht_plataforma">
            <option>Instagram</option><option>TikTok</option><option>LinkedIn</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="lbl">Sobre o que é o post?</label>
        <textarea class="form-control" id="ht_conteudo" placeholder="Descreva brevemente o conteúdo do post..."></textarea>
      </div>
      <button class="btn btn-primary" onclick="gerarHashtags()" id="btnHashtags">🏷️ Gerar Hashtags</button>
    </div>
    <div id="resultHashtags"></div>
  </div>

  <!-- IMAGENS -->
  <div class="tab-panel" id="panel-imagem">
    <div class="card">
      <div class="card-title">🎨 Gerar Imagem com DALL-E 3</div>
      <div class="aviso">
        ⚠️ As imagens geradas são URLs temporárias do OpenAI (válidas por ~1 hora). Faça download imediatamente após a geração.
      </div>
      <div class="form-group">
        <label class="lbl">Descreva a imagem que quer criar</label>
        <textarea class="form-control" id="img_prompt" rows="4"
          placeholder="Ex: Logo de agência de marketing moderno com tons de amarelo e preto, estilo minimalista&#10;&#10;Ex: Mulher sorridente segurando produto de skincare, fundo branco limpo, qualidade fotográfica"></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="lbl">Formato</label>
          <select class="form-control" id="img_size">
            <option value="1024x1024">Quadrado (1024×1024) — Feed</option>
            <option value="1792x1024">Paisagem (1792×1024) — Capa</option>
            <option value="1024x1792">Retrato (1024×1792) — Story</option>
          </select>
        </div>
        <div class="form-group">
          <label class="lbl">Estilo adicional</label>
          <select class="form-control" id="img_estilo">
            <option value="">Nenhum</option>
            <option value="fotorrealista">Fotorrealista</option>
            <option value="ilustração vetorial">Ilustração vetorial</option>
            <option value="flat design moderno">Flat design</option>
            <option value="aquarela artística">Aquarela</option>
            <option value="estilo 3D render">3D Render</option>
            <option value="estilo minimalista">Minimalista</option>
          </select>
        </div>
      </div>
      <button class="btn btn-primary" onclick="gerarImagem()" id="btnImagem">🎨 Gerar Imagem</button>
    </div>
    <div id="resultImagem"></div>
  </div>

  <!-- BRIEFING IA -->
  <div class="tab-panel" id="panel-briefing">
    <div class="card">
      <div class="card-title">📋 Analisar Briefing com IA</div>
      <div style="font-size:.85rem;color:var(--muted);margin-bottom:1.25rem">
        Cole os dados do briefing do cliente e a IA sugere conceito visual, paleta de cores e prompt pronto para gerar a imagem.
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="lbl">Título / Peça</label>
          <input type="text" class="form-control" id="bf_titulo" placeholder="Nome do criativo">
        </div>
        <div class="form-group">
          <label class="lbl">Público-alvo</label>
          <input type="text" class="form-control" id="bf_publico" placeholder="Ex: Mulheres 25-40 anos">
        </div>
      </div>
      <div class="form-group">
        <label class="lbl">Objetivo da peça</label>
        <textarea class="form-control" id="bf_objetivo" placeholder="O que este criativo deve comunicar?"></textarea>
      </div>
      <div class="form-group">
        <label class="lbl">Copy / Texto sugerido</label>
        <textarea class="form-control" id="bf_copy" placeholder="Texto que deve aparecer na peça"></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="lbl">Referências visuais</label>
          <input type="text" class="form-control" id="bf_referencias" placeholder="Links ou descrição">
        </div>
        <div class="form-group">
          <label class="lbl">Cores do cliente</label>
          <input type="text" class="form-control" id="bf_cores" placeholder="Ex: Azul e dourado">
        </div>
      </div>
      <button class="btn btn-primary" onclick="analisarBriefing()" id="btnBriefing">🤖 Analisar com IA</button>
    </div>
    <div id="resultBriefing"></div>
  </div>

</main>
<div class="toast-container" id="toastContainer"></div>

<script>
// ── TABS ─────────────────────────────────────────────────
function aba(nome, el){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel-'+nome).classList.add('active');
  el.classList.add('active');
}

// ── API ──────────────────────────────────────────────────
async function apiFetch(action, data=null){
  const r = await fetch(`api.php?action=${action}`,{
    method:'POST',credentials:'include',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(data||{})
  });
  return r.json();
}

// ── CHAVE ────────────────────────────────────────────────
function mostrarKeyConfig(){
  const el = document.getElementById('keyConfig');
  if(el) el.style.display = el.style.display==='none'?'block':'none';
}

async function salvarKey(){
  const key = document.getElementById('inputKey').value.trim();
  if(!key||!key.startsWith('sk-')){ toast('Chave inválida — deve começar com sk-','danger'); return; }
  const r = await apiFetch('salvar_openai_key',{key});
  if(r.ok){
    toast('Chave salva ✓');
    document.getElementById('keyConfig').style.display='none';
    location.reload();
  } else { toast(r.erro||'Erro','danger'); }
}

// ── LOADING ──────────────────────────────────────────────
function setLoading(elId, msg='Gerando com IA...'){
  document.getElementById(elId).innerHTML=`
    <div class="loading-wrap">
      <div class="loading-spin"></div>
      <div style="font-family:JetBrains Mono,monospace;font-size:.82rem">${msg}</div>
    </div>`;
}

function setBtn(id, loading){
  const b=document.getElementById(id);
  if(!b) return;
  b.disabled=loading;
  if(loading) b.style.opacity='.6';
  else b.style.opacity='1';
}

// ── LEGENDAS ─────────────────────────────────────────────
async function gerarLegendas(){
  const dados={
    produto:    document.getElementById('leg_produto').value.trim(),
    plataforma: document.getElementById('leg_plataforma').value,
    objetivo:   document.getElementById('leg_objetivo').value.trim(),
    tom:        document.getElementById('leg_tom').value,
  };
  if(!dados.produto){ toast('Informe o produto/serviço','danger'); return; }
  setLoading('resultLegenda','Gerando 3 opções de legenda...');
  setBtn('btnLegenda',true);
  const r = await apiFetch('ia_legenda',dados);
  setBtn('btnLegenda',false);
  if(!r.ok){ document.getElementById('resultLegenda').innerHTML=`<div style="color:var(--danger);font-size:.85rem;padding:1rem">${esc(r.erro)}</div>`; return; }
  document.getElementById('resultLegenda').innerHTML=(r.opcoes||[]).map((op,i)=>`
    <div class="result-card">
      <button class="copy-btn" onclick="copiar(\`${op.legenda.replace(/`/g,'\\`')}\`,this)">📋 Copiar</button>
      <div class="result-titulo">${esc(op.titulo)}</div>
      <div class="result-texto">${esc(op.legenda)}</div>
    </div>`).join('');
}

// ── HASHTAGS ─────────────────────────────────────────────
async function gerarHashtags(){
  const dados={
    nicho:      document.getElementById('ht_nicho').value.trim(),
    plataforma: document.getElementById('ht_plataforma').value,
    conteudo:   document.getElementById('ht_conteudo').value.trim(),
  };
  if(!dados.nicho){ toast('Informe o nicho','danger'); return; }
  setLoading('resultHashtags','Gerando hashtags...');
  setBtn('btnHashtags',true);
  const r = await apiFetch('ia_hashtags',dados);
  setBtn('btnHashtags',false);
  if(!r.ok){ document.getElementById('resultHashtags').innerHTML=`<div style="color:var(--danger);padding:1rem">${esc(r.erro)}</div>`; return; }

  const grupos = r.grupos || {};
  let html='<div class="card"><div class="card-title">Hashtags Geradas</div>';
  ['populares','nicho','locais'].forEach(g=>{
    if(grupos[g]?.length){
      html+=`<div style="font-family:JetBrains Mono,monospace;font-size:.68rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:.5rem;margin-top:1rem">${g}</div>`;
      html+=`<div class="hashtag-grid">${grupos[g].map(h=>`<span class="hashtag-pill" onclick="this.classList.toggle('sel')">${esc(h)}</span>`).join('')}</div>`;
    }
  });
  html+=`<div style="display:flex;gap:8px;margin-top:1.5rem;flex-wrap:wrap">
    <button class="btn btn-primary btn-sm" onclick="copiarSelecionadas()">📋 Copiar Selecionadas</button>
    <button class="btn btn-ghost btn-sm" onclick="document.querySelectorAll('.hashtag-pill').forEach(h=>h.classList.add('sel'))">Selecionar todas</button>
  </div></div>`;
  document.getElementById('resultHashtags').innerHTML=html;
}

function copiarSelecionadas(){
  const sel=Array.from(document.querySelectorAll('.hashtag-pill.sel')).map(h=>h.textContent);
  navigator.clipboard.writeText(sel.join(' ')).then(()=>toast(`${sel.length} hashtags copiadas ✓`));
}

// ── IMAGENS ──────────────────────────────────────────────
async function gerarImagem(){
  const prompt = document.getElementById('img_prompt').value.trim();
  const size   = document.getElementById('img_size').value;
  const estilo = document.getElementById('img_estilo').value;
  if(!prompt){ toast('Descreva a imagem','danger'); return; }
  setLoading('resultImagem','Gerando imagem com DALL-E 3... (pode levar ~15s)');
  setBtn('btnImagem',true);
  const r = await apiFetch('ia_imagem',{prompt:prompt+(estilo?', '+estilo:''), size});
  setBtn('btnImagem',false);
  if(!r.ok){ document.getElementById('resultImagem').innerHTML=`<div class="card"><div style="color:var(--danger)">${esc(r.erro)}</div></div>`; return; }
  document.getElementById('resultImagem').innerHTML=`
    <div class="card">
      <div class="card-title">Imagem Gerada</div>
      <img src="${esc(r.url)}" class="img-result" alt="Imagem gerada">
      <div class="img-actions">
        <a href="${esc(r.url)}" download="opa-studio-ia.png" target="_blank" class="btn btn-primary btn-sm">⬇️ Download</a>
        <button class="btn btn-ghost btn-sm" onclick="usarPromptBriefing('${r.prompt_usado?.replace(/'/g,"\\'")||''}')">Usar no Briefing IA</button>
      </div>
      <div style="font-size:.72rem;color:var(--muted);margin-top:1rem;font-family:JetBrains Mono,monospace;border-top:1px solid var(--border);padding-top:.75rem">
        Prompt usado: ${esc(r.prompt_usado||'')}
      </div>
    </div>`;
}

// ── BRIEFING IA ───────────────────────────────────────────
async function analisarBriefing(){
  const dados={
    titulo:                document.getElementById('bf_titulo').value.trim(),
    briefing_objetivo:     document.getElementById('bf_objetivo').value.trim(),
    briefing_publico:      document.getElementById('bf_publico').value.trim(),
    briefing_copy:         document.getElementById('bf_copy').value.trim(),
    briefing_referencias:  document.getElementById('bf_referencias').value.trim(),
    briefing_cores:        document.getElementById('bf_cores').value.trim(),
  };
  if(!dados.briefing_objetivo){ toast('Informe ao menos o objetivo','danger'); return; }
  setLoading('resultBriefing','Analisando briefing com IA...');
  setBtn('btnBriefing',true);
  const r = await apiFetch('ia_briefing',dados);
  setBtn('btnBriefing',false);
  if(!r.ok){ document.getElementById('resultBriefing').innerHTML=`<div class="card"><div style="color:var(--danger)">${esc(r.erro)}</div></div>`; return; }

  const paleta=r.paleta_sugerida||[];
  document.getElementById('resultBriefing').innerHTML=`
    <div class="conceito-card">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.1rem;letter-spacing:1.5px;color:var(--accent);margin-bottom:.75rem">💡 Conceito Visual</div>
      <div style="font-size:.9rem;line-height:1.7">${esc(r.conceito)}</div>
      ${paleta.length?`
      <div style="font-size:.72rem;color:var(--muted);font-family:JetBrains Mono,monospace;margin-top:1rem;margin-bottom:.5rem">PALETA SUGERIDA</div>
      <div class="paleta-preview">
        ${paleta.map(cor=>`<div class="cor-dot" style="background:${cor}" title="${cor}" onclick="copiar('${cor}',this)">${cor}</div>`).join('')}
      </div>`:''}
    </div>

    ${r.alerta?`<div class="aviso">⚠️ ${esc(r.alerta)}</div>`:''}

    <div class="card">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1rem;letter-spacing:1.5px;color:var(--text);margin-bottom:.75rem">Elementos Visuais Sugeridos</div>
      ${(r.elementos||[]).map(e=>`<div class="dica-item"><span style="color:var(--accent)">▸</span>${esc(e)}</div>`).join('')}
    </div>

    <div class="card">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1rem;letter-spacing:1.5px;color:var(--text);margin-bottom:.75rem">Dicas de Produção</div>
      ${(r.dicas||[]).map(d=>`<div class="dica-item"><span style="color:var(--success)">✓</span>${esc(d)}</div>`).join('')}
    </div>

    ${r.prompt_imagem?`
    <div class="card" style="border-color:var(--accent)">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1rem;letter-spacing:1.5px;color:var(--accent);margin-bottom:.75rem">🎨 Prompt para Gerar Imagem</div>
      <div style="font-size:.85rem;line-height:1.6;color:var(--text);margin-bottom:1rem">${esc(r.prompt_imagem)}</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary btn-sm" onclick="gerarImagemDoBriefing('${r.prompt_imagem?.replace(/'/g,"\\'")||''}')">🎨 Gerar imagem agora</button>
        <button class="btn btn-ghost btn-sm" onclick="copiar('${r.prompt_imagem?.replace(/'/g,"\\'")||''}',this)">📋 Copiar prompt</button>
      </div>
    </div>`:''}`;
}

function gerarImagemDoBriefing(prompt){
  aba('imagem', document.querySelectorAll('.tab-btn')[2]);
  document.getElementById('img_prompt').value=prompt;
  gerarImagem();
}

function usarPromptBriefing(prompt){
  aba('briefing', document.querySelectorAll('.tab-btn')[3]);
  document.getElementById('bf_objetivo').value=prompt;
}

// ── UTILS ─────────────────────────────────────────────────
function copiar(texto, btn){
  navigator.clipboard.writeText(texto).then(()=>{
    toast('Copiado ✓');
    if(btn){ const orig=btn.textContent; btn.textContent='✓ Copiado'; setTimeout(()=>btn.textContent=orig,1500); }
  });
}
function toast(msg,tipo='success'){
  const c=document.getElementById('toastContainer');const t=document.createElement('div');
  t.className='toast';if(tipo==='danger')t.style.borderLeftColor='var(--danger)';
  t.textContent=msg;c.appendChild(t);setTimeout(()=>t.remove(),3200);
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
</body>
</html>
