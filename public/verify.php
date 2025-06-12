<?php
header('Content-Type: application/json');

// Check what files exist and their modification times
$files = [
    'index.php' => __DIR__ . '/index.php',
    'simple.php' => __DIR__ . '/simple.php',
    'verify.php' => __FILE__
];

$info = [];
foreach ($files as $name => $path) {
    if (file_exists($path)) {
        $info[$name] = [
            'exists' => true,
            'size' => filesize($path),
            'modified' => filemtime($path),
            'modified_readable' => date('Y-m-d H:i:s', filemtime($path)),
            'first_100_chars' => substr(file_get_contents($path), 0, 100)
        ];
    } else {
        $info[$name] = ['exists' => false];
    }
}

echo json_encode([
    'message' => 'File verification',
    'timestamp' => date('c'),
    'current_time' => time(),
    'files' => $info,
    'working_directory' => getcwd(),
    'php_self' => $_SERVER['PHP_SELF'] ?? 'not set'
], JSON_PRETTY_PRINT);
?>