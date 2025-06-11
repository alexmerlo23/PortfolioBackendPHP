<?php
declare(strict_types=1);

// routes/contact.php - Contact API Routes

use App\Controllers\ContactController;

// Contact form submission
$router->post('/api/contact', 'ContactController@create');

// Get all contact messages (for admin)
$router->get('/api/contact/messages', 'ContactController@getMessages');

// Get contact statistics (for admin)
$router->get('/api/contact/stats', 'ContactController@getStats');

// Get specific contact message (for admin)
$router->get('/api/contact/messages/{id}', 'ContactController@getMessage');

// Test email configuration
$router->get('/api/contact/test', 'ContactController@testEmail');

// Handle OPTIONS requests for CORS
$router->options('/api/contact', function() {
    return ['message' => 'CORS preflight for contact endpoint'];
});

$router->options('/api/contact/messages', function() {
    return ['message' => 'CORS preflight for contact messages endpoint'];
});

$router->options('/api/contact/stats', function() {
    return ['message' => 'CORS preflight for contact stats endpoint'];
});

$router->options('/api/contact/test', function() {
    return ['message' => 'CORS preflight for contact test endpoint'];
});