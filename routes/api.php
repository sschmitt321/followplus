<?php

use App\Http\Controllers\Api\V1\Admin\AdminDepositController;
use App\Http\Controllers\Api\V1\Admin\AdminFollowController;
use App\Http\Controllers\Api\V1\Admin\AdminReferralController;
use App\Http\Controllers\Api\V1\Admin\AdminSystemController;
use App\Http\Controllers\Api\V1\Admin\AdminWithdrawController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\MarketController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\SwapController;
use App\Http\Controllers\Api\V1\SystemController;
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

        // Market routes
        Route::get('/symbols', [MarketController::class, 'symbols']);
        Route::get('/symbols/{id}/tick', [MarketController::class, 'tick']);
        Route::get('/symbols/{id}/tick-history', [MarketController::class, 'tickHistory']);

        // System routes
        Route::get('/system/announcements', [SystemController::class, 'announcements']);
        Route::get('/system/help', [SystemController::class, 'help']);
        Route::get('/system/version', [SystemController::class, 'version']);
        Route::get('/system/download', [SystemController::class, 'download']);

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

        // Referral routes
        Route::get('/ref/summary', [ReferralController::class, 'summary']);
        Route::get('/ref/rewards', [ReferralController::class, 'rewards']);

        // Follow routes
        Route::get('/follow/windows/available', [FollowController::class, 'availableWindows']);
        Route::post('/follow/order', [FollowController::class, 'placeOrder'])->middleware('idempotency');
        Route::get('/follow/orders', [FollowController::class, 'orders']);
        Route::get('/follow/summary', [FollowController::class, 'summary']);

        // Admin routes (must be admin role)
        Route::prefix('admin')->middleware('admin')->group(function () {
            // Referral admin routes
            Route::post('/ref/level-recalc', [AdminReferralController::class, 'levelRecalc']);
            Route::post('/ref/reward-reverse', [AdminReferralController::class, 'rewardReverse']);

            // Deposit admin routes
            Route::get('/deposits', [AdminDepositController::class, 'index']);
            Route::post('/deposits/{id}/confirm', [AdminDepositController::class, 'confirm']);

            // Withdrawal admin routes
            Route::get('/withdrawals', [AdminWithdrawController::class, 'index']);
            Route::post('/withdrawals/{id}/approve', [AdminWithdrawController::class, 'approve']);
            Route::post('/withdrawals/{id}/reject', [AdminWithdrawController::class, 'reject']);
            Route::post('/withdrawals/{id}/mark-paid', [AdminWithdrawController::class, 'markPaid']);

            // Follow admin routes
            Route::post('/follow-window', [AdminFollowController::class, 'createWindow']);
            Route::post('/invite-token', [AdminFollowController::class, 'createInviteToken']);

            // System admin routes
            Route::post('/system/announcement', [AdminSystemController::class, 'announcement']);
        });
    });
});

