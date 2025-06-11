<?php
declare(strict_types=1);

namespace App\Middleware;

class RateLimitMiddleware implements MiddlewareInterface
{
    private string $storageFile;
    private int $requestLimit;
    private int $timeWindow;
    private int $contactLimit;
    private int $contactWindow;

    public function __construct()
    {
        $this->storageFile = dirname(__DIR__, 2) . '/storage/rate_limits.json';
        $this->requestLimit = (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 100);
        $this->timeWindow = (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 900); // 15 minutes
        $this->contactLimit = (int)($_ENV['CONTACT_RATE_LIMIT'] ?? 5);
        $this->contactWindow = (int)($_ENV['CONTACT_RATE_WINDOW'] ?? 900); // 15 minutes
        
        $this->ensureStorageDirectory();
    }

    public function handle(array $request): mixed
    {
        $clientIP = $request['ip'];
        $currentTime = time();
        
        // Check general rate limit
        if ($this->isRateLimited($clientIP, 'general', $this->requestLimit, $this->timeWindow, $currentTime)) {
            return $this->rateLimitResponse('Too many requests. Please try again later.');
        }

        // Check contact-specific rate limit for contact endpoints
        if ($this->isContactEndpoint($request['path'])) {
            if ($this->isRateLimited($clientIP, 'contact', $this->contactLimit, $this->contactWindow, $currentTime)) {
                return $this->rateLimitResponse('Too many contact requests. Please try again later.');
            }
        }

        // Record the request
        $this->recordRequest($clientIP, 'general', $currentTime);
        
        if ($this->isContactEndpoint($request['path']) && $request['method'] === 'POST') {
            $this->recordRequest($clientIP, 'contact', $currentTime);
        }

        return null; // Continue to next middleware
    }

    private function isContactEndpoint(string $path): bool
    {
        return strpos($path, '/api/contact') === 0;
    }

    private function isRateLimited(string $clientIP, string $type, int $limit, int $window, int $currentTime): bool
    {
        $data = $this->loadRateLimitData();
        $key = "{$clientIP}_{$type}";
        
        if (!isset($data[$key])) {
            return false;
        }

        $requests = $data[$key];
        $validRequests = array_filter($requests, fn($timestamp) => ($currentTime - $timestamp) < $window);

        return count($validRequests) >= $limit;
    }

    private function recordRequest(string $clientIP, string $type, int $timestamp): void
    {
        $data = $this->loadRateLimitData();
        $key = "{$clientIP}_{$type}";
        
        if (!isset($data[$key])) {
            $data[$key] = [];
        }
        
        $data[$key][] = $timestamp;
        
        // Clean old entries
        $window = $type === 'contact' ? $this->contactWindow : $this->timeWindow;
        $data[$key] = array_filter($data[$key], fn($ts) => ($timestamp - $ts) < $window);
        
        $this->saveRateLimitData($data);
    }

    private function loadRateLimitData(): array
    {
        if (!file_exists($this->storageFile)) {
            return [];
        }

        $content = file_get_contents($this->storageFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function saveRateLimitData(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($this->storageFile, $json, LOCK_EX);
    }

    private function rateLimitResponse(string $message): array
    {
        http_response_code(429);
        header('Retry-After: ' . $this->timeWindow);
        
        return [
            'error' => 'Rate limit exceeded',
            'message' => $message,
            'retry_after' => $this->timeWindow
        ];
    }

    private function ensureStorageDirectory(): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}