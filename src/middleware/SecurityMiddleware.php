<?php
declare(strict_types=1);

namespace App\Middleware;

class SecurityMiddleware implements MiddlewareInterface
{
    public function handle(array $request): mixed
    {
        // Set security headers
        $this->setSecurityHeaders();
        
        // Validate request size
        $this->validateRequestSize($request);
        
        // Basic XSS protection
        $this->validateForXSS($request);
        
        return null; // Continue to next middleware
    }

    private function setSecurityHeaders(): void
    {
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // XSS Protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'");
        
        // HSTS (only for HTTPS)
        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        // Remove server signature
        header_remove('X-Powered-By');
        header('Server: Portfolio-API');
    }

    private function validateRequestSize(array $request): void
    {
        $maxSize = 10 * 1024 * 1024; // 10MB
        
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
        
        if ($contentLength > $maxSize) {
            http_response_code(413);
            throw new \Exception('Request payload too large');
        }
    }

    private function validateForXSS(array $request): void
    {
        $suspiciousPatterns = [
            '/<script[^>]*>.*?<\/script>/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe[^>]*>.*?<\/iframe>/i',
            '/data:text\/html/i',
            '/<object[^>]*>.*?<\/object>/i',
            '/<embed[^>]*>/i'
        ];

        // Check all input data
        $allInput = json_encode($request['body']) . json_encode($request['query']);
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $allInput)) {
                error_log(sprintf(
                    '[%s] [SECURITY] XSS attempt detected from IP: %s',
                    date('c'),
                    $request['ip']
                ));
                
                http_response_code(400);
                throw new \Exception('Invalid characters detected in request');
            }
        }
    }

    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['SERVER_PORT'] ?? 80) == 443 ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        );
    }
}