<?php
declare(strict_types=1);

namespace App\Core;

use Exception;

class Router
{
    private array $routes = [];
    private array $middleware = [];

    public function get(string $path, callable|string $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|string $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable|string $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable|string $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function options(string $path, callable|string $handler): void
    {
        $this->addRoute('OPTIONS', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable|string $handler): void
    {
        // Normalize the path
        $path = $this->normalizePath($path);
        $this->routes[$method][$path] = $handler;
    }

    private function normalizePath(string $path): string
    {
        // Remove trailing slashes except for root
        $path = rtrim($path, '/');
        // Ensure path starts with /
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path === '' ? '/' : $path;
    }

    public function group(string $prefix, callable $callback): void
    {
        $originalRoutes = $this->routes;
        $this->routes = [];
        
        // Execute the callback to register routes
        $callback($this);
        
        // Merge routes with prefix
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $path => $handler) {
                $prefixedPath = $this->normalizePath($prefix . $path);
                $originalRoutes[$method][$prefixedPath] = $handler;
            }
        }
        
        $this->routes = $originalRoutes;
    }

    public function addMiddleware(string $path, callable $middleware): void
    {
        $this->middleware[$path][] = $middleware;
    }

    public function dispatch(string $method, string $path): mixed
    {
        // Normalize the incoming path
        $path = $this->normalizePath($path);
        
        // Log the dispatch attempt for debugging
        error_log(sprintf(
            '[%s] [ROUTER] Dispatching: %s %s',
            date('c'),
            $method,
            $path
        ));
        
        // Debug: Log all available routes
        if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
            error_log('[ROUTER] Available routes: ' . json_encode($this->routes));
        }

        // Check if route exists for exact path
        if (isset($this->routes[$method][$path])) {
            error_log("[ROUTER] Found exact match for: {$method} {$path}");
            return $this->executeRoute($this->routes[$method][$path], []);
        }

        // Check for parameterized routes
        foreach ($this->routes[$method] ?? [] as $routePath => $handler) {
            $params = $this->matchRoute($routePath, $path);
            if ($params !== false) {
                error_log("[ROUTER] Found parameterized match: {$routePath} for {$path}");
                return $this->executeRoute($handler, $params);
            }
        }

        // Handle OPTIONS requests for CORS
        if ($method === 'OPTIONS') {
            return $this->handleOptionsRequest($path);
        }

        // Log the 404 error with available routes
        error_log(sprintf(
            '[%s] [ROUTER] 404 - Route not found: %s %s. Available %s routes: %s',
            date('c'),
            $method,
            $path,
            $method,
            json_encode(array_keys($this->routes[$method] ?? []))
        ));

        throw new NotFoundException("Route not found: {$method} {$path}");
    }

    private function matchRoute(string $routePath, string $requestPath): array|false
    {
        // Convert route path to regex pattern
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $routePath);
        $pattern = '#^' . str_replace('/', '\/', $pattern) . '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            array_shift($matches); // Remove full match
            return $matches;
        }

        return false;
    }

    private function executeRoute(callable|string $handler, array $params): mixed
    {
        if (is_string($handler)) {
            // Handle controller@method format
            if (strpos($handler, '@') !== false) {
                [$controllerName, $methodName] = explode('@', $handler);
                
                $controllerClass = "App\\Controllers\\{$controllerName}";
                if (!class_exists($controllerClass)) {
                    throw new Exception("Controller not found: {$controllerClass}");
                }
                
                $controller = new $controllerClass();
                if (!method_exists($controller, $methodName)) {
                    throw new Exception("Method not found: {$controllerClass}::{$methodName}");
                }
                
                return call_user_func_array([$controller, $methodName], $params);
            }
            
            throw new Exception("Invalid handler format: {$handler}");
        }

        return call_user_func_array($handler, $params);
    }

    private function handleOptionsRequest(string $path): array
    {
        $allowedMethods = ['OPTIONS'];
        
        foreach ($this->routes as $method => $routes) {
            if (isset($routes[$path])) {
                $allowedMethods[] = $method;
            }
        }

        if (!headers_sent()) {
            header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
        }

        return [
            'message' => 'CORS preflight',
            'allowed_methods' => $allowedMethods,
            'path' => $path
        ];
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
}

class NotFoundException extends Exception
{
    public function getStatusCode(): int
    {
        return 404;
    }
}