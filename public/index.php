<?php
declare(strict_types=1);

// public/index.php - Application Entry Point

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Application;
use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\SecurityMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use Config\Database;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Error reporting based on environment
if ($_ENV['APP_ENV'] === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Set timezone
date_default_timezone_set('UTC');

try {
    // Initialize database connection
    Database::getInstance();
    
    // Create application instance
    $app = new Application();
    
    // Register global middleware
    $app->addMiddleware(new ErrorHandlerMiddleware());
    $app->addMiddleware(new CorsMiddleware());
    $app->addMiddleware(new SecurityMiddleware());
    $app->addMiddleware(new RateLimitMiddleware());
    
    // Setup routes
    $router = new Router();
    
    // Health check endpoint
    $router->get('/health', function() {
        return [
            'status' => 'OK',
            'timestamp' => date('c'),
            'version' => '1.0.0',
            'php_version' => PHP_VERSION
        ];
    });
    
    // Root endpoint
    $router->get('/', function() {
        return [
            'message' => 'Portfolio Backend API (PHP)',
            'version' => '1.0.0',
            'endpoints' => [
                'health' => '/health',
                'contact' => '/api/contact',
                'contactMessages' => '/api/contact/messages',
                'contactStats' => '/api/contact/stats',
                'contactTest' => '/api/contact/test'
            ]
        ];
    });
    
    // Contact routes
    require_once dirname(__DIR__) . '/routes/contact.php';
    
    // Set router in application
    $app->setRouter($router);
    
    // Run application
    $app->run();
    
} catch (Exception $e) {
    // Emergency error handling
    http_response_code(500);
    header('Content-Type: application/json');
    
    $response = [
        'error' => 'Internal Server Error',
        'message' => $_ENV['APP_ENV'] === 'development' ? $e->getMessage() : 'Something went wrong'
    ];
    
    if ($_ENV['APP_ENV'] === 'development') {
        $response['trace'] = $e->getTraceAsString();
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit(1);
}