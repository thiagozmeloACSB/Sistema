<?php
// Roteador do painel: index.php?p=painel|campanhas|prospeccao|vendedor_ia|consumo|config
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
ob_start();

$paginas = ['painel', 'campanhas', 'prospeccao', 'vendedor_ia', 'consumo', 'config'];
$p = (string)($_GET['p'] ?? 'painel');
if (!in_array($p, $paginas, true)) $p = 'painel';

// Chamadas AJAX (fetch com ajax=1) recebem JSON em vez de redirecionar para o login
requireLogin(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ajax']));

$db = getDB();
require __DIR__ . '/pages/' . $p . '.php';
