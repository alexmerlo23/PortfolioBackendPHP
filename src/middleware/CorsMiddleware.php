<?php
declare(strict_types=1);

namespace App\Middleware;

class CorsMiddleware implements MiddlewareInterface
{
    private array $allowedOrigins;
    private array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
    private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'];

    public function __construct()
    {
        $this->allowedOrigins = array_filter(
            explode(',', $_ENV['ALLOWED_ORIGINS'] ?? ''),
            fn($origin) => !empty(trim($origin))
        );
        
        // Add localhost origins for development
        if ($_ENV['APP_ENV'] === 'development') {
            $this->allowedOrigins = array_merge($this->allowedOrigins, [
                'http://localhost:3000',
                'http://localhost:5173',
                'https://delightful-grass-046395110.6.azurestaticapps.net'
            ]);
        }
    }

    public function handle(array $request): mixed
    {
        $origin = $request['headers']['origin'] ?? '';
        
        // Allow requests with no origin (mobile apps, curl, etc.)
        if (empty($origin)) {
            $this->setBasicCorsHeaders();
            return null;
        }

        // Check if origin is allowed
        if ($this->isOriginAllowed($origin)) {
            $this->setCorsHeaders($origin);
        } else {
            // Log unauthorized origin attempt
            error_log(sprintf(
                '[%s] [CORS] Blocked request from unauthorized origin: %s',
                date('c'),
                $origin
            ));
            
            http_response_code(403);
            return [
                'error' => 'CORS: Origin not allowed',
                'origin' => $origin
            ];
        }

        // Handle preflight OPTIONS requests
        if ($request['method'] === 'OPTIONS') {
            http_response_code(200);
            return [
                'message' => 'CORS preflight successful',
                'allowed_methods' => $this->allowedMethods,
                'allowed_headers' => $this->allowedHeaders
            ];
        }

        return null; // Continue to next middleware/handler
    }

    private function isOriginAllowed(string $origin): bool
    {
        return in_array($origin, $this->allowedOrigins, true);
    }

    private function setCorsHeaders(string $origin): void
    {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: 86400'); // 24 hours
        header('Vary: Origin');
    }

    private function setBasicCorsHeaders(): void
    {
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: 86400');
    }
}