<?php
$slug      = basename($_GET['slug'] ?? '');
$cacheFile = __DIR__ . "/cache/{$slug}.cache";
$offlineUrl = "http://home.tvl.ovh:84/offline";

if (empty($slug)) {
    http_response_code(400);
    exit('Brak parametru slug.');
}

$url = trim(@file_get_contents($cacheFile) ?: '');
if (empty($url)) {
    $url = $offlineUrl;
}

header('Content-Type: audio/x-mpegurl');
header('Location: ' . $url);
