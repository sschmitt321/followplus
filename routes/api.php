<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SystemConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['rate.limit'])->group(function () {
    // Public routes
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('idempotency');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('idempotency');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('idempotency');

    // Protected routes
    Route::middleware(['auth:api'])->group(function () {
        Route::get('/me', [MeController::class, 'index']);

        // KYC routes
        Route::get('/kyc/status', [KycController::class, 'status']);
        Route::post('/kyc/basic', [KycController::class, 'submitBasic'])->middleware('idempotency');
        Route::post('/kyc/advanced', [KycController::class, 'submitAdvanced'])->middleware('idempotency');

        // System config routes (read-only)
        Route::get('/system/configs', [SystemConfigController::class, 'index']);
    });
});

