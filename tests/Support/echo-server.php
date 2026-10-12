<?php

declare(strict_types=1);

// Router for PHP's built-in server in CurlTransportTest:
//   /status/<code>  answers that status with a JSON body and an x-test header
//   /sleep/<ms>     waits, then answers 200
//   anything else   echoes the method, path, headers and body as JSON

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

if (preg_match('#^/sleep/(\d+)$#', $path, $m) === 1) {
    usleep((int) $m[1] * 1000);
    header('Content-Type: application/json');
    echo '{"slept":true}';
    return;
}

$status = 200;
if (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
    $status = (int) $m[1];
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (is_string($key) && str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

http_response_code($status);
header('Content-Type: application/json');
header('X-Test: yes');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'headers' => $headers,
    'body' => $body,
]);
