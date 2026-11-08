<?php
/**
 * Runner da fila de mensagens recebidas do WhatsApp (Vendedor IA).
 * Agende na Hostinger (Avançado → Cron Jobs), a cada minuto:
 *   * * * * * /usr/bin/php /home/SEU_USUARIO/domains/SEU_SITE/public_html/PASTA/cron_wa_inbound.php
 * (o caminho exato aparece em Configurações → WhatsApp). Alternativa via URL:
 *   https://SEU-SITE/PASTA/cron_wa_inbound.php?k=TOKEN_DO_CRON
 *
 *  • loop interno de ~55 s: um disparo por minuto cobre quase o minuto inteiro;
 *  • flock: se já há um runner rodando, o novo sai na hora (mesma mensagem nunca é processada 2×);
 *  • log próprio com fwrite+flush.
 */
declare(ticks=1);
@set_time_limit(0);
define('PRO_SEM_SESSAO', 1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vendedor_ia.php';

$LOGF  = PRO_ROOT . '/logs/wa_inbound.log';
$LOCKF = PRO_ROOT . '/logs/.wa_inbound.lock';
function rlog(string $msg): void {
    global $LOGF;
    $fh = @fopen($LOGF, 'a');
    if ($fh) { @fwrite($fh, date('Y-m-d H:i:s') . ' | CRON | ' . $msg . "\n"); @fflush($fh); @fclose($fh); }
}

$viaCli = (php_sapi_name() === 'cli');
if (!$viaCli) {
    $tok = getPConfig('wa_cron_token');
    if ($tok === '' || !hash_equals($tok, (string)($_GET['k'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
}

$lock = @fopen($LOCKF, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) exit(0);   // runner anterior ainda trabalhando: rotina, não erro

$inicio = time(); $LIMITE = 55; $LOTE = 8; $total = 0;
$db = getDB();
try {
    while ((time() - $inicio) < $LIMITE) {
        $st = $db->prepare("SELECT * FROM pro_wa_fila WHERE status = 'pendente' AND tentativas < 3 ORDER BY id ASC LIMIT {$LOTE}");
        $st->execute();
        $itens = $st->fetchAll();
        if (!$itens) { sleep(3); continue; }
        foreach ($itens as $item) {
            $id = (int)$item['id'];
            // Marca 'processando' ANTES de trabalhar, com guarda de status: se dois runners escaparem do lock, só um pega a linha.
            $up = $db->prepare("UPDATE pro_wa_fila SET status = 'processando', tentativas = tentativas + 1 WHERE id = ? AND status = 'pendente'");
            $up->execute([$id]);
            if ($up->rowCount() === 0) continue;
            $r = waProcessarItem($db, $item);
            $db->prepare('UPDATE pro_wa_fila SET status = ?, erro = ?, processado_em = ? WHERE id = ?')->execute([
                in_array($r['status'], ['ok', 'ignorado'], true) ? $r['status'] : 'pendente',   // erro → volta para a fila (até 3 tentativas)
                $r['status'] === 'erro' ? mb_substr((string)$r['msg'], 0, 255) : null, proAgora(), $id]);
            $total++;
            if ($r['status'] === 'erro') rlog("item {$id} ERRO: " . $r['msg']);
            if ((time() - $inicio) >= $LIMITE) break;
        }
    }
    // Itens que estouraram as 3 tentativas ficam como 'erro'
    $db->exec("UPDATE pro_wa_fila SET status = 'erro' WHERE status = 'pendente' AND tentativas >= 3");
    // Limpeza: fila processada com mais de 30 dias
    $db->prepare("DELETE FROM pro_wa_fila WHERE status IN ('ok','ignorado') AND recebido_em < ?")->execute([date('Y-m-d H:i:s', strtotime('-30 days'))]);
} catch (Throwable $e) {
    rlog('FATAL loop: ' . $e->getMessage());
}
if ($total > 0) rlog("processados={$total}");
@flock($lock, LOCK_UN); @fclose($lock);
if (!$viaCli) echo "ok processados={$total}\n";
exit(0);
