<?php
// proxy.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

if (isset($_GET['url'])) {
    $url = $_GET['url'];
    
    // Simula um navegador real para o servidor IPTV não bloquear
    $options = [
        "http" => [
            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
        ]
    ];
    $context = stream_context_create($options);
    
    // Transmite o conteúdo diretamente para o player
    $stream = fopen($url, 'rb', false, $context);
    if ($stream) {
        // Tenta identificar se é um arquivo de vídeo TS
        header("Content-Type: video/mp2t"); 
        fpassthru($stream);
        fclose($stream);
    }
}
?>