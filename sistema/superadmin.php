<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/asaas.php';
exigeLogin('superadmin');

/* POST ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['tenant_id'] ?? 0);

    if ($action === 'ativar')    { db()->prepare("UPDATE tenants SET status='ativo'     WHERE id=?")->execute([$tid]); echo json_encode(['ok'=>true]); exit; }
    if ($action === 'suspender') { db()->prepare("UPDATE tenants SET status='suspenso'  WHERE id=?")->execute([$tid]); echo json_encode(['ok'=>true]); exit; }
    if ($action === 'cancelar')  { db()->prepare("UPDATE tenants SET status='cancelado' WHERE id=?")->execute([$tid]); echo json_encode(['ok'=>true]); exit; }
    if ($action === 'excluir')   { db()->prepare("DELETE FROM tenants WHERE id=?")       ->execute([$tid]); echo json_encode(['ok'=>true]); exit; }

    if ($action === 'trial_extend') {
        $dias = (int)($_POST['dias'] ?? 7);
        db()->prepare("UPDATE tenants SET trial_ends_at = DATE_ADD(GREATEST(COALESCE(trial_ends_at, NOW()), NOW()), INTERVAL ? DAY), status='trial' WHERE id=?")
            ->execute([$dias, $tid]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'plano_change') {
        db()->prepare("UPDATE tenants SET plano_id=? WHERE id=?")->execute([(int)$_POST['plano_id'], $tid]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'plano_update') {
        $pid   = (int)($_POST['plano_id'] ?? 0);
        $preco = (int)(floatval($_POST['preco']) * 100);
        $nome  = trim($_POST['nome'] ?? '');
        $desc  = trim($_POST['descricao'] ?? '');
        if ($pid && $preco > 0 && $nome) {
            db()->prepare("UPDATE planos SET nome=?, descricao=?, preco_centavos=? WHERE id=?")
                ->execute([$nome, $desc, $preco, $pid]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'plano_create') {
        $preco  = (int)(floatval($_POST['preco']) * 100);
        $nome   = trim($_POST['nome'] ?? '');
        $desc   = trim($_POST['descricao'] ?? '');
        $limite = (int)($_POST['limite_clientes'] ?? 20);
        if ($preco > 0 && $nome) {
            $max_ordem = (int)db()->query("SELECT COALESCE(MAX(ordem),0) FROM planos")->fetchColumn();
            db()->prepare("INSERT INTO planos (nome,descricao,preco_centavos,limite_clientes,ativo,ordem) VALUES (?,?,?,?,1,?)")
                ->execute([$nome, $desc, $preco, $limite, $max_ordem + 1]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'plano_toggle') {
        db()->prepare("UPDATE planos SET ativo = NOT ativo WHERE id=?")->execute([(int)$_POST['plano_id']]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'config_save') {
        $cfg = db()->query("SELECT * FROM configuracoes LIMIT 1")->fetch();
        $d = [
            'trial_dias'       => (int)($_POST['trial_dias'] ?? 7),
            'desconto_anual'   => (int)($_POST['desconto_anual'] ?? 20),
            'whatsapp_suporte' => trim($_POST['whatsapp_suporte'] ?? ''),
            'email_suporte'    => trim($_POST['email_suporte'] ?? ''),
        ];
        if ($cfg) {
            db()->prepare("UPDATE configuracoes SET trial_dias=?,desconto_anual=?,whatsapp_suporte=?,email_suporte=? WHERE id=?")
                ->execute([$d['trial_dias'],$d['desconto_anual'],$d['whatsapp_suporte'],$d['email_suporte'],(int)$cfg['id']]);
        } else {
            db()->prepare("INSERT INTO configuracoes (trial_dias,desconto_anual,whatsapp_suporte,email_suporte) VALUES (?,?,?,?)")
                ->execute([$d['trial_dias'],$d['desconto_anual'],$d['whatsapp_suporte'],$d['email_suporte']]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false]); exit;
}

/* DADOS */
$tenants    = db()->query("SELECT t.*, p.nome AS plano_nome, p.preco_centavos FROM tenants t JOIN planos p ON p.id=t.plano_id ORDER BY t.id DESC")->fetchAll();
$planos_all = db()->query("SELECT * FROM planos ORDER BY ordem")->fetchAll();
$planos     = array_filter($planos_all, fn($p) => $p['ativo']);
$total_cli  = db()->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$total_cr   = db()->query("SELECT COUNT(*) FROM criativos")->fetchColumn();
$config     = db()->query("SELECT * FROM configuracoes LIMIT 1")->fetch() ?: [];

$ativos     = array_values(array_filter($tenants, fn($t) => $t['status'] === 'ativo'));
$trials     = array_values(array_filter($tenants, fn($t) => $t['status'] === 'trial'));
$inad       = array_values(array_filter($tenants, fn($t) => $t['status'] === 'inadimplente'));
$cancelados = array_values(array_filter($tenants, fn($t) => in_array($t['status'],['cancelado','suspenso'])));

$mrr   = array_sum(array_map(fn($t) => $t['preco_centavos'] / 100, $ativos));
$arr   = $mrr * 12;
$total_ten = count($tenants);
$churn = $total_ten > 0 ? round(count($cancelados) / $total_ten * 100, 1) : 0;
$ticket = count($ativos) > 0 ? $mrr / count($ativos) : 0;
$risco  = array_sum(array_map(fn($t) => $t['preco_centavos']/100, $inad));

/* MRR 6 meses */
$mrr_meses = [];
for ($i = 5; $i >= 0; $i--) {
    $mes_label = date('M/y', strtotime("-$i months"));
    $mes_end   = date('Y-m-t', strtotime("-$i months"));
    $val = db()->prepare("SELECT COALESCE(SUM(p.preco_centavos)/100,0) FROM tenants t JOIN planos p ON p.id=t.plano_id WHERE t.status='ativo' AND t.criado_em <= ?");
    $val->execute([$mes_end . ' 23:59:59']);
    $mrr_meses[] = ['mes' => $mes_label, 'val' => (float)$val->fetchColumn()];
}

/* Receita por plano */
$plano_receita = [];
foreach ($planos_all as $p) {
    $cnt = count(array_filter($ativos, fn($t) => $t['plano_nome'] === $p['nome']));
    $plano_receita[] = ['nome'=>$p['nome'],'receita'=>$cnt*$p['preco_centavos']/100,'clientes'=>$cnt];
}
usort($plano_receita, fn($a,$b) => $b['receita'] <=> $a['receita']);

/* Trials urgentes (=< 3 dias) */
$trial_urgente = array_filter($trials, function($t) {
    if (!$t['trial_ends_at']) return false;
    $diff = (strtotime($t['trial_ends_at']) - time()) / 86400;
    return $diff >= 0 && $diff <= 3;
});
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Super Admin — Opa Studio</title>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0d0d0d">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#141414;--surface2:#1e1e1e;--border:#2a2a2a;
  --accent:#78be20;--accent2:#4a9912;--text:#e8eaf0;--muted:#585858;
  --success:#4ade80;--warning:#fbbf24;--danger:#f87171;--info:#60a5fa}
:root.light{--bg:#f4f5f7;--surface:#ffffff;--surface2:#eef0f3;--border:#d8dce3;
  --text:#1a1d23;--muted:#7a8394}
*{margin:0;padding:0;box-sizing:border-box;transition:background-color .2s,border-color .2s,color .15s}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh}
header{position:sticky;top:0;z-index:100;background:rgba(13,13,13,.95);backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);padding:0 2rem;display:flex;align-items:center;justify-content:space-between;height:64px}
.logo{font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:2px;color:var(--accent);display:flex;align-items:center;gap:10px}
.sa-badge{font-family:'JetBrains Mono',monospace;font-size:.65rem;letter-spacing:1px;
  background:rgba(74,153,18,.2);color:var(--accent2);border:1px solid rgba(74,153,18,.3);padding:2px 8px;border-radius:4px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:7px;
  font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--accent);color:#0d0d0d}.btn-primary:hover{filter:brightness(1.1)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface2);color:var(--text)}
.btn-success{background:rgba(74,222,128,.15);color:var(--success);border:1px solid rgba(74,222,128,.3)}
.btn-warn{background:rgba(251,191,36,.12);color:var(--warning);border:1px solid rgba(251,191,36,.25)}
.btn-danger{background:rgba(248,113,113,.12);color:var(--danger);border:1px solid rgba(248,113,113,.25)}
.btn-info{background:rgba(96,165,250,.12);color:var(--info);border:1px solid rgba(96,165,250,.25)}
.btn-sm{padding:5px 10px;font-size:.75rem}
.tabs-nav{display:flex;border-bottom:1px solid var(--border);padding:0 1rem;background:var(--surface);
  overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:none;flex-wrap:nowrap}
.tabs-nav::-webkit-scrollbar{display:none}
.tab-btn{padding:14px 16px;font-family:'DM Sans',sans-serif;font-size:.875rem;font-weight:500;
  color:var(--muted);background:none;border:none;cursor:pointer;border-bottom:2px solid transparent;
  transition:all .15s;white-space:nowrap;flex-shrink:0}
.tab-btn:hover{color:var(--text)}.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);font-weight:600}
.tab-badge{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;
  border-radius:50%;font-size:.65rem;font-weight:700;background:var(--danger);color:#fff;margin-left:5px}
.tab-panel{display:none}.tab-panel.active{display:block}
main{max-width:1480px;margin:0 auto;padding:2rem}
.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:1rem;margin-bottom:2.5rem}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.1rem 1.25rem;position:relative;overflow:hidden}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.stat:nth-child(1)::before{background:var(--accent)}.stat:nth-child(2)::before{background:var(--success)}
.stat:nth-child(3)::before{background:var(--warning)}.stat:nth-child(4)::before{background:var(--danger)}
.stat:nth-child(5)::before{background:var(--info)}.stat:nth-child(6)::before{background:var(--accent2)}
.stat-lbl{font-size:.65rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-bottom:6px}
.stat-val{font-family:'Bebas Neue',sans-serif;font-size:1.9rem;line-height:1}
.stat-sub{font-size:.7rem;color:var(--muted);margin-top:2px;font-family:'JetBrains Mono',monospace}
.sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:10px}
.sec-title{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:2px;color:var(--text)}
.toolbar{display:flex;gap:10px;margin-bottom:1.25rem;flex-wrap:wrap;align-items:center}
.search-input,.filter-select,.form-input{background:var(--surface);border:1px solid var(--border);color:var(--text);
  padding:8px 13px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.875rem;outline:none;transition:border-color .15s}
.search-input::placeholder,.form-input::placeholder{color:var(--muted)}
.search-input:focus,.filter-select:focus,.form-input:focus{border-color:var(--accent)}
.search-input{flex:1;min-width:200px}.form-input{width:100%}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:600px}
thead th{padding:12px 16px;text-align:left;font-family:'JetBrains Mono',monospace;font-size:.65rem;
  color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;background:var(--surface2);
  border-bottom:1px solid var(--border);font-weight:600}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--surface2)}
tbody td{padding:13px 16px;font-size:.875rem;vertical-align:middle}
.td-mono{font-family:'JetBrains Mono',monospace;font-size:.75rem;color:var(--muted)}
.td-emp{font-weight:600}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.badge-ativo{background:rgba(74,222,128,.1);color:var(--success);border:1px solid rgba(74,222,128,.2)}
.badge-trial{background:rgba(120,190,32,.1);color:var(--accent);border:1px solid rgba(120,190,32,.2)}
.badge-inadimplente{background:rgba(251,191,36,.12);color:var(--warning);border:1px solid rgba(251,191,36,.25)}
.badge-cancelado,.badge-suspenso{background:rgba(248,113,113,.1);color:var(--danger);border:1px solid rgba(248,113,113,.2)}
.plano-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:6px;font-size:.7rem;font-weight:600;background:var(--surface2);border:1px solid var(--border)}
.actions{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.planos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.25rem}
.plano-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.5rem}
.plano-card.inativo{opacity:.55}
.plano-card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem}
.plano-nome-big{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:1px}
.plano-preco-big{font-family:'JetBrains Mono',monospace;font-size:1.5rem;color:var(--accent);font-weight:600}
.plano-preco-big span{font-size:.7rem;color:var(--muted)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-family:'JetBrains Mono',monospace}
.plano-tenants{font-size:.8rem;color:var(--muted);margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border)}
.plano-tenants strong{color:var(--text)}
.fin-grid{display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;margin-bottom:1.5rem}
.chart-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.5rem}
.chart-title{font-family:'Bebas Neue',sans-serif;font-size:1.1rem;letter-spacing:1.5px;margin-bottom:1.25rem}
.bar-chart{display:flex;align-items:flex-end;gap:8px;height:160px;padding-bottom:24px;position:relative}
.bar-chart::after{content:'';position:absolute;bottom:24px;left:0;right:0;height:1px;background:var(--border)}
.bar-wrap{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1}
.bar{background:linear-gradient(to top,var(--accent2),var(--accent));border-radius:4px 4px 0 0;width:100%;min-height:4px}
.bar-lbl{font-size:.6rem;color:var(--muted);font-family:'JetBrains Mono',monospace;white-space:nowrap}
.bar-val{font-size:.6rem;color:var(--accent);font-family:'JetBrains Mono',monospace;font-weight:600}
.pdist{display:flex;flex-direction:column;gap:.75rem}
.pdist-info{display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:3px}
.pdist-bar-bg{height:6px;background:var(--surface2);border-radius:3px;overflow:hidden}
.pdist-bar{height:100%;background:var(--accent);border-radius:3px}
.alert-warn{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.25);border-radius:10px;
  padding:.875rem 1.1rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.875rem}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:500;display:none;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:2rem;max-width:440px;width:100%;max-height:90vh;overflow-y:auto}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem}
.modal-title{font-family:'Bebas Neue',sans-serif;font-size:1.3rem;letter-spacing:1.5px}
.modal-close{background:none;border:none;color:var(--muted);font-size:1.3rem;cursor:pointer}.modal-close:hover{color:var(--text)}
.config-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.5rem}
.cfg-sep{font-family:'JetBrains Mono',monospace;font-size:.65rem;text-transform:uppercase;letter-spacing:2px;color:var(--muted);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:600;display:flex;flex-direction:column;gap:8px}
.toast{background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--accent);
  color:var(--text);padding:10px 16px;border-radius:10px;font-size:.85rem;font-weight:500;
  animation:toastIn .3s ease,toastOut .3s ease 2.7s both;box-shadow:0 8px 24px rgba(0,0,0,.4)}
.toast.error{border-left-color:var(--danger)}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{to{opacity:0;transform:translateX(20px)}}
@media(max-width:1200px){.stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .fin-grid{grid-template-columns:1fr}
  #tab-config > div{grid-template-columns:1fr!important}
}
@media(max-width:768px){
  header{padding:0 1rem}
  main{padding:1rem}
  .tabs-nav{padding:0 .5rem}
  .tab-btn{padding:12px 10px;font-size:.78rem}
  .stats{grid-template-columns:repeat(2,1fr);gap:.6rem;margin-bottom:1.5rem}
  .stat{padding:.85rem 1rem}
  .stat-val{font-size:1.5rem}
  .sec-header{flex-direction:column;align-items:flex-start}
  .toolbar{flex-direction:column}
  .toolbar .search-input,.toolbar .filter-select{width:100%}
  .table-wrap{border-radius:10px}
  table{min-width:500px}
  thead th,tbody td{padding:10px 12px;font-size:.8rem}
  .actions{flex-wrap:wrap;gap:4px}
  .btn-sm{padding:4px 8px;font-size:.7rem}
  .planos-grid{grid-template-columns:1fr}
  .form-row{grid-template-columns:1fr}
  .bar-lbl{font-size:.55rem}
  .bar-val{font-size:.55rem}
  .fin-grid{grid-template-columns:1fr}
  .modal{padding:1.5rem}
  #tab-config > div{grid-template-columns:1fr!important}
}
@media(max-width:480px){
  .stats{grid-template-columns:1fr 1fr}
  .stat-val{font-size:1.3rem}
  .logo img{height:28px}
  .sa-badge{display:none}
  .nav-actions .btn{padding:6px 10px;font-size:.75rem}
}
</style>
<link rel="stylesheet" href="menu_lateral.css?v=1">
</head>
<body>
<header>
  <div class="logo">
    <img id="navLogo" src="logo_studio_dark.png" alt="Opa Studio" style="height:36px;width:auto;object-fit:contain;max-width:155px">
    <span class="sa-badge">SUPERADMIN</span>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button id="themeToggle" onclick="toggleTheme()" class="btn btn-ghost btn-sm" title="Alternar tema" style="font-size:1.1rem;padding:.3rem .6rem;line-height:1">🌙</button>
    <a href="planos.php" target="_blank" class="btn btn-ghost btn-sm">Ver planos</a>
    <a href="logout.php" class="btn btn-ghost btn-sm">Sair</a>
  </div>
</header>

<nav class="tabs-nav">
  <button class="tab-btn active" onclick="switchTab('overview',this)">📊 Visão Geral</button>
  <button class="tab-btn" onclick="switchTab('agencias',this)">🏢 Agências<?php if(count($inad)): ?> <span class="tab-badge"><?= count($inad) ?></span><?php endif ?></button>
  <button class="tab-btn" onclick="switchTab('planos',this)">💳 Planos</button>
  <button class="tab-btn" onclick="switchTab('financeiro',this)">💰 Financeiro</button>
  <button class="tab-btn" onclick="switchTab('recorrencia',this)">🔄 Recorrência</button>
  <button class="tab-btn" onclick="switchTab('config',this)">⚙️ Configurações</button>
  <button class="tab-btn" onclick="switchTab('ia',this);iaCarregarTudo()" style="position:relative">🧠 IA <span style="background:#00e04f;color:#000;font-size:.55rem;font-weight:800;padding:1px 5px;border-radius:999px;letter-spacing:.04em;vertical-align:middle;margin-left:2px;animation:pulse-badge 2s infinite">Novo</span></button>
  <a class="tab-btn" href="prospeccao/" style="text-decoration:none;display:inline-flex;align-items:center">🔎 Prospecção</a>
</nav>

<main>

<!-- TAB VISÃO GERAL -->
<div class="tab-panel active" id="tab-overview">
<?php if(count($trial_urgente)): ?>
<div class="alert-warn">
  <span>⚠️</span>
  <strong><?= count($trial_urgente) ?> trial(s) expirando em até 3 dias:</strong>
  <?= implode(', ', array_map(fn($t)=>htmlspecialchars($t['nome_empresa']),$trial_urgente)) ?>
</div>
<?php endif ?>
<div class="stats">
  <div class="stat">
    <div class="stat-lbl">MRR</div>
    <div class="stat-val" style="color:var(--accent)">R$<?= number_format($mrr,0,'.','.') ?></div>
    <div class="stat-sub"><?= count($ativos) ?> agências ativas</div>
  </div>
  <div class="stat">
    <div class="stat-lbl">ARR Projetado</div>
    <div class="stat-val">R$<?= number_format($arr,0,'.','.') ?></div>
    <div class="stat-sub">12 × MRR atual</div>
  </div>
  <div class="stat">
    <div class="stat-lbl">Em Trial</div>
    <div class="stat-val" style="color:var(--accent)"><?= count($trials) ?></div>
    <div class="stat-sub"><?= count($trial_urgente) ?> expirando em breve</div>
  </div>
  <div class="stat">
    <div class="stat-lbl">Inadimplente</div>
    <div class="stat-val" style="color:var(--warning)"><?= count($inad) ?></div>
    <div class="stat-sub">Risco: R$<?= number_format($risco,0,'.','.') ?>/mês</div>
  </div>
  <div class="stat">
    <div class="stat-lbl">Churn Rate</div>
    <div class="stat-val" style="color:<?= $churn>10?'var(--danger)':'var(--text)' ?>"><?= $churn ?>%</div>
    <div class="stat-sub"><?= count($cancelados) ?> cancelados/suspensos</div>
  </div>
  <div class="stat">
    <div class="stat-lbl">Clientes/Criativos</div>
    <div class="stat-val"><?= $total_cli ?></div>
    <div class="stat-sub"><?= $total_cr ?> criativos gerados</div>
  </div>
</div>

<div class="sec-header"><div class="sec-title">Distribuição por Plano</div></div>
<div class="table-wrap">
<table>
<thead><tr><th>Plano</th><th>Ativos</th><th>Trial</th><th>Inadimplente</th><th>Receita/mês</th><th>% MRR</th></tr></thead>
<tbody>
<?php foreach($planos_all as $p):
  $atv = count(array_filter($tenants,fn($t)=>$t['plano_nome']===$p['nome']&&$t['status']==='ativo'));
  $tri = count(array_filter($tenants,fn($t)=>$t['plano_nome']===$p['nome']&&$t['status']==='trial'));
  $ind = count(array_filter($tenants,fn($t)=>$t['plano_nome']===$p['nome']&&$t['status']==='inadimplente'));
  $rec = $atv * $p['preco_centavos']/100;
  $pct = $mrr>0 ? round($rec/$mrr*100,1) : 0;
?>
<tr>
  <td><strong><?= htmlspecialchars($p['nome']) ?></strong><?= !$p['ativo']?' <span class="badge badge-cancelado" style="font-size:.6rem">INATIVO</span>':'' ?></td>
  <td><span class="badge badge-ativo"><?= $atv ?></span></td>
  <td><span class="badge badge-trial"><?= $tri ?></span></td>
  <td><span class="badge badge-inadimplente"><?= $ind ?></span></td>
  <td class="td-mono">R$ <?= number_format($rec,2,',','.') ?></td>
  <td>
    <div style="display:flex;align-items:center;gap:8px">
      <div style="flex:1;height:5px;background:var(--surface2);border-radius:3px"><div style="width:<?= $pct ?>%;height:100%;background:var(--accent);border-radius:3px"></div></div>
      <span class="td-mono"><?= $pct ?>%</span>
    </div>
  </td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</div>

<!-- TAB AGÊNCIAS -->
<div class="tab-panel" id="tab-agencias">
<div class="toolbar">
  <input type="text" class="search-input" id="searchTenants" placeholder="🔍 Buscar empresa ou e-mail..." oninput="filtrar()">
  <select class="filter-select" id="filterStatus" onchange="filtrar()" style="min-width:150px">
    <option value="">Todos os status</option>
    <option value="ativo">Ativo</option><option value="trial">Trial</option>
    <option value="inadimplente">Inadimplente</option><option value="suspenso">Suspenso</option><option value="cancelado">Cancelado</option>
  </select>
  <select class="filter-select" id="filterPlano" onchange="filtrar()" style="min-width:130px">
    <option value="">Todos os planos</option>
    <?php foreach($planos_all as $p): ?><option><?= htmlspecialchars($p['nome']) ?></option><?php endforeach ?>
  </select>
</div>
<div class="table-wrap">
<table>
<thead><tr><th>#</th><th>Empresa</th><th>E-mail</th><th>Plano</th><th>Status</th><th>Trial até</th><th>Cadastro</th><th>Ações</th></tr></thead>
<tbody id="tenantTable">
<?php foreach($tenants as $t): ?>
<tr data-empresa="<?= strtolower(htmlspecialchars($t['nome_empresa'])) ?>"
    data-email="<?= strtolower(htmlspecialchars($t['email_admin'])) ?>"
    data-status="<?= $t['status'] ?>" data-plano="<?= htmlspecialchars($t['plano_nome']) ?>">
  <td class="td-mono">#<?= $t['id'] ?></td>
  <td><div class="td-emp"><?= htmlspecialchars($t['nome_empresa']) ?></div><div class="td-mono"><?= htmlspecialchars($t['slug']) ?></div></td>
  <td class="td-mono"><?= htmlspecialchars($t['email_admin']) ?></td>
  <td>
    <select class="filter-select" style="font-size:.75rem;padding:4px 8px" onchange="trocarPlano(<?= $t['id'] ?>,this.value)">
      <?php foreach($planos_all as $p): ?><option value="<?= $p['id'] ?>" <?= $p['nome']===$t['plano_nome']?'selected':'' ?>><?= htmlspecialchars($p['nome']) ?></option><?php endforeach ?>
    </select>
  </td>
  <td><span class="badge badge-<?= $t['status'] ?>"><?= strtoupper($t['status']) ?></span></td>
  <td class="td-mono"><?= $t['trial_ends_at'] ? date('d/m/Y',strtotime($t['trial_ends_at'])) : '—' ?></td>
  <td class="td-mono"><?= date('d/m/y',strtotime($t['criado_em'])) ?></td>
  <td>
    <div class="actions">
      <?php if($t['status']!=='ativo'): ?><button class="btn btn-success btn-sm" onclick="acao('ativar',<?= $t['id'] ?>,this)">✓</button><?php endif ?>
      <?php if($t['status']==='ativo'): ?><button class="btn btn-warn btn-sm" onclick="acao('suspender',<?= $t['id'] ?>,this)">⏸</button><?php endif ?>
      <button class="btn btn-info btn-sm" onclick="openExtendModal(<?= $t['id'] ?>,'<?= htmlspecialchars($t['nome_empresa']) ?>')">+Trial</button>
      <button class="btn btn-danger btn-sm" onclick="acao('excluir',<?= $t['id'] ?>,this)" data-confirm="Excluir permanentemente?">🗑</button>
    </div>
  </td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</div>

<!-- TAB PLANOS -->
<div class="tab-panel" id="tab-planos">
<div class="sec-header">
  <div class="sec-title">Configurar Planos</div>
  <button class="btn btn-primary" onclick="document.getElementById('modal-novo-plano').classList.add('open')">+ Novo Plano</button>
</div>
<p style="color:var(--muted);font-size:.85rem;margin-bottom:1.5rem">
  Alterações salvas aqui atualizam o banco de dados e refletem no <strong>planos.php</strong>. 
  Para a <strong>landing page (index.html)</strong>, veja a tabela abaixo com os IDs a atualizar.
</p>
<div class="planos-grid">
<?php foreach($planos_all as $p):
  $cnt_atv = count(array_filter($ativos,fn($t)=>$t['plano_nome']===$p['nome']));
  $cnt_tri = count(array_filter($trials,fn($t)=>$t['plano_nome']===$p['nome']));
  $receita = $cnt_atv * $p['preco_centavos']/100;
?>
<div class="plano-card <?= $p['ativo']?'':'inativo' ?>">
  <div class="plano-card-header">
    <div>
      <div class="plano-nome-big"><?= htmlspecialchars($p['nome']) ?></div>
      <?= $p['ativo']?'<span class="badge badge-ativo" style="font-size:.6rem">ATIVO</span>':'<span class="badge badge-cancelado" style="font-size:.6rem">INATIVO</span>' ?>
    </div>
    <div class="plano-preco-big">R$<?= number_format($p['preco_centavos']/100,0,'.','.') ?><span>/mês</span></div>
  </div>
  <form onsubmit="salvarPlano(event,<?= $p['id'] ?>)">
    <div class="form-row">
      <div class="form-group"><label>Nome</label><input type="text" class="form-input" name="nome" value="<?= htmlspecialchars($p['nome']) ?>" required></div>
      <div class="form-group"><label>R$/mês</label><input type="number" class="form-input" name="preco" value="<?= number_format($p['preco_centavos']/100,2,'.','') ?>" min="1" step="0.01" required></div>
    </div>
    <div class="form-group" style="margin-bottom:12px">
      <label>Descrição</label>
      <input type="text" class="form-input" name="descricao" value="<?= htmlspecialchars($p['descricao']??'') ?>" placeholder="Tagline do plano">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary btn-sm" style="flex:1">💾 Salvar</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="togglePlano(<?= $p['id'] ?>)"><?= $p['ativo']?'🔕 Desativar':'✅ Ativar' ?></button>
    </div>
  </form>
  <div class="plano-tenants"><strong><?= $cnt_atv ?></strong> ativo · <strong><?= $cnt_tri ?></strong> trial · <strong style="color:var(--accent)">R$<?= number_format($receita,0,'.','.') ?>/mês</strong></div>
</div>
<?php endforeach ?>
</div>

<div style="margin:2.5rem 0 1.25rem">
  <div class="sec-title">Referência: Preços para index.html</div>
</div>
<div class="table-wrap">
<table>
<thead><tr><th>Plano</th><th>Preço Mensal (DB)</th><th>ID no HTML</th><th>data-mensal</th><th>data-anual (-20%)</th></tr></thead>
<tbody>
<?php foreach($planos_all as $p):
  $preco = $p['preco_centavos']/100;
  $anual = round($preco*0.8);
?>
<tr>
  <td><strong><?= htmlspecialchars($p['nome']) ?></strong></td>
  <td class="td-mono" style="color:var(--accent)">R$ <?= number_format($preco,0,'.','.') ?></td>
  <td class="td-mono"><code style="background:var(--surface2);padding:2px 6px;border-radius:4px">lp-val-<?= $p['id'] ?></code></td>
  <td class="td-mono"><?= $preco ?></td>
  <td class="td-mono" style="color:var(--warning)"><?= $anual ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p style="color:var(--muted);font-size:.78rem;margin-top:.6rem">
  No <code>index.html</code>, localize cada ID e atualize <code>data-mensal="<?php echo 'VALOR'; ?>"</code> e <code>data-anual="<?php echo 'VALOR*0.8'; ?>"</code>.
</p>
</div>

<!-- TAB FINANCEIRO -->
<div class="tab-panel" id="tab-financeiro">
<div class="stats" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat"><div class="stat-lbl">MRR</div><div class="stat-val" style="color:var(--accent)">R$<?= number_format($mrr,0,'.','.') ?></div><div class="stat-sub">receita mensal recorrente</div></div>
  <div class="stat"><div class="stat-lbl">ARR</div><div class="stat-val">R$<?= number_format($arr,0,'.','.') ?></div><div class="stat-sub">receita anual projetada</div></div>
  <div class="stat"><div class="stat-lbl">Ticket Médio</div><div class="stat-val">R$<?= number_format($ticket,0,'.','.') ?></div><div class="stat-sub">por agência ativa</div></div>
  <div class="stat"><div class="stat-lbl">Receita em Risco</div><div class="stat-val" style="color:var(--warning)">R$<?= number_format($risco,0,'.','.') ?></div><div class="stat-sub"><?= count($inad) ?> inadimplente(s)</div></div>
</div>

<div class="fin-grid">
  <div class="chart-card">
    <div class="chart-title">MRR — Últimos 6 Meses</div>
    <?php $max_val = max(array_map(fn($m)=>$m['val'],$mrr_meses)?: [1]); if(!$max_val)$max_val=1; ?>
    <div class="bar-chart">
      <?php foreach($mrr_meses as $m): ?>
      <div class="bar-wrap">
        <span class="bar-val">R$<?= number_format($m['val'],0,'.','.') ?></span>
        <div class="bar" style="height:<?= max(4,round($m['val']/$max_val*120)) ?>px"></div>
        <span class="bar-lbl"><?= $m['mes'] ?></span>
      </div>
      <?php endforeach ?>
    </div>
  </div>
  <div class="chart-card">
    <div class="chart-title">Receita por Plano</div>
    <?php $max_rec = max(array_map(fn($p)=>$p['receita'],$plano_receita)?:[1])?:1; ?>
    <div class="pdist">
      <?php foreach($plano_receita as $pr): ?>
      <div>
        <div class="pdist-info">
          <span style="font-weight:600"><?= htmlspecialchars($pr['nome']) ?></span>
          <span style="color:var(--accent);font-family:'JetBrains Mono',monospace;font-size:.75rem">R$<?= number_format($pr['receita'],0,'.','.') ?></span>
        </div>
        <div class="pdist-bar-bg"><div class="pdist-bar" style="width:<?= $max_rec>0?round($pr['receita']/$max_rec*100):0 ?>%"></div></div>
        <div style="font-size:.7rem;color:var(--muted);margin-top:2px"><?= $pr['clientes'] ?> agência(s)</div>
      </div>
      <?php endforeach ?>
    </div>
  </div>
</div>

<div class="sec-header"><div class="sec-title">Detalhamento — Agências Ativas</div></div>
<div class="table-wrap">
<table>
<thead><tr><th>#</th><th>Empresa</th><th>Plano</th><th>Valor/mês</th><th>Cliente desde</th><th>Meses ativo</th><th>LTV Estimado</th></tr></thead>
<tbody>
<?php
$sorted = $ativos;
usort($sorted, fn($a,$b)=>$b['preco_centavos']<=>$a['preco_centavos']);
foreach($sorted as $t):
  $meses = max(1, round((time()-strtotime($t['criado_em']))/2592000));
  $ltv = $t['preco_centavos']/100 * $meses;
?>
<tr>
  <td class="td-mono">#<?= $t['id'] ?></td>
  <td><div class="td-emp"><?= htmlspecialchars($t['nome_empresa']) ?></div><div class="td-mono"><?= htmlspecialchars($t['email_admin']) ?></div></td>
  <td><span class="plano-badge"><?= htmlspecialchars($t['plano_nome']) ?></span></td>
  <td class="td-mono" style="color:var(--accent)">R$ <?= number_format($t['preco_centavos']/100,2,',','.') ?></td>
  <td class="td-mono"><?= date('d/m/Y',strtotime($t['criado_em'])) ?></td>
  <td class="td-mono"><?= $meses ?>m</td>
  <td class="td-mono" style="color:var(--muted)">R$ <?= number_format($ltv,0,'.','.') ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p style="color:var(--muted);font-size:.75rem;margin-top:.5rem">* LTV estimado = valor mensal × meses desde criação</p>
</div>

<!-- TAB RECORRÊNCIA -->
<div class="tab-panel" id="tab-recorrencia">
<div class="stats" style="grid-template-columns:repeat(4,1fr);margin-bottom:2rem">
  <div class="stat"><div class="stat-lbl">Assinaturas Ativas</div><div class="stat-val" style="color:var(--success)"><?= count($ativos) ?></div><div class="stat-sub">cobrança mensal</div></div>
  <div class="stat"><div class="stat-lbl">Trials</div><div class="stat-val" style="color:var(--accent)"><?= count($trials) ?></div>
    <?php $exp7=count(array_filter($trials,fn($t)=>$t['trial_ends_at']&&(strtotime($t['trial_ends_at'])-time())/86400<=7)); ?>
    <div class="stat-sub"><?= $exp7 ?> expira em ≤7 dias</div></div>
  <div class="stat"><div class="stat-lbl">Inadimplentes</div><div class="stat-val" style="color:var(--warning)"><?= count($inad) ?></div><div class="stat-sub">aguardando pagamento</div></div>
  <div class="stat"><div class="stat-lbl">Cancelados</div><div class="stat-val" style="color:var(--danger)"><?= count($cancelados) ?></div><div class="stat-sub">total histórico</div></div>
</div>

<?php if(count($trials)>0): ?>
<div class="sec-header"><div class="sec-title">Trials — Acompanhamento</div></div>
<div class="table-wrap" style="margin-bottom:2rem">
<table>
<thead><tr><th>Empresa</th><th>E-mail</th><th>Plano</th><th>Expira em</th><th>Dias restantes</th><th>Ações</th></tr></thead>
<tbody>
<?php foreach($trials as $t):
  $diff = $t['trial_ends_at'] ? round((strtotime($t['trial_ends_at'])-time())/86400) : null;
  $urgente = $diff!==null && $diff<=3;
?>
<tr style="<?= $urgente?'background:rgba(251,191,36,.04)':'' ?>">
  <td><strong><?= htmlspecialchars($t['nome_empresa']) ?></strong></td>
  <td class="td-mono"><?= htmlspecialchars($t['email_admin']) ?></td>
  <td><span class="plano-badge"><?= htmlspecialchars($t['plano_nome']) ?></span></td>
  <td class="td-mono"><?= $t['trial_ends_at']?date('d/m/Y',strtotime($t['trial_ends_at'])):'—' ?></td>
  <td><?php if($diff!==null): ?><span class="badge <?= $diff<=0?'badge-cancelado':($diff<=3?'badge-inadimplente':'badge-trial') ?>"><?= $diff<=0?'EXPIRADO':"$diff dias" ?></span><?php else: echo '—'; endif ?></td>
  <td><div class="actions">
    <button class="btn btn-info btn-sm" onclick="openExtendModal(<?= $t['id'] ?>,'<?= htmlspecialchars($t['nome_empresa']) ?>')">+ Estender</button>
    <button class="btn btn-success btn-sm" onclick="acao('ativar',<?= $t['id'] ?>,this)">✓ Converter</button>
  </div></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<?php if(count($inad)>0): ?>
<div class="sec-header"><div class="sec-title">Inadimplentes — Cobrança Pendente</div></div>
<div class="table-wrap" style="margin-bottom:2rem">
<table>
<thead><tr><th>Empresa</th><th>E-mail</th><th>Plano</th><th>Valor/mês</th><th>Asaas ID</th><th>Ações</th></tr></thead>
<tbody>
<?php foreach($inad as $t): ?>
<tr>
  <td><strong><?= htmlspecialchars($t['nome_empresa']) ?></strong></td>
  <td class="td-mono"><?= htmlspecialchars($t['email_admin']) ?></td>
  <td><span class="plano-badge"><?= htmlspecialchars($t['plano_nome']) ?></span></td>
  <td class="td-mono" style="color:var(--warning)">R$ <?= number_format($t['preco_centavos']/100,2,',','.') ?></td>
  <td class="td-mono" style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($t['asaas_customer_id']??'—') ?></td>
  <td><div class="actions">
    <button class="btn btn-success btn-sm" onclick="acao('ativar',<?= $t['id'] ?>,this)">✓ Regularizado</button>
    <button class="btn btn-warn btn-sm" onclick="acao('suspender',<?= $t['id'] ?>,this)">⏸ Suspender</button>
    <?php if(!empty($t['asaas_customer_id'])): ?><a href="https://www.asaas.com" target="_blank" class="btn btn-ghost btn-sm">Asaas ↗</a><?php endif ?>
  </div></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<div class="sec-header"><div class="sec-title">Todas as Assinaturas Ativas</div></div>
<div class="table-wrap">
<table>
<thead><tr><th>Empresa</th><th>Plano</th><th>Valor/mês</th><th>Cliente desde</th><th>Asaas ID</th><th>Status</th></tr></thead>
<tbody>
<?php foreach($ativos as $t): ?>
<tr>
  <td><div class="td-emp"><?= htmlspecialchars($t['nome_empresa']) ?></div><div class="td-mono"><?= htmlspecialchars($t['email_admin']) ?></div></td>
  <td><span class="plano-badge"><?= htmlspecialchars($t['plano_nome']) ?></span></td>
  <td class="td-mono" style="color:var(--accent)">R$ <?= number_format($t['preco_centavos']/100,2,',','.') ?></td>
  <td class="td-mono"><?= date('d/m/Y',strtotime($t['criado_em'])) ?></td>
  <td class="td-mono" style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($t['asaas_customer_id']??'—') ?></td>
  <td><span class="badge badge-ativo">ATIVO</span></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</div>

<!-- TAB CONFIGURAÇÕES -->
<div class="tab-panel" id="tab-config">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;max-width:900px">
  <div class="config-card">
    <div class="cfg-sep">⚙️ Configurações Gerais</div>
    <form onsubmit="salvarConfig(event)">
      <div class="form-group" style="margin-bottom:12px">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Dias de Trial</label>
        <input type="number" class="form-input" name="trial_dias" value="<?= (int)($config['trial_dias']??7) ?>" min="1" max="90">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Desconto Plano Anual (%)</label>
        <input type="number" class="form-input" name="desconto_anual" value="<?= (int)($config['desconto_anual']??20) ?>" min="0" max="80">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">WhatsApp Suporte (DDI+número)</label>
        <input type="text" class="form-input" name="whatsapp_suporte" value="<?= htmlspecialchars($config['whatsapp_suporte']??'') ?>" placeholder="5548999999999">
      </div>
      <div class="form-group" style="margin-bottom:18px">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">E-mail Suporte</label>
        <input type="email" class="form-input" name="email_suporte" value="<?= htmlspecialchars($config['email_suporte']??'') ?>" placeholder="contato@opadesign.com.br">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">💾 Salvar</button>
    </form>
  </div>
  <div style="display:flex;flex-direction:column;gap:1rem">
    <div class="config-card">
      <div class="cfg-sep">🔗 Integrações</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <a href="https://www.asaas.com/dashboard" target="_blank" class="btn btn-ghost" style="justify-content:flex-start">💳 Painel Asaas ↗</a>
        <a href="webhook.php" target="_blank" class="btn btn-ghost" style="justify-content:flex-start">🔔 Testar Webhook</a>
        <a href="cron_trial.php" target="_blank" class="btn btn-ghost" style="justify-content:flex-start">⏰ Rodar Cron Trial</a>
        <a href="configuracoes.php" class="btn btn-ghost" style="justify-content:flex-start">🏷️ FAQs e Textos</a>
      </div>
    </div>
    <div class="config-card">
      <div class="cfg-sep">📋 Resumo do Sistema</div>
      <div style="display:flex;flex-direction:column;gap:7px;font-size:.8rem;font-family:'JetBrains Mono',monospace;color:var(--muted)">
        <div>Tenants: <strong style="color:var(--text)"><?= $total_ten ?></strong></div>
        <div>Ativos: <strong style="color:var(--success)"><?= count($ativos) ?></strong></div>
        <div>Clientes: <strong style="color:var(--text)"><?= $total_cli ?></strong></div>
        <div>Criativos: <strong style="color:var(--text)"><?= $total_cr ?></strong></div>
        <div>Planos: <strong style="color:var(--text)"><?= count(array_filter($planos_all,fn($p)=>$p['ativo'])) ?> ativos</strong></div>
        <div>MRR: <strong style="color:var(--accent)">R$ <?= number_format($mrr,2,',','.') ?></strong></div>
      </div>
    </div>
  </div>
</div>
</div>

</main>

<!-- ── ABA IA ─────────────────────────────────────── -->
<style>
@keyframes pulse-badge{0%,100%{opacity:1}50%{opacity:.5}}
</style>
<script>var _iaTudoCarregado=false;</script>

<div class="tab-panel" id="tab-ia">
<div style="padding:1.5rem">

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem">
    <div>
      <div style="font-size:1.3rem;font-weight:800;margin:0">🧠 Inteligência de Mercado IA</div>
      <div style="color:var(--muted);font-size:.85rem;margin:.25rem 0 0">Limites por plano, créditos avulsos e uso mensal</div>
    </div>
    <button onclick="iaCarregarRelatorio()" class="btn btn-ghost" style="display:flex;align-items:center;gap:.4rem">🔄 Atualizar</button>
  </div>

  <!-- Config de planos -->
  <div class="config-card" style="margin-bottom:1.5rem">
    <div class="cfg-sep">⚙️ Análises incluídas por plano / mês</div>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.88rem">
        <thead>
          <tr style="border-bottom:1px solid var(--border)">
            <th style="text-align:left;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Plano</th>
            <th style="text-align:left;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Análises / mês</th>
            <th style="text-align:left;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Preço crédito avulso (R$)</th>
          </tr>
        </thead>
        <tbody id="iaConfigBody">
          <tr><td colspan="3" style="padding:1.5rem;text-align:center;color:var(--muted)">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
    <div style="margin-top:1rem;display:flex;align-items:center;gap:1rem">
      <button onclick="iaSalvarConfig()" class="btn btn-primary">💾 Salvar configurações</button>
      <span id="iaConfigMsg" style="font-size:.85rem;display:none"></span>
    </div>
  </div>

  <!-- Uso do mês -->
  <div class="config-card" style="margin-bottom:1.5rem">
    <div class="cfg-sep">📊 Uso no mês atual</div>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.88rem">
        <thead>
          <tr style="border-bottom:1px solid var(--border)">
            <th style="text-align:left;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Agência</th>
            <th style="text-align:left;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Plano</th>
            <th style="text-align:center;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Mensais</th>
            <th style="text-align:center;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Por crédito</th>
            <th style="text-align:center;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Total</th>
            <th style="text-align:center;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Saldo créditos</th>
            <th style="text-align:center;padding:.6rem .75rem;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Ação</th>
          </tr>
        </thead>
        <tbody id="iaRelatorioBody">
          <tr><td colspan="7" style="padding:1.5rem;text-align:center;color:var(--muted)">Clique em Atualizar para carregar.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Adicionar créditos -->
  <div class="config-card">
    <div class="cfg-sep">➕ Adicionar créditos para agência</div>
    <div style="display:grid;grid-template-columns:1fr 150px auto;gap:1rem;align-items:end">
      <div>
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Agência</label>
        <select id="iaCreditoAgencia" class="form-input">
          <option value="">Selecione...</option>
        </select>
      </div>
      <div>
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Créditos</label>
        <input type="number" id="iaCreditoQtd" class="form-input" min="1" max="999" value="10">
      </div>
      <div>
        <button onclick="iaAdicionarCreditos()" class="btn btn-primary">✚ Adicionar</button>
      </div>
    </div>
    <div style="margin-top:.75rem">
      <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Observação (opcional)</label>
      <input type="text" id="iaCreditoObs" class="form-input" placeholder="Ex: Pagamento PIX em 24/03/2026" style="max-width:400px">
    </div>
    <div id="iaCreditoMsg" style="margin-top:.75rem;font-size:.85rem;display:none"></div>
  </div>

</div>
</div>
<!-- /tab-ia -->

<!-- MODAL EXTEND TRIAL -->
<div class="modal-overlay" id="modal-extend" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Estender Trial</div>
      <button class="modal-close" onclick="document.getElementById('modal-extend').classList.remove('open')">✕</button>
    </div>
    <p style="color:var(--muted);font-size:.9rem;margin-bottom:1.25rem">Adicionar dias ao trial de <strong id="extend-nome"></strong></p>
    <div class="form-group" style="margin-bottom:1.5rem">
      <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Dias a adicionar</label>
      <input type="number" class="form-input" id="extend-dias" value="7" min="1" max="90">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-ghost" onclick="document.getElementById('modal-extend').classList.remove('open')" style="flex:1">Cancelar</button>
      <button class="btn btn-primary" onclick="confirmarExtend()" style="flex:1">+ Estender</button>
    </div>
  </div>
</div>

<!-- MODAL NOVO PLANO -->
<div class="modal-overlay" id="modal-novo-plano" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Criar Novo Plano</div>
      <button class="modal-close" onclick="document.getElementById('modal-novo-plano').classList.remove('open')">✕</button>
    </div>
    <form onsubmit="criarPlano(event)">
      <div class="form-group" style="margin-bottom:12px">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Nome do Plano</label>
        <input type="text" class="form-input" name="nome" placeholder="Ex: ENTERPRISE" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Preço R$/mês</label>
        <input type="number" class="form-input" name="preco" min="1" step="0.01" placeholder="497.00" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Descrição / Tagline</label>
        <input type="text" class="form-input" name="descricao" placeholder="Para grandes agências">
      </div>
      <div class="form-group" style="margin-bottom:1.5rem">
        <label style="font-size:.7rem;color:var(--muted);display:block;margin-bottom:5px">Limite de Clientes</label>
        <input type="number" class="form-input" name="limite_clientes" value="50" min="1">
      </div>
      <div style="display:flex;gap:10px">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-novo-plano').classList.remove('open')" style="flex:1">Cancelar</button>
        <button type="submit" class="btn btn-primary" style="flex:1">Criar Plano</button>
      </div>
    </form>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
// ── INTELIGÊNCIA IA ──────────────────────────────────────────
function iaCarregarTudo(){
  if(_iaTudoCarregado) return;
  _iaTudoCarregado = true;
  iaCarregarRelatorio();
  iaCarregarAgenciasSelect();
}

function iaCarregarRelatorio(){
  fetch('ia_analises.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'relatorio'})})
  .then(r=>r.json())
  .then(data=>{
    iaRenderConfig(data.config_planos||[]);
    iaRenderRelatorio(data.dados||[]);
  })
  .catch(()=>{
    document.getElementById('iaRelatorioBody').innerHTML='<tr><td colspan="7" style="padding:1.5rem;text-align:center;color:#ef4444">Erro: verifique se ia_analises.php está na pasta sistema/</td></tr>';
  });
}

function iaRenderConfig(planos){
  var defs=[
    {plano:'starter',label:'Starter',analises_mes:0,credito_preco:4.90},
    {plano:'pro',label:'Pro ⭐',analises_mes:5,credito_preco:4.90},
    {plano:'agencia',label:'Agência',analises_mes:20,credito_preco:3.90},
  ];
  defs.forEach(d=>{
    var f=planos.find(p=>p.plano===d.plano||p.plano===d.plano.replace('starter','start'));
    if(f){d.analises_mes=f.analises_mes;d.credito_preco=f.credito_preco;}
  });
  var inp='background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:.4rem .6rem;color:var(--text);font-size:.88rem;width:90px';
  document.getElementById('iaConfigBody').innerHTML=defs.map(p=>
    '<tr style="border-bottom:1px solid var(--border)">'+
    '<td style="padding:.75rem"><strong>'+p.label+'</strong></td>'+
    '<td style="padding:.75rem"><input type="number" class="ia-an-input" data-plano="'+p.plano+'" min="0" max="999" value="'+p.analises_mes+'" style="'+inp+'"></td>'+
    '<td style="padding:.75rem"><span style="margin-right:.3rem;color:var(--muted)">R$</span><input type="number" class="ia-pr-input" data-plano="'+p.plano+'" min="0.90" max="999" step="0.10" value="'+parseFloat(p.credito_preco).toFixed(2)+'" style="'+inp+'"></td>'+
    '</tr>'
  ).join('');
}

function iaSalvarConfig(){
  var planos=[];
  document.querySelectorAll('.ia-an-input').forEach(el=>{
    var pr=document.querySelector('.ia-pr-input[data-plano="'+el.dataset.plano+'"]');
    planos.push({plano:el.dataset.plano,analises_mes:parseInt(el.value)||0,credito_preco:parseFloat(pr?pr.value:4.90)||4.90});
  });
  fetch('ia_analises.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'salvar_config',planos})})
  .then(r=>r.json())
  .then(data=>{
    var msg=document.getElementById('iaConfigMsg');
    msg.textContent=data.ok?'✓ Salvo!':'✗ '+(data.erro||'Erro');
    msg.style.color=data.ok?'var(--success)':'#ef4444';
    msg.style.display='inline';
    setTimeout(()=>msg.style.display='none',3000);
  });
}

function iaRenderRelatorio(dados){
  var tbody=document.getElementById('iaRelatorioBody');
  if(!dados.length){
    tbody.innerHTML='<tr><td colspan="7" style="padding:1.5rem;text-align:center;color:var(--muted)">Nenhum uso registrado este mês.</td></tr>';
    return;
  }
  var cores={agencia:'#00e04f',pro:'#4ac3f8',starter:'#888',start:'#888'};
  tbody.innerHTML=dados.map(r=>{
    var cor=cores[r.plano]||'#888';
    var saldo=parseInt(r.creditos_saldo)||0;
    return '<tr style="border-bottom:1px solid var(--border)">'+
      '<td style="padding:.75rem"><strong>'+r.agencia+'</strong></td>'+
      '<td style="padding:.75rem"><span style="background:'+cor+'22;color:'+cor+';border:1px solid '+cor+';border-radius:999px;padding:2px 10px;font-size:.7rem;font-weight:700">'+r.plano+'</span></td>'+
      '<td style="padding:.75rem;text-align:center">'+r.uso_mensal+'</td>'+
      '<td style="padding:.75rem;text-align:center">'+r.uso_credito+'</td>'+
      '<td style="padding:.75rem;text-align:center"><strong>'+r.total+'</strong></td>'+
      '<td style="padding:.75rem;text-align:center;color:'+(saldo>0?'var(--success)':'var(--muted)')+';font-weight:700">'+saldo+'</td>'+
      '<td style="padding:.75rem;text-align:center"><button onclick="iaSetAgencia('+r.agencia_id+')" class="btn btn-ghost" style="padding:.3rem .7rem;font-size:.78rem">+ Créditos</button></td>'+
    '</tr>';
  }).join('');
}

function iaCarregarAgenciasSelect(){
  // Reutiliza agências já carregadas na aba Agências
  var rows=document.querySelectorAll('#tenantTable tr[data-id]');
  var sel=document.getElementById('iaCreditoAgencia');
  if(rows.length){
    rows.forEach(tr=>{
      var nome=tr.querySelector('td:first-child strong')?.textContent||'';
      var id=tr.dataset.id;
      if(id&&nome){var o=document.createElement('option');o.value=id;o.textContent=nome;sel.appendChild(o);}
    });
  }
}

function iaSetAgencia(id){
  document.getElementById('iaCreditoAgencia').value=id;
  document.getElementById('iaCreditoQtd').focus();
}

function iaAdicionarCreditos(){
  var ag=parseInt(document.getElementById('iaCreditoAgencia').value);
  var qtd=parseInt(document.getElementById('iaCreditoQtd').value);
  var obs=document.getElementById('iaCreditoObs').value.trim();
  var msg=document.getElementById('iaCreditoMsg');
  if(!ag||!qtd||qtd<1){
    msg.textContent='⚠️ Selecione uma agência e informe a quantidade.';
    msg.style.color='#f59e0b';msg.style.display='block';return;
  }
  fetch('ia_analises.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'adicionar_creditos',agencia_id:ag,quantidade:qtd,observacao:obs})})
  .then(r=>r.json())
  .then(data=>{
    msg.textContent=data.ok?'✓ '+qtd+' crédito(s) adicionado(s). Saldo: '+data.saldo_novo:'✗ '+(data.erro||'Erro');
    msg.style.color=data.ok?'var(--success)':'#ef4444';
    msg.style.display='block';
    if(data.ok){document.getElementById('iaCreditoQtd').value='10';document.getElementById('iaCreditoObs').value='';iaCarregarRelatorio();}
    setTimeout(()=>msg.style.display='none',4000);
  });
}
// ── FIM INTELIGÊNCIA IA ──────────────────────────────────────

function switchTab(id,btn){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  btn.classList.add('active');
}

async function post(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))fd.append(k,v);
  const r=await fetch('superadmin.php',{method:'POST',body:fd});
  return r.json();
}

async function acao(action,tenant_id,btn){
  const msg=btn.dataset.confirm;
  if(msg&&!confirm(msg))return;
  const r=await post({action,tenant_id});
  if(r.ok){toast('Ação executada!');setTimeout(()=>location.reload(),900);}
  else toast('Erro: '+(r.msg||'tente novamente'),'error');
}

async function trocarPlano(tenant_id,plano_id){
  await post({action:'plano_change',tenant_id,plano_id});
  toast('Plano atualizado!');
}

let extendId=null;
function openExtendModal(id,nome){
  extendId=id;
  document.getElementById('extend-nome').textContent=nome;
  document.getElementById('extend-dias').value=7;
  document.getElementById('modal-extend').classList.add('open');
}
async function confirmarExtend(){
  const dias=parseInt(document.getElementById('extend-dias').value);
  if(!dias||dias<1)return;
  const r=await post({action:'trial_extend',tenant_id:extendId,dias});
  if(r.ok){toast('Trial estendido!');document.getElementById('modal-extend').classList.remove('open');setTimeout(()=>location.reload(),900);}
}

async function salvarPlano(e,plano_id){
  e.preventDefault();
  const f=e.target;
  const r=await post({action:'plano_update',plano_id,nome:f.nome.value,preco:f.preco.value,descricao:f.descricao?.value||''});
  if(r.ok){toast('Plano salvo!');setTimeout(()=>location.reload(),700);}
  else toast('Erro ao salvar','error');
}

async function togglePlano(plano_id){
  const r=await post({action:'plano_toggle',plano_id});
  if(r.ok){toast('Status alterado');setTimeout(()=>location.reload(),700);}
}

async function criarPlano(e){
  e.preventDefault();
  const f=e.target;
  const r=await post({action:'plano_create',nome:f.nome.value,preco:f.preco.value,descricao:f.descricao.value,limite_clientes:f.limite_clientes.value});
  if(r.ok){toast('Plano criado!');setTimeout(()=>location.reload(),700);}
  else toast('Erro ao criar','error');
}

async function salvarConfig(e){
  e.preventDefault();
  const f=e.target;
  const r=await post({action:'config_save',trial_dias:f.trial_dias.value,desconto_anual:f.desconto_anual.value,whatsapp_suporte:f.whatsapp_suporte.value,email_suporte:f.email_suporte.value});
  if(r.ok)toast('Configurações salvas!');
  else toast('Erro ao salvar','error');
}

function filtrar(){
  const s=document.getElementById('searchTenants').value.toLowerCase();
  const fSt=document.getElementById('filterStatus').value;
  const fPl=document.getElementById('filterPlano').value.toLowerCase();
  document.querySelectorAll('#tenantTable tr').forEach(tr=>{
    const matchS=!s||(tr.dataset.empresa||'').includes(s)||(tr.dataset.email||'').includes(s);
    const matchSt=!fSt||tr.dataset.status===fSt;
    const matchPl=!fPl||(tr.dataset.plano||'').toLowerCase()===fPl;
    tr.style.display=matchS&&matchSt&&matchPl?'':'none';
  });
}

function toast(msg,type=''){
  const c=document.getElementById('toastContainer');
  const t=document.createElement('div');
  t.className='toast'+(type?' '+type:'');
  t.textContent=msg;
  c.appendChild(t);
  setTimeout(()=>t.remove(),3300);
}

(function(){
  const D='logo_studio_dark.png', L='logo_studio_light.png';
  function applyTheme(isLight){
    document.documentElement.classList.toggle('light', isLight);
    var el=document.getElementById('navLogo');
    if(el) el.src = isLight ? L : D;
    var btn=document.getElementById('themeToggle');
    if(btn) btn.textContent = isLight ? '☀️' : '🌙';
    // fix header blur bg for light
    var header=document.querySelector('header');
    if(header) header.style.background = isLight ? 'rgba(244,245,247,.97)' : 'rgba(13,13,13,.95)';
  }
  window.toggleTheme = function(){
    var isLight = !document.documentElement.classList.contains('light');
    localStorage.setItem('opa_theme', isLight ? 'light' : 'dark');
    applyTheme(isLight);
  };
  // Load saved preference, fallback to OS preference
  var saved = localStorage.getItem('opa_theme');
  var isLight = saved ? saved==='light' : window.matchMedia('(prefers-color-scheme:light)').matches;
  document.addEventListener('DOMContentLoaded', function(){ applyTheme(isLight); });
  applyTheme(isLight);
})();
</script>
<script src="menu_lateral.js?v=1" defer></script>
</body>
</html>
