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
        // Get environment safely
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        $allowedOriginsEnv = $_ENV['ALLOWED_ORIGINS'] ?? getenv('ALLOWED_ORIGINS') ?: '';
        
        $this->allowedOrigins = array_filter(
            explode(',', $allowedOriginsEnv),
            fn($origin) => !empty(trim($origin))
        );
        
        // Add localhost origins for development
        if ($appEnv === 'development') {
            $this->allowedOrigins = array_merge($this->allowedOrigins, [
                'http://localhost:3000',
                'http://localhost:5173'
            ]);
        }
        
        // Always allow your Azure Static Web App in production if ALLOWED_ORIGINS is empty
        if (empty($this->allowedOrigins)) {
            $this->allowedOrigins = [
                'https://polite-sea-0cee84310.6.azurestaticapps.net'
            ];
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
                '[%s] [CORS] Blocked request from unauthorized origin: %s (Allowed: %s)',
                date('c'),
                $origin,
                implode(', ', $this->allowedOrigins)
            ));
            
            http_response_code(403);
            return [
                'error' => 'CORS: Origin not allowed',
                'origin' => $origin,
                'allowed_origins' => $this->allowedOrigins
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
        if (!headers_sent()) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
            header('Access-Control-Max-Age: 86400'); // 24 hours
            header('Vary: Origin');
        }
    }

    private function setBasicCorsHeaders(): void
    {
        if (!headers_sent()) {
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
            header('Access-Control-Max-Age: 86400');
        }
    }
}