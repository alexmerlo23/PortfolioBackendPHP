<?php
declare(strict_types=1);

// src/routes/Contact.php - Single Contact Route

use App\Controllers\ContactController;

// Single contact form submission endpoint
$router->post('/api/contact', 'ContactController@create');

// Handle CORS preflight
$router->options('/api/contact', function() {
    return ['message' => 'CORS preflight OK'];
});

// Simple test endpoint to verify routing works
$router->get('/api/test', function() {
    return [
        'success' => true,
        'message' => 'API is working!',
        'timestamp' => date('c')
    ];
});