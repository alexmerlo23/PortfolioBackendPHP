<?php
declare(strict_types=1);

namespace App\Middleware;

interface MiddlewareInterface
{
    /**
     * Handle the request
     * 
     * @param array $request The request data
     * @return mixed|null Return null to continue, or response to stop processing
     */
    public function handle(array $request): mixed;
}