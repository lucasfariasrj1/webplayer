<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$targetUrl = $_GET['url'] ?? '';
if ($targetUrl === '' || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'URL inválida';
    exit;
}

$ch = curl_init($targetUrl);
$requestHeaders = [];

if (!empty($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    CURLOPT_HEADER => true,
    CURLOPT_ENCODING => ''
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Erro no proxy: ' . curl_error($ch);
    curl_close($ch);
    exit;
}

$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 200;
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$rawHeaders = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
curl_close($ch);

http_response_code($statusCode);

$allowedHeaders = ['content-range', 'accept-ranges', 'content-length', 'cache-control', 'expires', 'last-modified'];
foreach (explode("\r\n", $rawHeaders) as $line) {
    $parts = explode(':', $line, 2);
    if (count($parts) !== 2) {
        continue;
    }

    $name = strtolower(trim($parts[0]));
    $value = trim($parts[1]);

    if (in_array($name, $allowedHeaders, true)) {
        header($parts[0] . ': ' . $value, false);
    }
}

$isM3U = stripos($contentType, 'application/vnd.apple.mpegurl') !== false
    || stripos($contentType, 'application/x-mpegurl') !== false
    || preg_match('/\.m3u8(\?|$)/i', $targetUrl) === 1;

if ($isM3U) {
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    $baseUrl = preg_replace('/[^\/]+(\?.*)?$/', '', $targetUrl);
    $playlistLines = preg_split('/\r\n|\r|\n/', $body);

    foreach ($playlistLines as &$playlistLine) {
        $line = trim($playlistLine);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (!preg_match('#^https?://#i', $line)) {
            $line = ltrim($line, '/');
            $line = $baseUrl . $line;
        }

        $playlistLine = 'proxy.php?url=' . rawurlencode($line);
    }
    unset($playlistLine);

    echo implode("\n", $playlistLines);
    exit;
}

header('Content-Type: ' . $contentType);
echo $body;
