<?php
// Simple CORS-free proxy for URL Page Replicator AJAX endpoints
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$request_uri = $_SERVER['REQUEST_URI'];
$pos = strpos($request_uri, 'endpoint=');
if ($pos === false) {
    echo json_encode(['error' => 'Endpoint missing']);
    exit;
}

$endpoint = substr($request_uri, $pos + 9);
if (empty($endpoint)) {
    echo json_encode(['error' => 'Endpoint empty']);
    exit;
}

// Get dynamic host
$host = 'www.apple.com';
if (isset($_GET['host'])) {
    $raw_host = $_GET['host'];
    // Validate host is a valid domain name
    if (preg_match('/^[a-zA-Z0-9\-\.]+(?::\d+)?$/', $raw_host)) {
        $host = $raw_host;
    }
}

// Build final target URL
$target_url = 'https://' . $host . '/' . ltrim($endpoint, '/');

// Fetch using curl
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 && !empty($response)) {
    // Rewrite all navigation URLs in the dynamically loaded JSON to '#'
    $response = preg_replace('/"url"\s*:\s*"[^"]*"/', '"url":"#"', $response);
}

http_response_code($http_code);
echo $response;
