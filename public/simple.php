<?php
header('Content-Type: application/json');
echo json_encode([
    'message' => 'PHP is working!',
    'timestamp' => date('c'),
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set'
]);
?>