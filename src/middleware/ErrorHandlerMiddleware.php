<?php
declare(strict_types=1);

namespace App\Middleware;

class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function handle(array $request): mixed
    {
        // Set up error and exception handlers
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        
        return null; // Continue to next middleware
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Convert errors to exceptions
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(\Throwable $exception): void
    {
        $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
        
        http_response_code($statusCode);
        header('Content-Type: application/json');

        $response = [
            'error' => true,
            'message' => $exception->getMessage(),
            'status_code' => $statusCode,
            'timestamp' => date('c')
        ];

        // Add debug information in development
        if ($_ENV['APP_ENV'] === 'development') {
            $response['debug'] = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        echo json_encode($response, JSON_PRETTY_PRINT);

        // Log the error
        $this->logError($exception);
        
        exit;
    }

    private function logError(\Throwable $exception): void
    {
        $logMessage = sprintf(
            '[%s] [%s] %s in %s:%d' . PHP_EOL . 'Stack trace:' . PHP_EOL . '%s' . PHP_EOL,
            date('c'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        error_log($logMessage);
    }
}