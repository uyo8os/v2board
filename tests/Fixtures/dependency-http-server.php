<?php

// Local fixture only: no requests are forwarded to payment or Telegram services.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
$request = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $_GET,
    'body' => $body,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
];
$captureUrl = 'https://pay.example.test/?capture=' . rawurlencode(base64_encode(json_encode($request)));

header('Content-Type: application/json');

if ($path === '/http-error') {
    http_response_code(503);
    echo json_encode(['message' => 'Fixture unavailable']);
} elseif ($path === '/mapi.php') {
    echo json_encode(['code' => 1, 'qrcode' => $captureUrl]);
} elseif (str_starts_with($path, '/telegram/')) {
    echo json_encode(['ok' => true, 'result' => ['id' => 123, 'request' => $request]]);
} else {
    echo json_encode([
        'status_code' => 200,
        'data' => ['trade_id' => 'fixture-trade', 'payment_url' => $captureUrl],
    ]);
}
