/**
 * Opa Studio — Theme + Tour System
 * Incluir em todas as páginas principais via <script src="/theme.js">
 */

// ============================================================
// TEMA CLARO / ESCURO
// ============================================================
const OpaTheme = (() => {
  const KEY = 'opa_tema';

  function get() {
    return localStorage.getItem(KEY) || document.documentElement.dataset.tema || 'dark';
  }

  function apply(tema) {
    document.documentElement.setAttribute('data-tema', tema);
    localStorage.setItem(KEY, tema);
    // Sincroniza toggle se existir
    const toggle = document.getElementById('themeToggle');
    if (toggle) toggle.checked = tema === 'light';
    // Salva no servidor (best-effort)
    fetch('api.php?action=salvar_tema', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tema })
    }).catch(() => {});
  }

  function toggle() {
    apply(get() === 'dark' ? 'light' : 'dark');
  }

  // Aplica na carga
  function init() {
    apply(get());
  }

  return { get, apply, toggle, init };
})();

// Aplica imediatamente (antes do render)
OpaTheme.init();

// ============================================================
// TOUR INTERATIVO
// ============================================================
const OpaTour = (() => {
  let steps   = [];
  let current = 0;
  let overlay, box, backdrop;

  const KEY_DONE    = 'opa_tour_done';
  const KEY_DISABLED= 'opa_tour_disabled';

  function isDone()     { return localStorage.getItem(KEY_DONE) === '1'; }
  function isDisabled() { return localStorage.getItem(KEY_DISABLED) === '1'; }
  function markDone()   { localStorage.setItem(KEY_DONE, '1'); }

  function enable()  { localStorage.removeItem(KEY_DISABLED); localStorage.removeItem(KEY_DONE); }
  function disable() { localStorage.setItem(KEY_DISABLED, '1'); cleanup(); }

  function init(tourSteps) {
    steps = tourSteps;
    if (isDisabled() || isDone() || !steps.length) return;
    // Pequeno delay para DOM estar pronto
    setTimeout(start, 600);
  }

  function start() {
    current = 0;
    createOverlay();
    showStep(0);
  }

  function createOverlay() {
    // Backdrop escurecido com buraco no elemento destacado
    backdrop = document.createElement('div');
    backdrop.id = 'tourBackdrop';
    backdrop.style.cssText = `
      position:fixed;inset:0;z-index:9000;pointer-events:none;
      transition:all .3s ease;
    `;
    document.body.appendChild(backdrop);

    // Caixa de texto do tour
    box = document.createElement('div');
    box.id = 'tourBox';
    box.style.cssText = `
      position:fixed;z-index:9001;
      background:var(--surface,#141414);border:1px solid var(--accent,#78be20);
      border-radius:14px;padding:1.5rem;max-width:340px;width:90%;
      box-shadow:0 20px 60px rgba(0,0,0,.5);
      animation:tourIn .25s ease;
    `;
    box.innerHTML = buildBoxHTML();
    document.body.appendChild(box);

    // Injetar CSS do tour
    if (!document.getElementById('tourCSS')) {
      const style = document.createElement('style');
      style.id = 'tourCSS';
      style.textContent = `
        @keyframes tourIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        .tour-highlight {
          position:relative;z-index:8999;
          box-shadow:0 0 0 4px var(--accent,#78be20), 0 0 0 9999px rgba(0,0,0,.65) !important;
          border-radius:8px;pointer-events:none;
          transition:box-shadow .3s ease;
        }
        #tourBox { font-family:'DM Sans',sans-serif; }
        #tourBox .tour-title { font-family:'Bebas Neue',sans-serif;font-size:1.2rem;letter-spacing:1.5px;color:var(--accent,#78be20);margin-bottom:.5rem }
        #tourBox .tour-text { font-size:.875rem;line-height:1.6;color:var(--text,#e8eaf0);margin-bottom:1.25rem }
        #tourBox .tour-footer { display:flex;align-items:center;justify-content:space-between;gap:8px }
        #tourBox .tour-counter { font-family:'JetBrains Mono',monospace;font-size:.72rem;color:var(--muted,#585858) }
        #tourBox .tour-btn { padding:7px 18px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s }
        #tourBox .tour-btn-next { background:var(--accent,#78be20);color:#0d0d0d }
        #tourBox .tour-btn-next:hover { filter:brightness(1.1) }
        #tourBox .tour-btn-skip { background:transparent;color:var(--muted,#585858);border:1px solid var(--border,#2a2a2a) }
        #tourBox .tour-btn-skip:hover { color:var(--text,#e8eaf0) }
        #tourBox .tour-progress { display:flex;gap:4px;margin-bottom:1rem }
        #tourBox .tour-dot { width:6px;height:6px;border-radius:50%;background:var(--border,#2a2a2a);transition:background .2s }
        #tourBox .tour-dot.active { background:var(--accent,#78be20) }
      `;
      document.head.appendChild(style);
    }
  }

  function buildBoxHTML() {
    const step = steps[current];
    const isLast = current === steps.length - 1;
    const dots = steps.map((_, i) =>
      `<div class="tour-dot${i === current ? ' active' : ''}"></div>`
    ).join('');
    return `
      <div class="tour-progress">${dots}</div>
      <div class="tour-title">${step.title}</div>
      <div class="tour-text">${step.text}</div>
      <div class="tour-footer">
        <span class="tour-counter">${current + 1} / ${steps.length}</span>
        <div style="display:flex;gap:8px">
          <button class="tour-btn tour-btn-skip" onclick="OpaTour.pular()">
            ${isLast ? '' : 'Pular'}
          </button>
          <button class="tour-btn tour-btn-next" onclick="OpaTour.proximo()">
            ${isLast ? '✓ Concluir' : 'Próximo →'}
          </button>
        </div>
      </div>
    `;
  }

  function showStep(index) {
    current = index;
    const step = steps[current];

    // Remove highlight anterior
    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));

    // Atualiza conteúdo da caixa
    if (box) {
      box.innerHTML = buildBoxHTML();
      box.style.animation = 'none';
      setTimeout(() => { if(box) box.style.animation = 'tourIn .25s ease'; }, 10);
      // Reseta transform para não interferir no cálculo
      box.style.transform = '';
    }

    // Destaca e posiciona
    let targetEl = null;
    if (step.target) {
      targetEl = document.querySelector(step.target);
    }

    if (targetEl) {
      targetEl.classList.add('tour-highlight');
      // Scroll suave para o elemento
      targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

      // Aguarda scroll antes de posicionar
      setTimeout(() => {
        if (!box) return;
        const rect   = targetEl.getBoundingClientRect();
        const boxW   = Math.min(340, window.innerWidth - 24);
        const boxH   = box.offsetHeight || 200;
        const margin = 14;

        // Calcula top: prefere abaixo, senão acima
        let top = rect.bottom + margin;
        if (top + boxH > window.innerHeight - 12) {
          top = rect.top - boxH - margin;
        }
        top = Math.max(12, Math.min(top, window.innerHeight - boxH - 12));

        // Calcula left: centraliza no elemento, sem sair da tela
        let left = rect.left + rect.width / 2 - boxW / 2;
        left = Math.max(12, Math.min(left, window.innerWidth - boxW - 12));

        box.style.top    = top  + 'px';
        box.style.left   = left + 'px';
        box.style.bottom = 'auto';
        box.style.right  = 'auto';
        box.style.width  = boxW + 'px';
      }, 350);

    } else {
      // Sem target → centraliza na tela
      if (box) {
        box.style.top       = '50%';
        box.style.left      = '50%';
        box.style.transform = 'translate(-50%,-50%)';
        box.style.width     = '340px';
      }
    }
  }

  function proximo() {
    if (current >= steps.length - 1) {
      concluir();
    } else {
      showStep(current + 1);
    }
  }

  function pular() {
    if (current >= steps.length - 1) {
      concluir();
    } else {
      concluir();
    }
  }

  function concluir() {
    markDone();
    cleanup();
    // Salva no servidor
    fetch('api.php?action=tour_concluido', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }
    }).catch(() => {});
  }

  function cleanup() {
    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
    if (box)      { box.remove();      box = null; }
    if (backdrop) { backdrop.remove(); backdrop = null; }
  }

  function reiniciar() {
    localStorage.removeItem(KEY_DONE);
    localStorage.removeItem(KEY_DISABLED);
    cleanup();
  }

  return { init, start, proximo, pular, concluir, cleanup, reiniciar, enable, disable, isDone, isDisabled };
})();
