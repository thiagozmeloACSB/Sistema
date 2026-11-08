<?php
require_once __DIR__.'/auth.php';
$u = usuarioLogado();
$t = tenantAtual();
?>
<!DOCTYPE html><html lang="pt-BR">
<head>
<meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="UTF-8"><title>Conta Suspensa — Opa Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--border:#2a2a2a;--accent:#78be20;--text:#e8eaf0;--muted:#585858;--danger:#f87171}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.card{background:var(--surface);border:1px solid #f8717144;border-radius:20px;padding:3rem;max-width:480px;width:100%;text-align:center}
.icon{font-size:3rem;margin-bottom:1.5rem}
.title{font-family:'Bebas Neue',sans-serif;font-size:2rem;letter-spacing:2px;color:var(--danger);margin-bottom:.75rem}
.sub{color:var(--muted);font-size:.9rem;line-height:1.7;margin-bottom:2rem}
.btn{display:inline-flex;align-items:center;padding:11px 24px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;border:none;text-decoration:none;background:var(--accent);color:#0d0d0d;margin:4px}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
@media(max-width:480px){body{padding:1rem!important}.card{padding:1.75rem 1.25rem!important;border-radius:16px}h1{font-size:1.5rem}}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔒</div>
  <div class="title">Conta <?= $t ? htmlspecialchars($t['status']) : 'suspensa' ?></div>
  <div class="sub">Sua conta está temporariamente suspensa por falta de pagamento.<br>
  Entre em contato ou regularize sua assinatura para reativar o acesso.</div>
  <a href="mailto:contato@printmycase.com" class="btn">Falar com suporte</a>
  <a href="logout.php" class="btn btn-ghost">Sair</a>
</div>
</body></html>
