<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

if (usuarioLogado()) { header('Location: admin.php'); exit; }

$erro    = '';
$sucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido.';
    } else {
        // Busca usuário (qualquer tipo)
        $st = db()->prepare("SELECT id, nome, email FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1");
        $st->execute([$email]);
        $u = $st->fetch();

        if ($u) {
            // Invalida tokens anteriores do mesmo e-mail
            db()->prepare("UPDATE password_resets SET usado = 1 WHERE email = ? AND usado = 0")
                ->execute([$email]);

            // Gera token seguro (64 hex chars, 1h)
            $token    = bin2hex(random_bytes(32));
            $expira   = date('Y-m-d H:i:s', strtotime('+1 hour'));

            db()->prepare("INSERT INTO password_resets (email, token, expira_em) VALUES (?, ?, ?)")
                ->execute([$email, $token, $expira]);

            // Envia e-mail (best-effort — não revela se e-mail existe)
            try {
                mailer_reset_senha($u['email'], $u['nome'], $token);
            } catch (Exception $e) { /* silencia */ }
        }

        // Sempre mostra sucesso (evita enumeração de e-mails)
        $sucesso = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Esqueci minha senha — Opa Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;--accent:#78be20;--text:#e8eaf0;--muted:#585858;--danger:#f87171;--success:#4ade80}
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
.card-title{font-family:'Bebas Neue',sans-serif;font-size:1.6rem;letter-spacing:2px;
  color:var(--accent);margin-bottom:.35rem;text-align:center}
.card-sub{font-size:.82rem;color:var(--muted);margin-bottom:2rem;text-align:center;
  font-family:'JetBrains Mono',monospace;line-height:1.6}
.form-group{margin-bottom:1.2rem}
label{display:block;font-size:.7rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;
  font-family:'JetBrains Mono',monospace;margin-bottom:8px}
input{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);
  padding:12px 16px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.95rem;
  outline:none;transition:border-color .15s}
input:focus{border-color:var(--accent)}
input::placeholder{color:var(--muted)}
.btn{width:100%;background:var(--accent);color:#0d0d0d;border:none;padding:13px;
  border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;
  cursor:pointer;transition:all .15s}
.btn:hover{background:#5a9a18;transform:translateY(-1px)}
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
    <img id="logoImg" src="" alt="Opa Studio"
         onerror="this.style.display='none'">
  </div>

  <?php if ($sucesso): ?>

    <div class="sucesso-box">
      <div class="icon">📧</div>
      <p><strong>Verifique seu e-mail!</strong><br><br>
      Se este e-mail estiver cadastrado, você receberá um link para redefinir sua senha em instantes.<br><br>
      <span style="font-size:.8rem;font-family:'JetBrains Mono',monospace">Lembre-se de verificar a pasta de spam.</span>
      </p>
    </div>

    <div class="links">
      <a href="login.php">← Voltar para o login</a>
    </div>

  <?php else: ?>

    <div class="card-title">Esqueci minha senha</div>
    <div class="card-sub">Informe seu e-mail e enviaremos um link para redefinir sua senha.</div>

    <?php if ($erro): ?>
      <div class="erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif ?>

    <form method="POST">
      <div class="form-group">
        <label>E-mail cadastrado</label>
        <input type="email" name="email" placeholder="seu@email.com" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autofocus>
      </div>
      <button type="submit" class="btn">Enviar link de redefinição →</button>
    </form>

    <div class="links">
      Lembrou a senha? <a href="login.php">Entrar</a>
    </div>

  <?php endif ?>

</div>

<script>
// Logo base64
(function(){
<?php
require_once __DIR__ . '/mailer.php';
// Injeta logo dark como base64
$logo_path = __DIR__ . '/logo_studio_dark.png';
if (file_exists($logo_path)) {
    $b64 = base64_encode(file_get_contents($logo_path));
    echo "  document.getElementById('logoImg').src = 'data:image/png;base64,{$b64}';";
}
?>
})();
</script>
</body>
</html>
