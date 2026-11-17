<?php
// Erzeugt die App-Icons: php werkzeuge/icons_erzeugen.php
$out = __DIR__ . '/../webspace/assets';
foreach (['icon-512.png' => 512, 'icon-192.png' => 192, 'apple-touch-icon.png' => 180] as $name => $size) {
    $f = 4; $S = $size * $f; // Supersampling für glatte Kanten
    $im = imagecreatetruecolor($S, $S);
    $bg = imagecolorallocate($im, 47, 91, 211);
    imagefill($im, 0, 0, $bg);
    $back = imagecolorallocate($im, 160, 182, 240);
    $front = imagecolorallocate($im, 255, 255, 255);
    $line = imagecolorallocate($im, 47, 91, 211);
    $rr = function ($x1, $y1, $x2, $y2, $r, $c) use ($im) {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $c);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $c);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $c);
        }
    };
    $u = $S / 100;
    // hintere Karte (versetzt), vordere Karte im Telefonformat
    $rr((int)(36 * $u), (int)(14 * $u), (int)(74 * $u), (int)(78 * $u), (int)(6 * $u), $back);
    $rr((int)(26 * $u), (int)(22 * $u), (int)(64 * $u), (int)(86 * $u), (int)(6 * $u), $front);
    // Textzeilen
    foreach ([[34, 56, 36], [34, 50, 46], [34, 56, 56], [34, 46, 66]] as [$x1, $x2, $y]) {
        $rr((int)($x1 * $u), (int)($y * $u), (int)($x2 * $u), (int)(($y + 4) * $u), (int)(2 * $u), $line);
    }
    $dst = imagecreatetruecolor($size, $size);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $size, $size, $S, $S);
    imagepng($dst, "$out/$name");
    echo "$name\n";
}
