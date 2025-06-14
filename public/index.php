<?php
declare(strict_types=1);

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set JSON header early
header('Content-Type: application/json');

// Debug information
$debug = [
    'step' => 'starting',
    'php_version' => phpversion(),
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'not set',
    'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'not set',
    'working_directory' => getcwd(),
    'include_path' => get_include_path(),
];

try {
    $debug['step'] = 'checking_autoloader';
    
    // Check if autoloader exists
    $autoloaderPath = __DIR__ . '/../vendor/autoload.php';
    $debug['autoloader_path'] = $autoloaderPath;
    $debug['autoloader_exists'] = file_exists($autoloaderPath);
    
    if (!file_exists($autoloaderPath)) {
        throw new Exception('Composer autoloader not found at: ' . $autoloaderPath);
    }
    
    $debug['step'] = 'loading_autoloader';
    require_once $autoloaderPath;
    
    $debug['step'] = 'loading_env';
    
    // Load environment variables
    $envPath = __DIR__ . '/../.env';
    $debug['env_path'] = $envPath;
    $debug['env_exists'] = file_exists($envPath);
    
    if (file_exists($envPath)) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        $debug['env_loaded'] = true;
    }
    
    // Fallback to getenv for Azure App Service environment variables
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    $debug['app_env'] = $appEnv;
    
    $debug['step'] = 'checking_classes';
    
    // Check if our classes exist
    $debug['classes'] = [
        'Application' => class_exists('App\Core\Application'),
        'Router' => class_exists('App\Core\Router'),
        'CorsMiddleware' => class_exists('App\Middleware\CorsMiddleware'),
        'ContactController' => class_exists('App\Controllers\ContactController'),
    ];
    
    $debug['step'] = 'creating_router';
    
    // Create router
    $router = new App\Core\Router();
    
    $debug['step'] = 'loading_routes';
    
    // Load routes from Contact.php
    $routesPath = __DIR__ . '/../src/routes/Contact.php';
    $debug['routes_path'] = $routesPath;
    $debug['routes_exists'] = file_exists($routesPath);
    
    if (file_exists($routesPath)) {
        $routeConfig = require_once $routesPath;
        $debug['routes_loaded'] = true;
        if (is_callable($routeConfig)) {
            $routeConfig($router);
            $debug['routes_configured'] = true;
        } else {
            $debug['routes_configured'] = false;
            error_log('Contact.php did not return a callable');
        }
    } else {
        error_log('Routes file not found: ' . $routesPath);
    }
    
    $debug['step'] = 'adding_root_route';
    
    // Add a root route
    $router->get('/', function() use ($debug) {
        return [
            'success' => true,
            'message' => 'Portfolio API Server',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'debug' => $debug,
            'endpoints' => [
                'GET /' => 'API Status',
                'GET /api/test' => 'Test endpoint',
                'POST /api/contact' => 'Contact form submission',
                'OPTIONS /api/contact' => 'CORS preflight'
            ]
        ];
    });
    
    $debug['step'] = 'creating_application';
    
    // Create application
    $app = new App\Core\Application();
    $app->setRouter($router);
    
    $debug['step'] = 'adding_middleware';
    
    // Add middleware if it exists
    if (class_exists('App\Middleware\CorsMiddleware')) {
        $app->addMiddleware(new App\Middleware\CorsMiddleware());
        $debug['cors_middleware_added'] = true;
    }
    
    $debug['step'] = 'running_application';
    
    // Log registered routes for debugging
    if ($appEnv !== 'production') {
        error_log('[DEBUG] Registered routes: ' . json_encode($router->getRoutes()));
    }
    
    // Run application
    $app->run();
    
} catch (Exception $e) {
    http_response_code(500);
    
    $response = [
        'error' => true,
        'message' => 'Server error: ' . $e->getMessage(),
        'timestamp' => date('c'),
        'debug' => $debug,
        'exception' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
    error_log('[BOOTSTRAP ERROR] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}