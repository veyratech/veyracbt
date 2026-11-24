<?php
/**
 * PWA Icon Generator — membuat icon PNG statis saat pertama diakses
 * Cache ke file agar tidak generate ulang tiap request
 */
$iconDir = __DIR__;
$sizes = [192, 512];

foreach ($sizes as $size) {
    $filePath = "{$iconDir}/icon-{$size}.png";
    if (!file_exists($filePath)) {
        // Generate icon menggunakan GD
        generateIcon($filePath, $size);
    }
}

function generateIcon(string $path, int $size): void {
    $img = imagecreatetruecolor($size, $size);

    // Gradient background (blue sky)
    $topColor    = imagecolorallocate($img, 2, 132, 199);   // #0284c7  blue-600
    $bottomColor = imagecolorallocate($img, 14, 165, 233);  // #0ea5e9  sky-500

    for ($y = 0; $y < $size; $y++) {
        $r = (int)($topColor >> 16 & 0xFF) + (int)(($bottomColor >> 16 & 0xFF) - (int)($topColor >> 16 & 0xFF)) * $y / $size;
        $g = (int)($topColor >> 8 & 0xFF) + (int)(($bottomColor >> 8 & 0xFF) - (int)($topColor >> 8 & 0xFF)) * $y / $size;
        $b = (int)($topColor & 0xFF) + (int)(($bottomColor & 0xFF) - (int)($topColor & 0xFF)) * $y / $size;
        $col = imagecolorallocate($img, $r, $g, $b);
        imageline($img, 0, $y, $size, $y, $col);
    }

    // Round shape (circle)
    $cx = (int)($size / 2);
    $cy = (int)($size / 2);
    $cr = (int)($size * 0.42);

    // White circle background
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledellipse($img, $cx, $cy, $cr * 2, $cr * 2, $white);

    // Text "CBT" centered
    $fontSize = (int)($size / 4.5);
    $text     = 'CBT';
    $bbox     = imageftbbox($fontSize, 0, fontPath(), $text);
    $tw       = $bbox[2] - $bbox[0];
    $th       = $bbox[1] - $bbox[7];
    $tx       = (int)($cx - $tw / 2);
    $ty       = (int)($cy - $th / 2 - $bbox[7]);

    $darkBlue = imagecolorallocate($img, 2, 132, 199); // #0284c7 blue-600
    imagefttext($img, $fontSize, 0, $tx, $ty, $darkBlue, fontPath(), $text);

    imagepng($img, $path);
    imagedestroy($img);
}

function fontPath(): string {
    // Gunakan font system yang tersedia di Windows/XAMPP
    $candidates = [
        __DIR__ . '/../assets/fonts/Inter-Bold.ttf',
        'C:/Windows/Fonts/seguiabd.ttf',
        'C:/Windows/Fonts/ARIALBD.TTF',
        'C:/Windows/Fonts/tahoma.ttf',
    ];
    foreach ($candidates as $f) {
        if (file_exists($f)) return $f;
    }
    return '';
}
