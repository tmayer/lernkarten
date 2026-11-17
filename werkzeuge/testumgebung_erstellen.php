<?php
/**
 * Legt eine lokale Testumgebung mit Beispielkarten an (getrennt von webspace/data).
 * Aufruf: php werkzeuge/testumgebung_erstellen.php
 * Danach:  ./werkzeuge/testserver.sh   →  Passwort: test1234
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$env = "$root/testumgebung";
if (is_dir($env)) {
    exec('rm -rf ' . escapeshellarg($env));
}
mkdir("$env/data", 0770, true);
file_put_contents("$env/config.php", "<?php\n// Nur für lokale Tests – Passwort: test1234\nreturn ['password_hash' => " .
    var_export(password_hash('test1234', PASSWORD_DEFAULT), true) . ", 'timezone' => 'Europe/Berlin'];\n");
putenv("LK_CONFIG=$env/config.php");
putenv("LK_DATA_DIR=$env/data");
require "$root/webspace/lib/bootstrap.php";

const FONT = '/System/Library/Fonts/Supplemental/Arial.ttf';
const FONT_BOLD = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';

$cards = [
    ['Spanisch', [47, 91, 211], 'Tiere', [
        ['el perro', 'der Hund'], ['el gato', 'die Katze'], ['el pájaro', 'der Vogel'],
        ['el caballo', 'das Pferd'], ['la vaca', 'die Kuh'], ['el pez', 'der Fisch'],
    ], 'Merke: Artikel el = männlich, la = weiblich'],
    ['Spanisch', [47, 91, 211], 'ser oder estar?', [
        ['ser', 'dauerhaft: Herkunft, Beruf, Charakter'], ['estar', 'vorübergehend: Ort, Zustand, Gefühl'],
        ['Soy alemán.', 'Ich bin Deutscher.'], ['Estoy cansado.', 'Ich bin müde.'],
    ], 'Eselsbrücke: Wie du dich fühlst und wo du bist, nimmst du estar.'],
    ['Statistik', [47, 138, 76], 'Median vs. Mittelwert', [
        ['Mittelwert', 'Summe ÷ Anzahl'], ['Median', 'der Wert in der Mitte der sortierten Liste'],
        ['Beispiel', '1, 2, 3, 4, 100'], ['→ Mittelwert', '22'], ['→ Median', '3'],
    ], 'Der Median ist robust gegen Ausreißer.'],
    ['Statistik', [47, 138, 76], 'Standardabweichung', [
        ['σ', 'wie stark Werte um den Mittelwert streuen'], ['≈ 68 %', 'liegen innerhalb ±1 σ'],
        ['≈ 95 %', 'liegen innerhalb ±2 σ'], ['≈ 99,7 %', 'liegen innerhalb ±3 σ'],
    ], 'Gilt für die Normalverteilung („68-95-99,7-Regel“).'],
    ['Kochen', [183, 121, 31], 'Garstufen Rindersteak', [
        ['Rare', '48–52 °C Kerntemperatur'], ['Medium rare', '54–57 °C'], ['Medium', '58–62 °C'],
        ['Well done', 'ab 68 °C'],
    ], 'Nach dem Braten 5 Minuten ruhen lassen: Die Temperatur steigt noch um 2–3 °C.'],
    ['Kochen', [183, 121, 31], 'Maillard-Reaktion', [
        ['Was', 'Bräunung von Eiweiß und Zucker'], ['Ab', 'ca. 140 °C'], ['Ergebnis', 'Röstaromen, Kruste'],
        ['Tipp', 'Fleisch trocken tupfen, Pfanne nicht überfüllen'],
    ], 'Wasser verhindert die Bräunung, weil es nicht heißer als 100 °C wird.'],
];

// Lernverlauf: [Tage zurück, Bewertungen] – die Karte springt nach jeder Bewertung zum nächsten Termin
$histories = [
    [40, [3, 3, 3, 3, 3]],       // gut gelernt, langer Abstand
    [20, [3, 3, 1, 3, 2, 1, 3]], // oft vergessen
    [9, [3, 3, 3]],              // wahrscheinlich heute fällig
    [5, [3, 4]],
    null,                        // neu
    null,                        // neu
];

function make_image(string $tag, array $rgb, string $title, array $rows, string $tip): GdImage
{
    [$W, $H] = [1179, 2556];
    $im = imagecreatetruecolor($W, $H);
    $bg = imagecolorallocate($im, 250, 249, 246);
    $ink = imagecolorallocate($im, 29, 29, 31);
    $muted = imagecolorallocate($im, 110, 110, 115);
    $accent = imagecolorallocate($im, ...$rgb);
    $soft = imagecolorallocate($im, (int)(255 - (255 - $rgb[0]) * .12), (int)(255 - (255 - $rgb[1]) * .12), (int)(255 - (255 - $rgb[2]) * .12));
    $line = imagecolorallocate($im, 225, 223, 218);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $bg);

    // Kopf
    imagefilledrectangle($im, 0, 0, $W, 520, $accent);
    imagettftext($im, 44, 0, 90, 260, $white, FONT_BOLD, mb_strtoupper($tag));
    $size = 84;
    while ($size > 40 && (imagettfbbox($size, 0, FONT_BOLD, $title)[2] > $W - 180)) {
        $size -= 2;
    }
    imagettftext($im, $size, 0, 90, 420, $white, FONT_BOLD, $title);

    // Zeilen
    $y = 680;
    foreach ($rows as [$a, $b]) {
        imagettftext($im, 58, 0, 90, $y, $ink, FONT_BOLD, $a);
        $y += 86;
        foreach (wrap($b, 52, $W - 180) as $l) {
            imagettftext($im, 52, 0, 90, $y, $muted, FONT, $l);
            $y += 72;
        }
        $y += 30;
        imagefilledrectangle($im, 90, $y - 40, $W - 90, $y - 38, $line);
        $y += 60;
    }

    // Merkkasten
    $lines = wrap($tip, 50, $W - 260);
    $boxH = 80 + count($lines) * 72 + 40;
    $y += 20;
    imagefilledrectangle($im, 90, $y, $W - 90, $y + $boxH, $soft);
    imagefilledrectangle($im, 90, $y, 104, $y + $boxH, $accent);
    $ty = $y + 100;
    foreach ($lines as $l) {
        imagettftext($im, 50, 0, 150, $ty, $ink, FONT, $l);
        $ty += 72;
    }
    return $im;
}

function wrap(string $text, int $size, int $maxWidth): array
{
    $out = [];
    $cur = '';
    foreach (explode(' ', $text) as $word) {
        $try = $cur === '' ? $word : "$cur $word";
        $box = imagettfbbox($size, 0, FONT, $try);
        if ($box[2] - $box[0] > $maxWidth && $cur !== '') {
            $out[] = $cur;
            $cur = $word;
        } else {
            $cur = $try;
        }
    }
    $out[] = $cur;
    return $out;
}

$now = time();
mkdir(DATA_DIR . '/images/demo', 0770, true);
mkdir(DATA_DIR . '/thumbs/demo', 0770, true);
$examples = "$root/testumgebung/beispielbilder";
mkdir($examples);

foreach ($cards as $i => [$tag, $rgb, $title, $rows, $tip]) {
    $im = make_image($tag, $rgb, $title, $rows, $tip);
    $name = sprintf('karte%02d.jpg', $i + 1);
    imagejpeg($im, DATA_DIR . "/images/demo/$name", 88);
    imagejpeg(make_thumb($im), DATA_DIR . "/thumbs/demo/$name", 75);
    imagejpeg($im, "$examples/$name", 88); // zum Selbst-Hochladen ausprobieren

    $hist = $histories[$i];
    $uploaded = $hist ? $now - $hist[0] * 86400 : $now - 3600;
    db()->prepare('INSERT INTO cards (filename, thumb, width, height, title, uploaded_at, due_at) VALUES (?,?,?,?,?,?,?)')
        ->execute(["images/demo/$name", "thumbs/demo/$name", 1179, 2556, $title, $uploaded, $uploaded]);
    $id = (int)db()->lastInsertId();
    db()->prepare('INSERT OR IGNORE INTO tags (name) VALUES (?)')->execute([$tag]);
    db()->prepare('INSERT INTO card_tags (card_id, tag_id) SELECT ?, id FROM tags WHERE name = ?')->execute([$id, $tag]);

    if ($hist) {
        $t = $uploaded;
        foreach ($hist[1] as $g) {
            if ($t > $now) {
                break;
            }
            $n = apply_review($id, $g, random_int(3000, 15000), $t);
            $t = max($t + 60, $n['due_at']) + random_int(0, 3600);
        }
    }
    $c = get_card_row($id);
    printf("%-24s %-10s fällig %s\n", $title, $c['state'], date('d.m. H:i', (int)$c['due_at']));
}
echo "\nTestumgebung: $env\nBeispielbilder zum Hochladen: $examples\n";
