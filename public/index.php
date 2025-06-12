<?php

declare(strict_types=1);

// IMMEDIATE OUTPUT - This should run before anything else
echo "STARTING DEBUG - " . date('c') . "\n";
flush();

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set JSON header
header('Content-Type: application/json');

// Immediate response to prove this file is running
$response = [
    'FORCE_DEBUG' => true,
    'message' => 'This is the debug version running!',
    'timestamp' => date('c'),
    'file' => __FILE__,
    'line' => __LINE__,
    'request_info' => [
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'not set',
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'not set',
        'PHP_SELF' => $_SERVER['PHP_SELF'] ?? 'not set'
    ]
];

// Output and exit immediately - don't try to load anything else
echo json_encode($response, JSON_PRETTY_PRINT);
exit('DEBUG VERSION COMPLETED');
?>