<?php

namespace App\Services\Follow;

use App\Models\Deposit;
use App\Models\FollowBonusWindow;
use App\Models\FollowOrder;
use App\Models\FollowWindow;
use App\Models\InviteToken;
use App\Models\RefStat;
use App\Models\Symbol;
use App\Models\User;
use App\Services\Assets\AssetsService;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use App\Support\TimeHelper;
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
                $now = TimeHelper::now();
                $reason = [];
                
                if ($window->status !== 'active') {
                    $reason[] = "status is '{$window->status}' (expected 'active')";
                }
                
                // Convert window times to UTC+8 for comparison
                $startAtUtc8 = $window->start_at->setTimezone('Asia/Shanghai');
                $expireAtUtc8 = $window->expire_at->setTimezone('Asia/Shanghai');
                
                if ($now->lt($startAtUtc8)) {
                    $reason[] = "current time ({$now->format('Y-m-d H:i:s')}) is before start time ({$startAtUtc8->format('Y-m-d H:i:s')})";
                }
                
                if ($now->gt($expireAtUtc8)) {
                    $reason[] = "current time ({$now->format('Y-m-d H:i:s')}) is after expire time ({$expireAtUtc8->format('Y-m-d H:i:s')})";
                }
                
                $message = 'Window is not active';
                if (!empty($reason)) {
                    $message .= ': ' . implode(', ', $reason);
                }
                
                throw new \Exception($message);
            }

            if ($window->symbol_id !== $symbolId) {
                throw new \Exception('Symbol mismatch');
            }

            // Check user balance first (required for all window types)
            $totalBalance = $this->assetsService->getTotalBalance($userId);
            if ($totalBalance->isZero()) {
                throw new \Exception('Insufficient balance: Account balance is zero');
            }

            // Check user permission for window type
            if (!$this->canUserParticipate($userId, $window->window_type)) {
                // Get detailed reason for better error message
                $user = User::findOrFail($userId);
                $reason = $this->getPermissionDeniedReason($user, $window->window_type);
                throw new \Exception("User does not have permission to participate in this window type: {$reason}");
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

            // Check if user has already used this invite token
            $existingOrder = FollowOrder::where('user_id', $userId)
                ->where('invite_token', $inviteToken)
                ->first();

            if ($existingOrder) {
                throw new \Exception('This invite token has already been used by you');
            }

            // Check quota
            $date = TimeHelper::now()->format('Y-m-d');
            if (!$this->quotaService->hasQuota($userId, $date, $window->window_type)) {
                throw new \Exception('Quota exhausted');
            }

            // Calculate amount_base (1% of total assets)
            // Note: totalBalance was already checked above, reuse it
            $amountBase = $totalBalance->percentage(1, 6);

            if ($amountBase->isZero()) {
                throw new \Exception('Insufficient balance');
            }

            // Validate amount_input if provided
            // If amount_input doesn't match 1% of total assets, use calculated amount_base instead
            if ($amountInput) {
                if (!$amountInput->equals($amountBase)) {
                    // Log discrepancy and use calculated amount
                    \Log::warning("Amount input mismatch for user {$userId}: input={$amountInput}, calculated={$amountBase}. Using calculated amount.");
                    // Use calculated amount_base instead of user input
                    $amountInput = null; // Clear invalid input, will use amount_base
                }
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
        $now = TimeHelper::now();
        // Convert to UTC for database comparison
        $nowUtc = $now->utc();
        
        // Get expired windows that haven't been settled
        $expiredWindows = FollowWindow::where('status', 'active')
            ->where('expire_at', '<=', $nowUtc)
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
                        'settled_at' => TimeHelper::now()->utc(),
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
    public function getAvailableWindows(?string $date = null, ?int $userId = null): array
    {
        $date = $date ?? TimeHelper::now()->format('Y-m-d');
        // Parse date in UTC+8 timezone
        $startOfDay = TimeHelper::parse($date)->startOfDay()->utc();
        $endOfDay = TimeHelper::parse($date)->endOfDay()->utc();

        $windows = FollowWindow::where('status', 'active')
            ->whereBetween('start_at', [$startOfDay, $endOfDay])
            ->with(['symbol', 'inviteTokens'])
            ->get();

        // Filter windows by user permission if userId is provided
        if ($userId !== null) {
            $windows = $windows->filter(function ($window) use ($userId) {
                return $this->canUserParticipate($userId, $window->window_type);
            });
        }

        return $windows->map(function ($window) use ($userId) {
            $canParticipate = $userId !== null 
                ? $this->canUserParticipate($userId, $window->window_type)
                : true;
            
            $quotaInfo = $userId !== null
                ? $this->quotaService->getRemainingQuota($userId, $date, $window->window_type)
                : null;

            return [
                'id' => $window->id,
                'symbol' => $window->symbol->name,
                'window_type' => $window->window_type,
                'start_at' => $window->start_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'expire_at' => $window->expire_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'reward_rate_min' => (float) $window->reward_rate_min,
                'reward_rate_max' => (float) $window->reward_rate_max,
                'can_participate' => $canParticipate,
                'quota' => $quotaInfo,
                'invite_tokens' => $window->inviteTokens->map(function ($token) {
                    return [
                        'token' => $token->token,
                        'valid_after' => $token->valid_after->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                        'valid_before' => $token->valid_before->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
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
     * Check if user can participate in a specific window type.
     * 
     * Rules:
     * - User must have balance (total assets > 0)
     * - fixed_daily: All users can participate (if they have balance)
     * - newbie_bonus: Only newbies (registered within 7 days) can participate
     * - inviter_bonus: Only inviters with ratio >= 30% can participate
     */
    public function canUserParticipate(int $userId, string $windowType): bool
    {
        // Check if user has balance first (required for all window types)
        $totalBalance = $this->assetsService->getTotalBalance($userId);
        if ($totalBalance->isZero()) {
            return false; // No balance, cannot participate
        }

        // fixed_daily: All users can participate (if they have balance)
        if ($windowType === 'fixed_daily') {
            return true;
        }

        $user = User::findOrFail($userId);

        // newbie_bonus: Check if user is newbie (registered within 7 days)
        if ($windowType === 'newbie_bonus') {
            return $this->isNewbie($user);
        }

        // inviter_bonus: Check if user has inviter ratio >= 30%
        if ($windowType === 'inviter_bonus') {
            return $this->hasInviterRatio30Pct($user);
        }

        return false;
    }

    /**
     * Check if user is newbie (registered within 7 days).
     */
    private function isNewbie(User $user): bool
    {
        if (!$user->first_joined_at) {
            return true; // No join date, treat as newbie
        }

        $daysSinceJoin = $user->first_joined_at->setTimezone('Asia/Shanghai')->diffInDays(TimeHelper::now());
        
        // Newbie bonus windows: days 2-6 are eligible
        // diffInDays: 0=day1, 1=day2, 2=day3, ..., 5=day6, 6=day7
        // So days 2-6 means: daysSinceJoin >= 1 && daysSinceJoin <= 5
        return $daysSinceJoin >= 1 && $daysSinceJoin <= 5;
    }

    /**
     * Check if user has inviter ratio >= 30%.
     * 
     * Inviter ratio = 直接邀请的新人充值总额 / 邀请者当前总资金
     * If inviter total balance is 0, ratio is 0 (not eligible)
     * 
     * If ratio >= 30%, automatically grant 2 days bonus window with 4 extra quota per day.
     */
    private function hasInviterRatio30Pct(User $user): bool
    {
        // Get inviter's total balance
        $inviterTotalBalance = $this->assetsService->getTotalBalance($user->id);
        
        if ($inviterTotalBalance->isZero()) {
            return false; // No balance, cannot calculate ratio
        }

        // Get total deposit amount from directly invited users
        $directInvitedUserIds = User::where('invited_by_user_id', $user->id)
            ->pluck('id')
            ->toArray();
        
        if (empty($directInvitedUserIds)) {
            return false; // No direct invites
        }

        // Calculate total deposit amount from directly invited users
        $totalDepositAmount = Deposit::whereIn('user_id', $directInvitedUserIds)
            ->where('status', 'paid') // Only count paid deposits
            ->sum('amount');
        
        if ($totalDepositAmount <= 0) {
            return false; // No deposits from direct invites
        }

        // Calculate ratio: total deposit amount / inviter total balance
        $totalDepositDecimal = Decimal::of($totalDepositAmount);
        $ratio = $totalDepositDecimal->dividedBy($inviterTotalBalance);
        
        // Check if ratio >= 30% (0.3)
        $hasRatio = $ratio->isGreaterThanOrEqualTo(Decimal::of('0.3'));
        
        // If ratio >= 30%, grant bonus window (2 days, 4 extra quota per day)
        if ($hasRatio) {
            $this->grantInviterBonusWindow($user->id);
        }
        
        return $hasRatio;
    }

    /**
     * Grant bonus window for inviter with ratio >= 30%.
     * Creates a 2-day bonus window (today and tomorrow) with 2 extra quota per day (total 4 times).
     * If user already has a bonus window, appends a new 2-day window starting from the day after the existing window ends.
     * 
     * Maximum duration: 30 days from today (to prevent unlimited accumulation).
     */
    private function grantInviterBonusWindow(int $userId): void
    {
        $today = TimeHelper::now()->format('Y-m-d');
        $maxEndDate = TimeHelper::now()->addDays(60)->format('Y-m-d'); // Maximum 60 days from today
        
        // Find the latest bonus window for this user
        $latestBonus = FollowBonusWindow::where('user_id', $userId)
            ->where('reason', 'inviter_ratio30pct')
            ->orderBy('end_date', 'desc')
            ->first();
        
        if ($latestBonus && $latestBonus->end_date >= $today) {
            // User already has an active or future bonus window
            // Append a new 2-day window starting from the day after the existing window ends
            $newStartDate = TimeHelper::parse($latestBonus->end_date)
                ->addDay()
                ->format('Y-m-d');
            $newEndDate = TimeHelper::parse($newStartDate)
                ->addDay()
                ->format('Y-m-d'); // 2 days: newStartDate + next day
            
            // Check maximum duration limit (30 days from today)
            if ($newEndDate > $maxEndDate) {
                // Already reached maximum duration, don't create new window
                return;
            }
            
            // Check if this new window period already exists
            $existingNewBonus = FollowBonusWindow::where('user_id', $userId)
                ->where('reason', 'inviter_ratio30pct')
                ->where('start_date', '<=', $newEndDate)
                ->where('end_date', '>=', $newStartDate)
                ->first();
            
            if (!$existingNewBonus) {
                // Create new bonus window: 2 days × 2 extra quota per day = 4 total extra quota
                FollowBonusWindow::create([
                    'user_id' => $userId,
                    'reason' => 'inviter_ratio30pct',
                    'start_date' => $newStartDate,
                    'end_date' => $newEndDate,
                    'daily_extra_quota' => 2, // 2 extra quota per day, total 4 times in 2 days
                ]);
            }
        } else {
            // No existing bonus window or existing window has expired
            // Create a new 2-day window starting from today
            $endDate = TimeHelper::now()->addDays(1)->format('Y-m-d'); // Tomorrow (2 days total: today + tomorrow)
            
            // Check maximum duration limit (30 days from today)
            if ($endDate > $maxEndDate) {
                // Already reached maximum duration, don't create new window
                return;
            }
            
            // Check if this period already exists
            $existingBonus = FollowBonusWindow::where('user_id', $userId)
                ->where('reason', 'inviter_ratio30pct')
                ->where('start_date', '<=', $endDate)
                ->where('end_date', '>=', $today)
                ->first();
            
            if (!$existingBonus) {
                // Create new bonus window: 2 days × 2 extra quota per day = 4 total extra quota
                FollowBonusWindow::create([
                    'user_id' => $userId,
                    'reason' => 'inviter_ratio30pct',
                    'start_date' => $today,
                    'end_date' => $endDate,
                    'daily_extra_quota' => 2, // 2 extra quota per day, total 4 times in 2 days
                ]);
            }
        }
    }

    /**
     * Get detailed reason why user cannot participate in a window type.
     * This is used for better error messages.
     */
    private function getPermissionDeniedReason(User $user, string $windowType): string
    {
        // fixed_daily: Should not reach here if balance check passed
        if ($windowType === 'fixed_daily') {
            return 'Unknown reason (fixed_daily window)';
        }

        // newbie_bonus: Check if user is newbie
        if ($windowType === 'newbie_bonus') {
            if (!$user->first_joined_at) {
                return 'User has no join date';
            }
            
            $daysSinceJoin = $user->first_joined_at->setTimezone('Asia/Shanghai')->diffInDays(TimeHelper::now());
            
            if ($daysSinceJoin < 1) {
                return "User registered today (day 1), eligible days are 2-6";
            } elseif ($daysSinceJoin > 5) {
                return "User registered {$daysSinceJoin} days ago, eligible days are 2-6";
            }
            
            return 'Unknown reason (newbie_bonus window)';
        }

        // inviter_bonus: Check inviter ratio
        if ($windowType === 'inviter_bonus') {
            // Get inviter's total balance
            $inviterTotalBalance = $this->assetsService->getTotalBalance($user->id);
            
            if ($inviterTotalBalance->isZero()) {
                return 'User has no balance';
            }
            
            // Get total deposit amount from directly invited users
            $directInvitedUserIds = User::where('invited_by_user_id', $user->id)
                ->pluck('id')
                ->toArray();
            
            if (empty($directInvitedUserIds)) {
                return 'User has no direct invites';
            }
            
            $totalDepositAmount = Deposit::whereIn('user_id', $directInvitedUserIds)
                ->where('status', 'paid')
                ->sum('amount');
            
            if ($totalDepositAmount <= 0) {
                return 'No deposits from direct invites';
            }
            
            $totalDepositDecimal = Decimal::of($totalDepositAmount);
            $ratio = $totalDepositDecimal->dividedBy($inviterTotalBalance)->multipliedBy(100);
            
            return "User inviter ratio is {$ratio->toFixed(2)}% (required: >= 30%). Direct invites deposit: {$totalDepositDecimal->toFixed(2)}, Inviter balance: {$inviterTotalBalance->toFixed(2)}";
        }

        return "Unknown window type: {$windowType}";
    }

    /**
     * Generate random rate between min and max.
     */
    private function randomRate(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}

