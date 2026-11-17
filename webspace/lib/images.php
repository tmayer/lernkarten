<?php
declare(strict_types=1);

const MAX_UPLOAD_BYTES = 30 * 1024 * 1024;
// Begrenzt wird vor allem die Breite: lange Bilder zum Scrollen bleiben so scharf
const MAX_IMAGE_WIDTH = 1400;
const MAX_IMAGE_HEIGHT = 14000;
const THUMB_WIDTH = 400;
const THUMB_MAX_HEIGHT = 712; // Vorschaubilder langer Bilder zeigen nur den oberen Teil
const IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

/**
 * Prüft eine hochgeladene Datei und legt sie unter data/images ab.
 * Mit GD wird das Bild als JPEG neu geschrieben (entfernt Metadaten wie GPS) und ein Vorschaubild erzeugt.
 * @return array{filename:string, thumb:?string, width:?int, height:?int}
 */
function store_uploaded_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload fehlgeschlagen (Fehlercode ' . ($file['error'] ?? '?') . ').');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Ungültiger Upload.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('Datei zu groß (max. 30 MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(IMAGE_TYPES[$mime])) {
        throw new InvalidArgumentException("Dateityp $mime wird nicht unterstützt.");
    }

    $sub = date('Y/m');
    $base = bin2hex(random_bytes(12));
    foreach (["images/$sub", "thumbs/$sub"] as $d) {
        if (!is_dir(DATA_DIR . "/$d")) {
            mkdir(DATA_DIR . "/$d", 0770, true);
        }
    }

    $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring(file_get_contents($file['tmp_name'])) : false;
    if ($img === false) {
        // Ohne GD (oder z. B. HEIC): Datei unverändert speichern
        $rel = "images/$sub/$base." . IMAGE_TYPES[$mime];
        move_uploaded_file($file['tmp_name'], DATA_DIR . "/$rel");
        $size = @getimagesize(DATA_DIR . "/$rel");
        return ['filename' => $rel, 'thumb' => null, 'width' => $size[0] ?? null, 'height' => $size[1] ?? null];
    }

    $img = apply_exif_orientation($img, $file['tmp_name'], $mime);
    $full = resize_to_jpeg($img, MAX_IMAGE_WIDTH, MAX_IMAGE_HEIGHT);
    $thumb = make_thumb($img);
    $w = imagesx($full);
    $h = imagesy($full);

    $rel = "images/$sub/$base.jpg";
    $relThumb = "thumbs/$sub/$base.jpg";
    imagejpeg($full, DATA_DIR . "/$rel", 85);
    imagejpeg($thumb, DATA_DIR . "/$relThumb", 75);
    return ['filename' => $rel, 'thumb' => $relThumb, 'width' => $w, 'height' => $h];
}

function apply_exif_orientation(GdImage $img, string $path, string $mime): GdImage
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $img;
    }
    $o = (int)(@exif_read_data($path)['Orientation'] ?? 1);
    return match ($o) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => $img,
    };
}

function resize_to_jpeg(GdImage $src, int $maxWidth, int $maxHeight, ?int $cropHeight = null): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxWidth / $w, $maxHeight / $h);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $outH = $cropHeight ? min($nh, $cropHeight) : $nh;
    $dst = imagecreatetruecolor($nw, $outH);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // Transparenz → weiß
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);   // unten Überstehendes wird abgeschnitten
    return $dst;
}

function make_thumb(GdImage $src): GdImage
{
    return resize_to_jpeg($src, THUMB_WIDTH, PHP_INT_MAX, THUMB_MAX_HEIGHT);
}

function delete_card_files(array $card): void
{
    foreach ([$card['filename'], $card['thumb']] as $rel) {
        if ($rel && is_file(DATA_DIR . "/$rel")) {
            unlink(DATA_DIR . "/$rel");
        }
    }
}
