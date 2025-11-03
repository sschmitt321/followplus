<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only check idempotency for POST, PUT, PATCH, DELETE requests
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json([
                'error' => 'Idempotency-Key header is required',
            ], 400);
        }

        // Check if this key was already processed
        $cacheKey = "idempotency:{$idempotencyKey}";
        $cachedResponse = Cache::get($cacheKey);

        if ($cachedResponse) {
            // Return cached response
            return response()->json(
                json_decode($cachedResponse['body'], true),
                $cachedResponse['status'],
                $cachedResponse['headers']
            );
        }

        // Process request
        $response = $next($request);

        // Cache response for 24 hours
        if ($response->getStatusCode() < 400) {
            Cache::put($cacheKey, [
                'body' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
            ], now()->addHours(24));
        }

        return $response;
    }
}
