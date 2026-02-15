<?php
// Configurações de CORS para permitir requisições do seu domínio
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$targetUrl = $_GET['url'] ?? '';
if ($targetUrl === '' || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    die('URL inválida');
}

// Inicializa o CURL para buscar a stream original
$ch = curl_init($targetUrl);
$requestHeaders = [];

// Encaminha o cabeçalho "Range" do navegador para o servidor IPTV
// Isso permite que o player pule para diferentes partes do vídeo.
if (!empty($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true, // Segue redirecionamentos do servidor de canais
    CURLOPT_CONNECTTIMEOUT => 15,   // Tempo maior para evitar quedas no login
    CURLOPT_TIMEOUT        => 0,    // Sem limite de tempo para streaming contínuo
    CURLOPT_HTTPHEADER     => $requestHeaders,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_HEADER         => true,
    CURLOPT_ENCODING       => '',   // Suporta compressão GZIP/Brotli se disponível
    CURLOPT_SSL_VERIFYPEER => false // Evita erros de certificados SSL inválidos em listas
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    die('Erro no proxy: ' . curl_error($ch));
}

$statusCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$headerSize   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$rawHeaders   = substr($response, 0, $headerSize);
$body         = substr($response, $headerSize);
curl_close($ch);

http_response_code($statusCode);

// Encaminha apenas os cabeçalhos essenciais para o player
$allowedHeaders = [
    'content-type', 'content-length', 'content-range', 
    'accept-ranges', 'cache-control', 'expires'
];

foreach (explode("\r\n", $rawHeaders) as $line) {
    $parts = explode(':', $line, 2);
    if (count($parts) === 2) {
        $name = strtolower(trim($parts[0]));
        if (in_array($name, $allowedHeaders)) {
            header($line, false);
        }
    }
}

// Lógica de reescrita para arquivos HLS (.m3u8)
$isM3U = stripos($contentType, 'mpegurl') !== false || preg_match('/\.m3u8(\?|$)/i', $targetUrl);

if ($isM3U) {
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    $baseUrl = preg_replace('/[^\/]+(\?.*)?$/', '', $targetUrl);
    $lines = preg_split('/\r\n|\r|\n/', $body);

    foreach ($lines as &$line) {
        $l = trim($line);
        // Se a linha não for um comentário e for um link relativo, transforma em absoluto
        if ($l !== '' && $l[0] !== '#') {
            if (!preg_match('#^https?://#i', $l)) {
                $l = $baseUrl . ltrim($l, '/');
            }
            // Faz com que cada segmento (.ts) também passe pelo proxy para evitar CORS
            $line = 'proxy.php?url=' . rawurlencode($l);
        }
    }
    echo implode("\n", $lines);
    exit;
}

// Entrega o vídeo (.ts ou .mp4) diretamente para o Video.js
echo $body;