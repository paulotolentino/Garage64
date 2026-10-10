<?php
// Generates the default Open Graph image: php tools/make_og_default.php → assets/img/og-default.png (1200×630).
$W = 1200; $H = 630;
$img = imagecreatetruecolor($W, $H);

$font_black = '/System/Library/Fonts/Supplemental/Arial Black.ttf';
$font_bold  = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';
$font_reg   = '/System/Library/Fonts/Supplemental/Arial.ttf';

$hex = static function ($img, $h) {
    return imagecolorallocate($img, hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
};

$bg      = $hex($img, '0c0e12');
$amber   = $hex($img, 'f5a623');
$amberBr = $hex($img, 'ffc24b');
$ink     = $hex($img, 'f2f4f8');
$inkSec  = $hex($img, 'aeb4c0');
imagefilledrectangle($img, 0, 0, $W, $H, $bg);

// Fine 4px steps keep the corner glow free of visible banding.
$cx = $W - 40; $cy = 20; $rMax = 760;
for ($r = $rMax; $r > 0; $r -= 4) {
    $t = $r / $rMax;
    $alpha = max(28, min(120, (int) (120 - (1 - $t) * 95)));
    $glow = imagecolorallocatealpha($img, 245, 166, 35, $alpha);
    imagefilledellipse($img, $cx, $cy, $r, $r, $glow);
}

imagefilledrectangle($img, 0, 0, $W, 10, $amber);
imagefilledrectangle($img, 90, 250, 104, 380, $amber);

imagettftext($img, 20, 0, 130, 232, $amber, $font_bold, 'A GARAGEM DIGITAL DO COLECIONADOR');
imagettftext($img, 92, 0, 126, 345, $ink, $font_black, 'Garage64');
imagettftext($img, 30, 0, 130, 420, $inkSec, $font_reg, 'Catalogue, organize e compartilhe sua');
imagettftext($img, 30, 0, 130, 468, $inkSec, $font_reg, 'coleção de miniaturas diecast.');
imagettftext($img, 24, 0, 130, 560, $amberBr, $font_bold, 'garage64');

imagepng($img, __DIR__ . '/../assets/img/og-default.png', 9);
echo "og-default.png generated\n";
