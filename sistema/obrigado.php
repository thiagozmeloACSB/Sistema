<?php
$pay = $_GET['pay'] ?? '';
?>
<!DOCTYPE html><html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Conta criada! — Opa Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--border:#2a2a2a;--accent:#78be20;--text:#e8eaf0;--muted:#585858;--success:#4ade80}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:3rem;max-width:500px;width:100%;text-align:center;animation:fadeUp .4s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.icon{font-size:3.5rem;margin-bottom:1.5rem}
.title{font-family:'Bebas Neue',sans-serif;font-size:2.2rem;letter-spacing:2px;color:var(--accent);margin-bottom:.75rem}
.sub{color:var(--muted);font-size:.95rem;line-height:1.7;margin-bottom:2rem}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--accent);color:#0d0d0d}.btn-primary:hover{background:#d4e83e}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border);margin-top:.75rem}
.btn-ghost:hover{background:#1e1e1e}
.actions{display:flex;flex-direction:column;align-items:center;gap:8px}
.trial-info{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);color:var(--success);border-radius:10px;padding:12px 18px;font-size:.82rem;margin-bottom:1.75rem;font-family:'JetBrains Mono',monospace}
@media(max-width:480px){body{padding:1rem!important}.card{padding:1.75rem 1.25rem!important;border-radius:16px}h1{font-size:1.5rem}}
</style>
</head>
<body>

<?php if (!empty($_GET['confirmar'])): ?>
<div style="max-width:520px;margin:0 auto 1.5rem;background:rgba(120,190,32,.08);
  border:1px solid rgba(120,190,32,.25);border-radius:14px;padding:1.25rem 1.5rem;
  font-family:'JetBrains Mono',monospace;font-size:.82rem;color:#78be20;text-align:center;
  position:relative;z-index:1;animation:fadeUp .4s ease">
  📧 <strong>Verifique seu e-mail!</strong><br>
  <span style="color:#8a9bb0;font-size:.78rem">Enviamos um link de confirmação. Clique nele para ativar sua conta.</span>
</div>
<?php endif ?>
<div class="card">
  <div class="icon">🎉</div>
  <div class="title">Conta criada!</div>
  <div class="trial-info">✓ Seu trial de 7 dias começa agora</div>
  <div class="sub">
    <?php if($pay): ?>
    Para garantir a continuidade após o trial, finalize o pagamento no link abaixo.<br>
    <strong>Enquanto isso, já pode usar o sistema!</strong>
    <?php else: ?>
    Você já pode acessar o sistema. Seu trial de 7 dias está ativo.
    <?php endif ?>
  </div>
  <div class="actions">
    <a href="login.php" class="btn btn-primary">Acessar o sistema →</a>
    <?php if($pay): ?>
    <a href="<?=htmlspecialchars($pay)?>" target="_blank" class="btn btn-ghost">💳 Finalizar pagamento</a>
    <?php endif ?>
  </div>
</div>
</body></html>
