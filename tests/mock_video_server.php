<?php

declare(strict_types=1);

$headers = getallheaders();
$auth = $headers['Authorization'] ?? '';

if ($auth !== 'Basic ' . base64_encode('testuser:testpass')) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$range = $headers['Range'] ?? null;
$file = __DIR__ . '/fixtures/video.mp4';

if (!file_exists($file)) {
    http_response_code(404);
    echo 'Fixture not found';
    exit;
}

$size = filesize($file);

// Endpoint used to verify low-speed stall detection aborts stuck transfers.
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/slow')) {
    http_response_code(200);
    header('Content-Type: video/mp4');
    header('Content-Length: ' . $size);
    // Send a tiny chunk then sleep long enough to trigger the configured low-speed timeout.
    echo substr(file_get_contents($file, false, null, 0, 16), 0, 16);
    flush();
    sleep(3);
    exit;
}

if ($range !== null && preg_match('/bytes=(\d+)-/', $range, $matches)) {
    $start = (int)$matches[1];
    if ($start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    $length = $size - $start;
    http_response_code(206);
    header('Content-Type: video/mp4');
    header('Content-Length: ' . $length);
    header('Content-Range: bytes ' . $start . '-' . ($size - 1) . '/' . $size);
    echo file_get_contents($file, false, null, $start, $length);
    exit;
}

http_response_code(200);
header('Content-Type: video/mp4');
header('Content-Length: ' . $size);
readfile($file);
