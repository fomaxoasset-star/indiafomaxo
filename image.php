<?php
// Small product photos for the Sell screen. Makes a light copy of the fomaxo.in photo once and stores it
// on the server outside public_html (so app updates never wipe it), and lets phones keep it too - so tiles
// show instantly. A stored copy is only remade when the website's photo file changes.
// image.php?k=gold-main2&w=360 (assets/img on fomaxo.in), image.php?k=up/new-perfume-1a2b3c4d.webp (uploaded on fomaxo.in/admin)
require __DIR__ . '/lib/core.php';

$key = (string) ($_GET['k'] ?? '');
$w = (int) ($_GET['w'] ?? 360);
$w = in_array($w, [120, 360, 600], true) ? $w : 360;
if (!preg_match('#^(up/[a-z0-9-]{1,120}\.(webp|jpg)|[a-z0-9_-]{1,150})$#i', $key)) {
    http_response_code(404);
    exit;
}

$cacheDir = dirname(__DIR__) . '/fomaxo-pos-in-thumbs';
$cacheFile = $cacheDir . '/' . str_replace(['/', '.'], ['__', '_'], $key) . '-' . $w . '.webp';

function send_image($file, $type = 'image/webp')
{
    $mtime = filemtime($file);
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=604800');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    $since = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($since && $since >= $mtime) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// The original: read straight from the website folder on the same hosting account, else download it.
$uploaded = strpos($key, 'up/') === 0;
$site = dirname(__DIR__, 2) . '/fomaxo.in';
$local = $uploaded ? $site . '/fomaxo-private/product-images/' . substr($key, 3) : $site . '/public_html/assets/img/' . $key . '.webp';
$srcTime = @filemtime($local);

// The stored copy is good while the website photo has not changed (or, for a downloaded photo, for 30 days).
if (is_file($cacheFile) && ($srcTime ? filemtime($cacheFile) >= $srcTime : filemtime($cacheFile) > time() - 30 * 86400)) {
    send_image($cacheFile);
}

$data = false;
if ($srcTime && @is_readable($local)) {
    $data = @file_get_contents($local);
}
if ($data === false) {
    $ch = curl_init($uploaded ? 'https://fomaxo.in/media/p/' . substr($key, 3) : 'https://fomaxo.in/assets/img/' . $key . '.webp');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'FOMAXO-Store-System-India']);
    $data = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $data = false;
    }
}
if ($data === false || $data === '') {
    http_response_code(404);
    exit;
}

$src = function_exists('imagecreatefromstring') ? @imagecreatefromstring($data) : false;
if (!$src || !function_exists('imagewebp')) {
    // No resizing available: pass the original through.
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=604800');
    echo $data;
    exit;
}
$sw = imagesx($src);
$sh = imagesy($src);
$side = min($sw, $sh);                         // square crop from the centre, like the tiles
$dst = imagecreatetruecolor($w, $w);
imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $side) / 2), (int) (($sh - $side) / 2), $w, $w, $side, $side);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@imagewebp($dst, $tmp, 78) && @rename($tmp, $cacheFile)) {
    send_image($cacheFile);
}
@unlink($tmp);
header('Content-Type: image/webp');
header('Cache-Control: public, max-age=604800');
imagewebp($dst, null, 78);
