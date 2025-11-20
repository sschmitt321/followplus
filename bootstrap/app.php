<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotency' => \App\Http\Middleware\IdempotencyMiddleware::class,
            'rate.limit' => \App\Http\Middleware\RateLimitMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle authentication exceptions for API routes
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Authentication required',
                ], 401);
            }
        });

        // Handle route not found exceptions for API routes
        $exceptions->render(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'Route not found',
                    'message' => $e->getMessage(),
                ], 404);
            }
        });
    })->create();
