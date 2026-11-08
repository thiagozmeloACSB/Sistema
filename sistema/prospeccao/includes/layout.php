<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// Layout da página exclusiva (cabeçalho no padrão do superadmin: logo, tema, abas).
// Tema claro/escuro: mesma chave do superadmin (localStorage opa_theme), então a escolha vale nas duas telas.

function proNavItens(): array {
    return ['painel' => '📊 Painel', 'campanhas' => '🎯 Campanhas', 'prospeccao' => '🔎 Prospecção', 'vendedor_ia' => '🤖 Vendedor IA', 'consumo' => '💸 Consumo IA', 'config' => '⚙️ Configurações'];
}

function proHeader(string $titulo, string $pagina): void {
    $f = flashTake();
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($titulo) ?> — Prospecção · Opa Studio</title>
<meta name="theme-color" content="#0d0d0d">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=4">
<script>
(function(){var l=false;try{var s=localStorage.getItem('opa_theme');l=s?s==='light':window.matchMedia('(prefers-color-scheme:light)').matches;}catch(e){}
document.documentElement.classList.toggle('light',l);window.__proLight=l;})();
</script>
</head>
<body>
<header class="topo">
  <div class="topo-in">
    <button type="button" id="menuBtn" class="menu-btn" aria-label="Abrir menu" aria-expanded="false">☰</button>
    <a class="marca" href="<?= e(proUrl('painel')) ?>">
      <img id="navLogo" src="../logo_studio_dark.png" alt="Opa Studio"><span class="badge">PROSPECÇÃO</span>
    </a>
    <div class="topo-acoes">
      <button type="button" id="themeToggle" class="btn btn-sm" title="Alternar tema" style="font-size:1.05rem;line-height:1">🌙</button>
      <a href="../superadmin.php" class="btn btn-sm">← Superadmin</a>
      <a href="../logout.php" class="btn btn-sm">Sair</a>
    </div>
  </div>
</header>
<div class="menu-fundo" id="menuFundo"></div>
<nav class="abas" id="menuLateral">
  <?php foreach (proNavItens() as $k => $rot): ?><a href="<?= e(proUrl($k)) ?>" class="<?= $pagina === $k ? 'on' : '' ?>"><?= $rot ?></a><?php endforeach; ?>
</nav>
<main class="pagina">
<h1 class="titulo"><?= e($titulo) ?></h1>
<?php if ($f): ?><div class="flash <?= e($f[1]) ?>"><?= e($f[0]) ?></div><?php endif; ?>
<script>
(function(){
  function aplicar(l){document.documentElement.classList.toggle('light',l);
    var i=document.getElementById('navLogo');if(i)i.src=l?'../logo_studio_light.png':'../logo_studio_dark.png';
    var b=document.getElementById('themeToggle');if(b)b.textContent=l?'☀️':'🌙';}
  aplicar(!!window.__proLight);
  var mb=document.getElementById('menuBtn'),mf=document.getElementById('menuFundo'),ml=document.getElementById('menuLateral');
  function abrir(v){document.body.classList.toggle('menu-aberto',v);mb.setAttribute('aria-expanded',v?'true':'false');}
  mb.onclick=function(){abrir(!document.body.classList.contains('menu-aberto'));};
  mf.onclick=function(){abrir(false);};
  ml.onclick=function(e){if(e.target.closest('a'))abrir(false);};
  document.addEventListener('keydown',function(e){if(e.key==='Escape')abrir(false);});
  window.addEventListener('resize',function(){if(window.innerWidth>=900)abrir(false);});
  document.getElementById('themeToggle').onclick=function(){
    var l=!document.documentElement.classList.contains('light');
    try{localStorage.setItem('opa_theme',l?'light':'dark');}catch(e){}
    aplicar(l);};
})();
</script>
<?php
}

function proFooter(): void {
    echo "\n</main>\n</body>\n</html>\n";
}
