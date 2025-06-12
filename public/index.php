<?php
declare(strict_types=1);

// Add cache-busting headers
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

// Unique identifier to prove this version is running
$unique_id = 'DEBUG_' . date('YmdHis') . '_' . uniqid();

echo json_encode([
    'CACHE_BUSTER' => true,
    'unique_id' => $unique_id,
    'message' => 'This should be different every time!',
    'timestamp' => date('c'),
    'microtime' => microtime(true),
    'file_info' => [
        'file' => __FILE__,
        'size' => filesize(__FILE__),
        'modified' => filemtime(__FILE__),
        'modified_readable' => date('Y-m-d H:i:s', filemtime(__FILE__))
    ],
    'request_info' => [
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'not set',
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
        'HTTP_CACHE_CONTROL' => $_SERVER['HTTP_CACHE_CONTROL'] ?? 'not set'
    ]
], JSON_PRETTY_PRINT);

exit();
?>