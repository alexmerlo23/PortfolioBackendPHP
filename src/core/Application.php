<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Router;
use App\Middleware\MiddlewareInterface;

class Application
{
    private Router $router;
    private array $middleware = [];
    private array $request;

    public function __construct()
    {
        $this->parseRequest();
    }

    public function setRouter(Router $router): void
    {
        $this->router = $router;
    }

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function run(): void
    {
        try {
            // Execute middleware chain
            $response = $this->executeMiddleware($this->request);
            
            if ($response === null) {
                // No middleware returned early, continue to routing
                $response = $this->router->dispatch(
                    $this->request['method'],
                    $this->request['path']
                );
            }

            $this->sendResponse($response);

        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    private function parseRequest(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $parsedUrl = parse_url($requestUri);
        $path = $parsedUrl['path'] ?? '/';
        
        // Log the original request for debugging
        error_log(sprintf(
            '[%s] [REQUEST] Original URI: %s, Parsed path: %s',
            date('c'),
            $requestUri,
            $path
        ));
        
        $this->request = [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => $path,
            'query' => $_GET,
            'body' => $this->getRequestBody(),
            'headers' => $this->getHeaders(),
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'raw_uri' => $requestUri,
        ];
        
        // Debug log the parsed request
        if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
            error_log('[REQUEST] Parsed request: ' . json_encode([
                'method' => $this->request['method'],
                'path' => $this->request['path'],
                'headers' => array_keys($this->request['headers'])
            ]));
        }
    }

    private function getRequestBody(): array
    {
        $body = file_get_contents('php://input');
        
        if (empty($body)) {
            return $_POST;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($body, true);
            return $decoded ?? [];
        }

        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($body, $parsed);
            return $parsed;
        }

        return [];
    }

    private function getHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($header)] = $value;
            }
        }
        return $headers;
    }

    private function getClientIP(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function executeMiddleware(array $request): mixed
    {
        foreach ($this->middleware as $middleware) {
            $result = $middleware->handle($request);
            if ($result !== null) {
                return $result; // Middleware returned early response
            }
        }
        return null;
    }

    private function sendResponse(mixed $response): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        
        if (is_array($response) || is_object($response)) {
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_string($response)) {
            echo $response;
        } else {
            // Default JSON response
            echo json_encode(['data' => $response], JSON_PRETTY_PRINT);
        }
    }

    private function handleError(\Exception $e): void
    {
        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        $response = [
            'error' => true,
            'message' => $e->getMessage(),
            'timestamp' => date('c')
        ];

        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if ($appEnv !== 'production') {
            $response['debug'] = [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request_info' => [
                    'method' => $this->request['method'] ?? 'UNKNOWN',
                    'path' => $this->request['path'] ?? 'UNKNOWN',
                    'raw_uri' => $this->request['raw_uri'] ?? 'UNKNOWN'
                ]
            ];
        }

        echo json_encode($response, JSON_PRETTY_PRINT);

        // Log error
        error_log(sprintf(
            '[%s] [ERROR] %s in %s:%d - Request: %s %s',
            date('c'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $this->request['method'] ?? 'UNKNOWN',
            $this->request['path'] ?? 'UNKNOWN'
        ));
    }

    public function getRequest(): array
    {
        return $this->request;
    }
}