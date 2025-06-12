<?php
declare(strict_types=1);

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load autoloader (adjust path as needed)
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

use App\Core\Application;
use App\Core\Router;
use App\Middleware\CorsMiddleware;

try {
    // Create router
    $router = new Router();
    
    // Load routes - make $router available in the included file
    require_once __DIR__ . '/../src/routes/Contact.php';
    
    // Add a root route to handle the base path
    $router->get('/', function() {
        return [
            'success' => true,
            'message' => 'Portfolio API Server',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'endpoints' => [
                'GET /' => 'API Status',
                'GET /api/test' => 'Test endpoint',
                'POST /api/contact' => 'Contact form submission',
                'OPTIONS /api/contact' => 'CORS preflight'
            ]
        ];
    });
    
    // Create application
    $app = new Application();
    $app->setRouter($router);
    $app->addMiddleware(new CorsMiddleware());
    
    // Run application
    $app->run();
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    
    $response = [
        'error' => true,
        'message' => 'Server error: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    // Add debug info if in development
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    if ($appEnv !== 'production') {
        $response['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
    error_log('[BOOTSTRAP ERROR] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}