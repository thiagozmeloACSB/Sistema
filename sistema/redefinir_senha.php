<?php
require_once __DIR__ . '/auth.php';

if (usuarioLogado()) { header('Location: admin.php'); exit; }

$token  = trim($_GET['token'] ?? '');
$erro   = '';
$ok     = false;

// Valida token logo na entrada
$reset  = null;
if ($token) {
    $st = db()->prepare("
        SELECT r.*, u.id AS uid, u.nome, u.email
        FROM password_resets r
        JOIN usuarios u ON u.email = r.email
        WHERE r.token = ?
          AND r.usado = 0
          AND r.expira_em > NOW()
        LIMIT 1
    ");
    $st->execute([$token]);
    $reset = $st->fetch();
}

if (!$reset && !$_POST) {
    // Token inválido ou expirado — exibe erro imediatamente
    $erro = 'Link inválido ou expirado. Solicite um novo link abaixo.';
}

// Processa nova senha
$senha_requisitos = ['minLength'=>8,'upper'=>true,'number'=>true,'special'=>true];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $senha  = $_POST['senha']  ?? '';
    $senha2 = $_POST['senha2'] ?? '';

    if (strlen($senha) < 8)                        $erro = 'A senha deve ter no mínimo 8 caracteres.';
    elseif (!preg_match('/[A-Z]/', $senha))         $erro = 'A senha deve conter ao menos uma letra maiúscula.';
    elseif (!preg_match('/[0-9]/', $senha))         $erro = 'A senha deve conter ao menos um número.';
    elseif (!preg_match('/[^a-zA-Z0-9]/', $senha))  $erro = 'A senha deve conter ao menos um caractere especial.';
    elseif ($senha !== $senha2)                     $erro = 'As senhas não conferem.';
    else {
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        db()->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?")
            ->execute([$hash, $reset['uid']]);
        db()->prepare("UPDATE password_resets SET usado = 1 WHERE token = ?")
            ->execute([$token]);
        $ok = true;

        // Garante email verificado (evita bloqueio no login automático)
        db()->prepare("UPDATE usuarios SET email_verificado=1 WHERE id=?")->execute([$reset['uid']]);

        // Login automático (session já iniciada pelo auth.php)
        login($reset['email'], $senha);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redefinir senha — Opa Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;--accent:#78be20;--accent-dark:#5a9a18;
  --text:#e8eaf0;--muted:#585858;--danger:#f87171;--success:#4ade80;--warning:#fbbf24}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;
  display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1rem}
body::before{content:'';position:fixed;inset:0;
  background:radial-gradient(ellipse 60% 50% at 50% 0%,rgba(120,190,32,.06),transparent 70%);pointer-events:none}
.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:2.5rem;
  width:100%;max-width:420px;position:relative;z-index:1;animation:fadeUp .4s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.logo{text-align:center;margin-bottom:2rem}
.logo img{height:80px;width:auto;object-fit:contain}
.card-title{font-family:'Bebas Neue',sans-serif;font-size:1.6rem;letter-spacing:2px;color:var(--accent);margin-bottom:.35rem;text-align:center}
.card-sub{font-size:.82rem;color:var(--muted);margin-bottom:2rem;text-align:center;font-family:'JetBrains Mono',monospace}
.form-group{margin-bottom:1rem;position:relative}
label{display:block;font-size:.7rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:8px}
.pass-wrap{position:relative}
.pass-wrap input{padding-right:44px}
input{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);
  padding:12px 16px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.95rem;outline:none;transition:border-color .15s}
input:focus{border-color:var(--accent)}
input::placeholder{color:var(--muted)}
.pass-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;color:var(--muted);padding:4px;transition:color .15s}
.pass-toggle:hover{color:var(--text)}
/* Barra de força */
.strength-bar{display:flex;gap:4px;margin-top:6px;height:3px}
.strength-seg{flex:1;border-radius:2px;background:var(--border);transition:background .25s}
.strength-label{font-family:'JetBrains Mono',monospace;font-size:.65rem;margin-top:5px;color:var(--muted);min-height:14px}
.req-list{margin-top:6px;display:grid;grid-template-columns:1fr 1fr;gap:3px 8px}
.req{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--muted);display:flex;align-items:center;gap:4px;transition:color .2s}
.req.ok{color:var(--success)}.req.ok::before{content:'✓'}.req:not(.ok)::before{content:'○'}
.match-label{font-family:'JetBrains Mono',monospace;font-size:.65rem;min-height:14px;margin-top:5px}
.btn{width:100%;background:var(--accent);color:#0d0d0d;border:none;padding:13px;
  border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;
  cursor:pointer;margin-top:.5rem;transition:all .15s}
.btn:hover{background:var(--accent-dark);transform:translateY(-1px)}
.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
.erro{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:var(--danger);
  padding:10px 14px;border-radius:8px;font-size:.85rem;margin-bottom:1.25rem;text-align:center}
.sucesso-box{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);color:var(--success);
  padding:1.5rem;border-radius:10px;text-align:center}
.sucesso-box .icon{font-size:2.5rem;margin-bottom:.75rem}
.sucesso-box p{font-size:.9rem;line-height:1.7;color:var(--muted)}
.sucesso-box strong{color:var(--text)}
.links{text-align:center;margin-top:1.5rem;font-size:.82rem;color:var(--muted)}
.links a{color:var(--accent);text-decoration:none}
.links a:hover{text-decoration:underline}
@media(max-width:480px){body{padding:1rem!important}.card{padding:1.75rem 1.25rem!important;border-radius:16px}h1{font-size:1.5rem}}
</style>
</head>
<body>
<div class="card">

  <div class="logo">
    <img id="logoImg" src="" alt="Opa Studio" onerror="this.style.display='none'">
  </div>

  <?php if ($ok): ?>

    <div class="sucesso-box">
      <div class="icon">✅</div>
      <p><strong>Senha redefinida!</strong><br><br>
      Sua nova senha foi salva. Você já está logado e será redirecionado ao painel.</p>
    </div>
    <script>setTimeout(()=>window.location.href='admin.php', 2500);</script>

  <?php elseif (!$reset): ?>

    <div class="card-title">Link inválido</div>
    <div class="erro"><?= htmlspecialchars($erro) ?></div>
    <a href="esqueci_senha.php" class="btn" style="display:block;text-align:center;text-decoration:none">
      Solicitar novo link →
    </a>
    <div class="links"><a href="login.php">← Voltar para o login</a></div>

  <?php else: ?>

    <div class="card-title">Nova senha</div>
    <div class="card-sub">Olá, <?= htmlspecialchars($reset['nome']) ?>! Crie sua nova senha abaixo.</div>

    <?php if ($erro): ?>
      <div class="erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif ?>

    <form method="POST" action="redefinir_senha.php?token=<?= urlencode($token) ?>">

      <div class="form-group">
        <label>Nova senha</label>
        <div class="pass-wrap">
          <input type="password" id="novaSenha" name="senha" placeholder="Mínimo 8 caracteres"
                 required oninput="avaliarSenha(this.value)">
          <button type="button" class="pass-toggle" onclick="togglePass('novaSenha','ico1')" tabindex="-1">
            <svg id="ico1" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <div class="strength-bar">
          <div class="strength-seg" id="s1"></div><div class="strength-seg" id="s2"></div>
          <div class="strength-seg" id="s3"></div><div class="strength-seg" id="s4"></div>
        </div>
        <div class="strength-label" id="sLabel"></div>
        <div class="req-list">
          <div class="req" id="r-len">8+ caracteres</div>
          <div class="req" id="r-upper">Maiúscula</div>
          <div class="req" id="r-num">Número</div>
          <div class="req" id="r-spec">Especial</div>
        </div>
      </div>

      <div class="form-group">
        <label>Confirmar nova senha</label>
        <div class="pass-wrap">
          <input type="password" id="confirmarSenha" name="senha2" placeholder="Repita a senha"
                 required oninput="checarMatch()">
          <button type="button" class="pass-toggle" onclick="togglePass('confirmarSenha','ico2')" tabindex="-1">
            <svg id="ico2" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <div class="match-label" id="matchLabel"></div>
      </div>

      <button type="submit" class="btn" id="btnSalvar" disabled>Salvar nova senha →</button>
    </form>

  <?php endif ?>

</div>

<script>
(function(){
<?php
$logo_path = __DIR__ . '/logo_studio_dark.png';
if (file_exists($logo_path)) {
    $b64 = base64_encode(file_get_contents($logo_path));
    echo "  document.getElementById('logoImg').src = 'data:image/png;base64,{$b64}';";
}
?>
})();

const EYE_OPEN = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const EYE_SHUT = `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/>
<line x1="1" y1="1" x2="23" y2="23"/>`;

function togglePass(id, ico) {
  const inp = document.getElementById(id);
  const sv  = document.getElementById(ico);
  const isPass = inp.type === 'password';
  inp.type = isPass ? 'text' : 'password';
  sv.innerHTML = isPass ? EYE_SHUT : EYE_OPEN;
}

function avaliarSenha(v) {
  const reqs = {
    'r-len':   v.length >= 8,
    'r-upper': /[A-Z]/.test(v),
    'r-num':   /[0-9]/.test(v),
    'r-spec':  /[^a-zA-Z0-9]/.test(v),
  };
  const pts = Object.values(reqs).filter(Boolean).length;
  Object.entries(reqs).forEach(([id, ok]) =>
    document.getElementById(id).classList.toggle('ok', ok)
  );
  const cores  = ['','#f87171','#fbbf24','#78be20','#4ade80'];
  const labels = ['','Fraca','Média','Boa','Forte'];
  [1,2,3,4].forEach(i => {
    document.getElementById('s'+i).style.background = i <= pts ? cores[pts] : 'var(--border)';
  });
  const lbl = document.getElementById('sLabel');
  lbl.textContent = v.length ? labels[pts] : '';
  lbl.style.color = cores[pts] || 'var(--muted)';
  checarMatch();
  habilitarBotao(pts === 4);
}

function checarMatch() {
  const s1 = document.getElementById('novaSenha').value;
  const s2 = document.getElementById('confirmarSenha').value;
  const lbl = document.getElementById('matchLabel');
  if (!s2) { lbl.textContent = ''; return; }
  if (s1 === s2) { lbl.textContent = '✓ Senhas coincidem'; lbl.style.color = 'var(--success)'; }
  else           { lbl.textContent = '✗ Senhas não coincidem'; lbl.style.color = 'var(--danger)'; }
  habilitarBotao(s1 === s2 && s1.length >= 8);
}

function habilitarBotao(on) {
  const reqs = {
    'r-len':   document.getElementById('novaSenha').value.length >= 8,
    'r-upper': /[A-Z]/.test(document.getElementById('novaSenha').value),
    'r-num':   /[0-9]/.test(document.getElementById('novaSenha').value),
    'r-spec':  /[^a-zA-Z0-9]/.test(document.getElementById('novaSenha').value),
  };
  const forte = Object.values(reqs).every(Boolean);
  const s1 = document.getElementById('novaSenha').value;
  const s2 = document.getElementById('confirmarSenha').value;
  document.getElementById('btnSalvar').disabled = !(forte && s1 === s2 && s2.length > 0);
}
</script>
</body>
</html>
