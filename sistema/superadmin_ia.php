<?php
/**
 * superadmin_ia.php
 * Aba Inteligência IA para o superadmin
 * Estrutura flat — todos na raiz de /sistema/
 *
 * INTEGRAÇÃO NO superadmin.php:
 * 1. Localize onde as abas são definidas e adicione:
 *    ['key'=>'ia', 'label'=>'🧠 IA']
 *
 * 2. No switch/bloco que renderiza o conteúdo de cada aba:
 *    case 'ia': include 'superadmin_ia.php'; break;
 */
?>

<div style="padding:1.5rem">

  <!-- Título -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem">
    <div>
      <h2 style="font-size:1.3rem;font-weight:800;margin:0">🧠 Inteligência de Mercado IA</h2>
      <p style="color:#888;font-size:.85rem;margin:.25rem 0 0">Limites por plano, créditos avulsos e uso mensal</p>
    </div>
    <button onclick="iaCarregarRelatorio()" style="background:#00e04f;color:#000;border:none;border-radius:8px;padding:.6rem 1.2rem;font-weight:700;cursor:pointer;font-size:.85rem">
      🔄 Atualizar
    </button>
  </div>

  <!-- Config de planos -->
  <div style="background:var(--card-bg,#1c1c1c);border:1px solid var(--border,#2a2a2a);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem">
    <h3 style="font-size:1rem;font-weight:700;margin:0 0 1.25rem">⚙️ Análises por plano / mês</h3>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.88rem" id="iaConfigTable">
        <thead>
          <tr style="border-bottom:1px solid var(--border,#2a2a2a)">
            <th style="text-align:left;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Plano</th>
            <th style="text-align:left;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Análises / mês inclusas</th>
            <th style="text-align:left;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Preço crédito avulso (R$)</th>
          </tr>
        </thead>
        <tbody id="iaConfigBody">
          <tr><td colspan="3" style="padding:1.5rem;text-align:center;color:#888">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
    <div style="margin-top:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <button onclick="iaSalvarConfig()" style="background:#00e04f;color:#000;border:none;border-radius:8px;padding:.65rem 1.4rem;font-weight:700;cursor:pointer;font-size:.88rem">
        💾 Salvar configurações
      </button>
      <span id="iaConfigMsg" style="font-size:.85rem;display:none"></span>
    </div>
  </div>

  <!-- Relatório de uso -->
  <div style="background:var(--card-bg,#1c1c1c);border:1px solid var(--border,#2a2a2a);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem">
    <h3 style="font-size:1rem;font-weight:700;margin:0 0 1.25rem">📊 Uso no mês atual</h3>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.88rem">
        <thead>
          <tr style="border-bottom:1px solid var(--border,#2a2a2a)">
            <th style="text-align:left;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Agência</th>
            <th style="text-align:left;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Plano</th>
            <th style="text-align:center;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Mensais</th>
            <th style="text-align:center;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Créditos usados</th>
            <th style="text-align:center;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Total</th>
            <th style="text-align:center;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Saldo créditos</th>
            <th style="text-align:center;padding:.6rem .75rem;color:#888;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Ação</th>
          </tr>
        </thead>
        <tbody id="iaRelatorioBody">
          <tr><td colspan="7" style="padding:1.5rem;text-align:center;color:#888">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Adicionar créditos -->
  <div style="background:var(--card-bg,#1c1c1c);border:1px solid var(--border,#2a2a2a);border-radius:12px;padding:1.5rem">
    <h3 style="font-size:1rem;font-weight:700;margin:0 0 1.25rem">➕ Adicionar créditos para agência</h3>
    <div style="display:grid;grid-template-columns:1fr 160px auto;gap:1rem;align-items:end;flex-wrap:wrap">
      <div>
        <label style="font-size:.75rem;font-weight:600;color:#888;display:block;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em">Agência</label>
        <select id="iaCreditoAgencia" style="width:100%;background:var(--input-bg,#111);border:1px solid var(--border,#2a2a2a);border-radius:8px;padding:.6rem .85rem;color:#f0f0f0;font-size:.88rem">
          <option value="">Selecione...</option>
        </select>
      </div>
      <div>
        <label style="font-size:.75rem;font-weight:600;color:#888;display:block;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em">Créditos</label>
        <input type="number" id="iaCreditoQtd" min="1" max="999" value="10"
          style="width:100%;background:var(--input-bg,#111);border:1px solid var(--border,#2a2a2a);border-radius:8px;padding:.6rem .85rem;color:#f0f0f0;font-size:.88rem">
      </div>
      <div>
        <button onclick="iaAdicionarCreditos()" style="background:#00e04f;color:#000;border:none;border-radius:8px;padding:.65rem 1.2rem;font-weight:700;cursor:pointer;font-size:.88rem;white-space:nowrap">
          ✚ Adicionar
        </button>
      </div>
    </div>
    <div style="margin-top:.75rem">
      <label style="font-size:.75rem;font-weight:600;color:#888;display:block;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em">Observação (opcional)</label>
      <input type="text" id="iaCreditoObs" placeholder="Ex: Pagamento PIX em 24/03/2026"
        style="width:100%;max-width:420px;background:var(--input-bg,#111);border:1px solid var(--border,#2a2a2a);border-radius:8px;padding:.6rem .85rem;color:#f0f0f0;font-size:.88rem">
    </div>
    <div id="iaCreditoMsg" style="margin-top:.75rem;font-size:.85rem;display:none"></div>
  </div>

</div>

<script>
// Inicializar ao carregar a aba
iaCarregarTudo();

function iaCarregarTudo() {
  iaCarregarRelatorio();
  iaCarregarAgenciasSelect();
}

// ── Relatório + config ─────────────────────────────────────────
function iaCarregarRelatorio() {
  fetch('ia_analises.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'relatorio' })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    iaRenderConfig(data.config_planos || []);
    iaRenderRelatorio(data.dados || []);
  })
  .catch(function() {
    document.getElementById('iaRelatorioBody').innerHTML =
      '<tr><td colspan="7" style="padding:1.5rem;text-align:center;color:#ef4444">Erro ao carregar. Verifique se ia_analises.php está na pasta sistema/.</td></tr>';
  });
}

function iaRenderConfig(planos) {
  var defaults = [
    { plano:'start',   label:'Start',   analises_mes:0,  credito_preco:4.90 },
    { plano:'pro',     label:'Pro ⭐',  analises_mes:5,  credito_preco:4.90 },
    { plano:'agencia', label:'Agência', analises_mes:20, credito_preco:3.90 },
  ];
  // Merge com dados do banco
  defaults.forEach(function(d) {
    var found = planos.find(function(p) { return p.plano === d.plano; });
    if (found) { d.analises_mes = found.analises_mes; d.credito_preco = found.credito_preco; }
  });

  var inp = 'background:var(--input-bg,#111);border:1px solid var(--border,#2a2a2a);border-radius:6px;padding:.4rem .6rem;color:#f0f0f0;font-size:.88rem;width:90px';
  document.getElementById('iaConfigBody').innerHTML = defaults.map(function(p) {
    return '<tr style="border-bottom:1px solid var(--border,#2a2a2a)">' +
      '<td style="padding:.75rem"><strong>' + p.label + '</strong></td>' +
      '<td style="padding:.75rem"><input type="number" class="ia-an-input" data-plano="' + p.plano + '" min="0" max="999" value="' + p.analises_mes + '" style="' + inp + '"></td>' +
      '<td style="padding:.75rem"><span style="margin-right:.3rem;color:#888">R$</span><input type="number" class="ia-pr-input" data-plano="' + p.plano + '" min="0.90" max="999" step="0.10" value="' + parseFloat(p.credito_preco).toFixed(2) + '" style="' + inp + '"></td>' +
      '</tr>';
  }).join('');
}

function iaSalvarConfig() {
  var planos = [];
  document.querySelectorAll('.ia-an-input').forEach(function(el) {
    var pr = document.querySelector('.ia-pr-input[data-plano="' + el.dataset.plano + '"]');
    planos.push({ plano: el.dataset.plano, analises_mes: parseInt(el.value)||0, credito_preco: parseFloat(pr ? pr.value : 4.90)||4.90 });
  });

  fetch('ia_analises.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'salvar_config', planos: planos })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    var msg = document.getElementById('iaConfigMsg');
    msg.textContent = data.ok ? '✓ Salvo com sucesso!' : ('✗ ' + (data.erro || 'Erro'));
    msg.style.color = data.ok ? '#22c55e' : '#ef4444';
    msg.style.display = 'inline';
    setTimeout(function() { msg.style.display = 'none'; }, 3000);
  });
}

function iaRenderRelatorio(dados) {
  var tbody = document.getElementById('iaRelatorioBody');
  if (!dados.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="padding:1.5rem;text-align:center;color:#888">Nenhum uso registrado este mês.</td></tr>';
    return;
  }
  tbody.innerHTML = dados.map(function(r) {
    var saldo = parseInt(r.creditos_saldo) || 0;
    var badges = { agencia:'#00e04f', pro:'#4ac3f8', start:'#888' };
    var cor = badges[r.plano] || '#888';
    return '<tr style="border-bottom:1px solid var(--border,#2a2a2a)">' +
      '<td style="padding:.75rem"><strong>' + esc(r.agencia) + '</strong></td>' +
      '<td style="padding:.75rem"><span style="background:' + cor + '22;color:' + cor + ';border:1px solid ' + cor + ';border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:700">' + r.plano + '</span></td>' +
      '<td style="padding:.75rem;text-align:center">' + r.uso_mensal + '</td>' +
      '<td style="padding:.75rem;text-align:center">' + r.uso_credito + '</td>' +
      '<td style="padding:.75rem;text-align:center"><strong>' + r.total + '</strong></td>' +
      '<td style="padding:.75rem;text-align:center;color:' + (saldo>0?'#22c55e':'#888') + ';font-weight:700">' + saldo + '</td>' +
      '<td style="padding:.75rem;text-align:center"><button onclick="iaSetAgencia(' + r.agencia_id + ')" style="background:none;border:1px solid var(--border,#2a2a2a);border-radius:6px;color:#f0f0f0;padding:.3rem .7rem;cursor:pointer;font-size:.78rem">+ Créditos</button></td>' +
      '</tr>';
  }).join('');
}

// ── Agências no select ─────────────────────────────────────────
function iaCarregarAgenciasSelect() {
  fetch('superadmin.php?ajax=listar_agencias')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var sel = document.getElementById('iaCreditoAgencia');
      (data.agencias || data.data || []).forEach(function(a) {
        var opt = document.createElement('option');
        opt.value = a.id;
        opt.textContent = a.nome + ' (' + (a.plano||'') + ')';
        sel.appendChild(opt);
      });
    })
    .catch(function() {});
}

function iaSetAgencia(id) {
  document.getElementById('iaCreditoAgencia').value = id;
  document.getElementById('iaCreditoQtd').focus();
  document.getElementById('iaCreditoAgencia').scrollIntoView({ behavior:'smooth', block:'center' });
}

// ── Adicionar créditos ─────────────────────────────────────────
function iaAdicionarCreditos() {
  var ag  = parseInt(document.getElementById('iaCreditoAgencia').value);
  var qtd = parseInt(document.getElementById('iaCreditoQtd').value);
  var obs = document.getElementById('iaCreditoObs').value.trim();
  var msg = document.getElementById('iaCreditoMsg');

  if (!ag || !qtd || qtd < 1) {
    msg.textContent = '⚠️ Selecione uma agência e informe a quantidade.';
    msg.style.color = '#f59e0b';
    msg.style.display = 'block';
    return;
  }

  fetch('ia_analises.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action:'adicionar_creditos', agencia_id:ag, quantidade:qtd, observacao:obs })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (data.ok) {
      msg.textContent = '✓ ' + qtd + ' crédito(s) adicionado(s). Saldo: ' + data.saldo_novo;
      msg.style.color = '#22c55e';
      document.getElementById('iaCreditoQtd').value = '10';
      document.getElementById('iaCreditoObs').value  = '';
      iaCarregarRelatorio();
    } else {
      msg.textContent = '✗ ' + (data.erro || 'Erro ao adicionar.');
      msg.style.color = '#ef4444';
    }
    msg.style.display = 'block';
    setTimeout(function() { msg.style.display='none'; }, 4000);
  });
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
