<?php

namespace App\Services\Follow;

use App\Models\FollowOrder;
use App\Models\FollowWindow;
use App\Models\InviteToken;
use App\Models\Symbol;
use App\Services\Assets\AssetsService;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class FollowService
{
    public function __construct(
        private LedgerService $ledgerService,
        private AssetsService $assetsService,
        private FollowQuotaService $quotaService
    ) {
    }

    /**
     * Place a follow order.
     */
    public function placeOrder(
        int $userId,
        int $followWindowId,
        int $symbolId,
        string $inviteToken,
        ?Decimal $amountInput = null
    ): FollowOrder {
        return DB::transaction(function () use ($userId, $followWindowId, $symbolId, $inviteToken, $amountInput) {
            // Validate window
            $window = FollowWindow::lockForUpdate()->findOrFail($followWindowId);
            
            if (!$window->isActive()) {
                throw new \Exception('Window is not active');
            }

            if ($window->symbol_id !== $symbolId) {
                throw new \Exception('Symbol mismatch');
            }

            // Validate token
            $token = InviteToken::where('token', $inviteToken)
                ->where('follow_window_id', $followWindowId)
                ->first();

            if (!$token || !$token->isValid()) {
                throw new \Exception('Invalid or expired invite token');
            }

            if ($token->symbol_id !== $symbolId) {
                throw new \Exception('Token symbol mismatch');
            }

            // Check quota
            $date = now()->format('Y-m-d');
            if (!$this->quotaService->hasQuota($userId, $date, $window->window_type)) {
                throw new \Exception('Quota exhausted');
            }

            // Calculate amount_base (1% of total assets)
            $totalBalance = $this->assetsService->getTotalBalance($userId);
            $amountBase = $totalBalance->percentage(1, 6);

            if ($amountBase->isZero()) {
                throw new \Exception('Insufficient balance');
            }

            // Validate amount_input if provided (for audit)
            if ($amountInput && !$amountInput->equals($amountBase)) {
                // Log discrepancy but don't fail
                \Log::warning("Amount input mismatch for user {$userId}: input={$amountInput}, calculated={$amountBase}");
            }

            // Consume quota
            $this->quotaService->consumeQuota($userId, $date, $window->window_type);

            // Create order
            $order = FollowOrder::create([
                'user_id' => $userId,
                'follow_window_id' => $followWindowId,
                'symbol_id' => $symbolId,
                'amount_base' => $amountBase,
                'amount_input' => $amountInput,
                'status' => 'placed',
                'invite_token' => $inviteToken,
            ]);

            return $order;
        });
    }

    /**
     * Settle expired windows (batch process).
     */
    public function settleExpiredWindows(): int
    {
        $now = now();
        
        // Get expired windows that haven't been settled
        $expiredWindows = FollowWindow::where('status', 'active')
            ->where('expire_at', '<=', $now)
            ->get();

        $settledCount = 0;

        foreach ($expiredWindows as $window) {
            DB::transaction(function () use ($window, &$settledCount) {
                // Lock window
                $window = FollowWindow::lockForUpdate()->findOrFail($window->id);
                
                if ($window->status !== 'active') {
                    return; // Already processed
                }

                // Get all placed orders for this window
                $orders = FollowOrder::where('follow_window_id', $window->id)
                    ->where('status', 'placed')
                    ->get();

                foreach ($orders as $order) {
                    // Calculate profit: amount_base × random(reward_rate_min, reward_rate_max)
                    $rate = $this->randomRate($window->reward_rate_min, $window->reward_rate_max);
                    $profit = Decimal::of($order->amount_base)->multiply($rate);

                    // Update order
                    $order->update([
                        'status' => 'settled',
                        'profit' => $profit,
                        'settled_at' => now(),
                    ]);

                    // Credit profit to user's account
                    $this->ledgerService->credit(
                        $order->user_id,
                        'spot',
                        'USDT', // Default currency
                        $profit,
                        'follow_settle',
                        $order->id
                    );
                }

                // Mark window as settled
                $window->update(['status' => 'settled']);
                $settledCount++;
            });
        }

        return $settledCount;
    }

    /**
     * Get available windows for a date.
     */
    public function getAvailableWindows(?string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');
        $startOfDay = \Carbon\Carbon::parse($date)->startOfDay();
        $endOfDay = \Carbon\Carbon::parse($date)->endOfDay();

        $windows = FollowWindow::where('status', 'active')
            ->whereBetween('start_at', [$startOfDay, $endOfDay])
            ->with(['symbol', 'inviteTokens'])
            ->get();

        return $windows->map(function ($window) {
            return [
                'id' => $window->id,
                'symbol' => $window->symbol->name,
                'window_type' => $window->window_type,
                'start_at' => $window->start_at->toIso8601String(),
                'expire_at' => $window->expire_at->toIso8601String(),
                'reward_rate_min' => (float) $window->reward_rate_min,
                'reward_rate_max' => (float) $window->reward_rate_max,
                'invite_tokens' => $window->inviteTokens->map(function ($token) {
                    return [
                        'token' => $token->token,
                        'valid_after' => $token->valid_after->toIso8601String(),
                        'valid_before' => $token->valid_before->toIso8601String(),
                    ];
                }),
            ];
        })->toArray();
    }

    /**
     * Get user's follow summary.
     */
    public function getSummary(int $userId): array
    {
        $orders = FollowOrder::where('user_id', $userId)->get();

        $totalAmount = $orders->reduce(function ($carry, $order) {
            return $carry->add($order->amount_base);
        }, Decimal::zero());

        $totalProfit = $orders->where('status', 'settled')
            ->reduce(function ($carry, $order) {
                return $carry->add($order->profit ?? Decimal::zero());
            }, Decimal::zero());

        $totalOrders = $orders->count();
        $settledOrders = $orders->where('status', 'settled')->count();
        $winRate = $totalOrders > 0 ? ($settledOrders / $totalOrders) * 100 : 0;

        return [
            'total_amount' => $totalAmount->toString(),
            'total_orders' => $totalOrders,
            'settled_orders' => $settledOrders,
            'total_profit' => $totalProfit->toString(),
            'win_rate' => round($winRate, 2),
        ];
    }

    /**
     * Generate random rate between min and max.
     */
    private function randomRate(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}

