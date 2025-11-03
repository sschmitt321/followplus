<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $identifier = $this->resolveRequestIdentifier($request);
        $key = "rate_limit:{$identifier}";

        $attempts = Cache::get($key, 0);

        if ($attempts >= $maxAttempts) {
            return response()->json([
                'error' => 'Too many requests. Please try again later.',
            ], 429);
        }

        // Increment attempts
        Cache::put($key, $attempts + 1, now()->addMinutes($decayMinutes));

        $response = $next($request);

        // Add rate limit headers
        $remaining = max(0, $maxAttempts - ($attempts + 1));
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $remaining);
        $response->headers->set('X-RateLimit-Reset', now()->addMinutes($decayMinutes)->timestamp);

        return $response;
    }

    /**
     * Resolve request identifier for rate limiting.
     */
    private function resolveRequestIdentifier(Request $request): string
    {
        // Use IP + User ID if authenticated
        $ip = $request->ip();
        $userId = auth()->id();

        return $userId ? "user:{$userId}" : "ip:{$ip}";
    }
}
