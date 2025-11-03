<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SwapController;
use App\Http\Controllers\Api\V1\SystemConfigController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\WalletsController;
use App\Http\Controllers\Api\V1\WithdrawController;
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

        // Wallet routes
        Route::get('/wallets', [WalletsController::class, 'index']);

        // Deposit routes
        Route::get('/deposits', [DepositController::class, 'index']);
        Route::post('/deposits/manual-apply', [DepositController::class, 'manualApply'])->middleware('idempotency');

        // Withdrawal routes
        Route::get('/withdrawals', [WithdrawController::class, 'index']);
        Route::get('/withdrawals/calc-withdrawable', [WithdrawController::class, 'calcWithdrawable']);
        Route::post('/withdrawals/apply', [WithdrawController::class, 'apply'])->middleware('idempotency');

        // Transfer routes
        Route::post('/transfer', [TransferController::class, 'transfer'])->middleware('idempotency');

        // Swap routes
        Route::post('/swap/quote', [SwapController::class, 'quote']);
        Route::post('/swap/confirm', [SwapController::class, 'confirm'])->middleware('idempotency');
    });
});

