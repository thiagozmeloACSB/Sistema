<?php
/**
 * Gera ícones PWA com as cores da Opa!
 * Acesse uma vez e DELETE este arquivo.
 */
$dir = __DIR__ . '/icons/';
if(!is_dir($dir)) mkdir($dir, 0755, true);

function gerarIcone(string $path, int $size): void {
    $img  = imagecreatetruecolor($size, $size);
    $bg   = imagecolorallocate($img, 13, 13, 13);      // #0d0d0d
    $yel  = imagecolorallocate($img, 240, 192, 0);     // #f0c000 amarelo
    $red  = imagecolorallocate($img, 204, 34, 0);      // #cc2200 vermelho
    $dark = imagecolorallocate($img, 30, 30, 30);

    imagefilledrectangle($img, 0, 0, $size-1, $size-1, $bg);

    // Círculo amarelo central
    $cx = (int)($size * 0.65);
    $cy = (int)($size * 0.62);
    $r  = (int)($size * 0.22);
    imagefilledellipse($img, $cx, $cy, $r*2, $r*2, $yel);

    // Linha vermelha (traço do !)
    $lw = max(2, (int)($size * 0.045));
    $lx = $cx;
    $ly_top = (int)($size * 0.18);
    $ly_bot = (int)($size * 0.40);
    imagesetthickness($img, $lw);
    imageline($img, $lx, $ly_top, $lx, $ly_bot, $red);

    // Texto "opa" simplificado
    $fs = max(2, (int)($size / 10));
    $tx = (int)($size * 0.08);
    $ty = (int)($size * 0.52);
    $white = imagecolorallocate($img, 220, 220, 220);
    imagestring($img, $fs, $tx, $ty, 'opa!', $white);

    imagepng($img, $path);
    imagedestroy($img);
}

gerarIcone($dir.'icon-192.png', 192);
gerarIcone($dir.'icon-512.png', 512);
echo '<pre>✓ Ícones gerados com as cores da Opa!
icon-192.png e icon-512.png criados em /icons/

APAGUE ESTE ARQUIVO AGORA.</pre>';
