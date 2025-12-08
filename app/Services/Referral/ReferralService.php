<?php

namespace App\Services\Referral;

use App\Models\Deposit;
use App\Models\RefStat;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralService
{
    /**
     * Minimum total deposit amount (in USDT) required for user activation.
     * User must have cumulative deposits >= this amount to be considered "activated".
     */
    private const MIN_ACTIVATION_AMOUNT = 1000; // 1000 USDT

    public function __construct()
    {
    }
    
    /**
     * Get RewardService instance (lazy-loaded to avoid circular dependency).
     */
    private function getRewardService(): ?RewardService
    {
        try {
            return app(RewardService::class);
        } catch (\Exception $e) {
            // If RewardService cannot be resolved (e.g., circular dependency), return null
            return null;
        }
    }
    /**
     * Bind inviter to user (called during registration).
     */
    public function bindInviter(int $userId, string $inviterCode): void
    {
        DB::transaction(function () use ($userId, $inviterCode) {
            $user = User::lockForUpdate()->findOrFail($userId);
            
            if ($user->invited_by_user_id) {
                throw new \Exception('User already has an inviter');
            }

            $inviter = User::where('invite_code', $inviterCode)->first();
            if (!$inviter) {
                throw new \Exception('Invalid invite code');
            }

            if ($inviter->id === $userId) {
                throw new \Exception('Cannot invite yourself');
            }

            // Build ref_path: inviter's path + inviter's id
            $refPath = $inviter->ref_path . '/' . $inviter->id;
            $refDepth = $inviter->ref_depth + 1;

            $user->update([
                'invited_by_user_id' => $inviter->id,
                'ref_path' => $refPath,
                'ref_depth' => $refDepth,
            ]);

            // Update inviter's direct_count
            $this->incrementDirectCount($inviter->id);
            
            // Recalculate team stats for inviter's upline
            $this->recalcTeamStats($inviter->id);
        });
    }

    /**
     * Recalculate team statistics for a user and all upline users.
     * 
     * Note: This method recalculates stats for upline users (users in ref_path),
     * but NOT for the user itself. To recalculate a specific user's stats,
     * use recalcSingleUserStats() directly or call recalcTeamStats() with one of their downlines.
     */
    public function recalcTeamStats(int $userId): void
    {
        $user = User::findOrFail($userId);
        
        // First, recalculate the user's own stats
        $this->recalcSingleUserStats($userId);
        
        // Then recalculate all users in the upline path
        $pathIds = $this->extractPathIds($user->ref_path);
        
        foreach ($pathIds as $pathUserId) {
            $this->recalcSingleUserStats($pathUserId);
        }
    }

    /**
     * Recalculate stats for a single user.
     */
    private function recalcSingleUserStats(int $userId): void
    {
        $user = User::findOrFail($userId);
        
        // Count direct downlines
        $directCount = User::where('invited_by_user_id', $userId)->count();
        
        // Count active direct downlines (users who have at least one confirmed deposit)
        $directActiveCount = $this->countActiveDirectDownlines($userId);
        
        // Count total team size (including all subtree)
        $teamCount = $this->countSubtreeSize($userId);
        
        // Count active team size (including all subtree, only users with confirmed deposits)
        $teamActiveCount = $this->countActiveSubtreeSize($userId);
        
        // Get or create ref_stat
        $stat = RefStat::firstOrCreate(
            ['user_id' => $userId],
            [
                'direct_count' => 0,
                'direct_active_count' => 0,
                'team_count' => 0,
                'team_active_count' => 0,
                'ambassador_level' => 'L0',
                'dividend_rate' => 0,
            ]
        );
        
        $stat->update([
            'direct_count' => $directCount,
            'direct_active_count' => $directActiveCount,
            'team_count' => $teamCount,
            'team_active_count' => $teamActiveCount,
        ]);
        
        // Recalculate ambassador level based on team_active_count and direct_active_count
        // Level requires both team size and direct downlines to meet criteria
        $newLevel = $this->calculateAmbassadorLevel($teamActiveCount, $directActiveCount);
        $oldLevel = $stat->ambassador_level;
        
        if ($oldLevel !== $newLevel) {
            // Update ambassador level and dividend rate
            $dividendRate = $this->getDividendRateForLevel($newLevel);
            $stat->update([
                'ambassador_level' => $newLevel,
                'dividend_rate' => $dividendRate,
            ]);
            
            // Auto-grant ambassador reward when level up (if RewardService is available)
            if ($newLevel !== 'L0') {
                $rewardService = $this->getRewardService();
                if ($rewardService) {
                    try {
                        $rewardService->grantAmbassadorOneOff($userId, $newLevel);
                    } catch (\Exception $e) {
                        // Log error but don't fail the recalculation
                        Log::error("Failed to grant ambassador reward on level up", [
                            'user_id' => $userId,
                            'old_level' => $oldLevel,
                            'new_level' => $newLevel,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } else {
            // Even if level doesn't change, update dividend rate to ensure consistency
            $dividendRate = $this->getDividendRateForLevel($newLevel);
            if ($stat->dividend_rate != $dividendRate) {
                $stat->update(['dividend_rate' => $dividendRate]);
            }
        }
    }

    /**
     * Count subtree size (excluding user itself, only counting downlines).
     * 
     * Rule: If a direct downline has more invites than the user, 
     * that downline's team is excluded from the user's team count.
     * Otherwise, include the downline and their entire subtree.
     */
    private function countSubtreeSize(int $userId): int
    {
        $user = User::findOrFail($userId);
        
        // Get user's direct invite count
        $userDirectCount = User::where('invited_by_user_id', $userId)->count();
        
        // Get all direct downlines
        $directDownlines = User::where('invited_by_user_id', $userId)->get();
        
        $totalCount = 0;
        
        foreach ($directDownlines as $downline) {
            // Check if this downline has more invites than the user
            $downlineDirectCount = User::where('invited_by_user_id', $downline->id)->count();
            
            if ($downlineDirectCount > $userDirectCount) {
                // Skip this downline's entire team (don't count them)
                continue;
            }
            
            // Include this downline and their entire subtree
            $basePath = rtrim($downline->ref_path, '/');
        if ($basePath === '') {
                $pathPrefix = '/' . $downline->id;
        } else {
                $pathPrefix = $basePath . '/' . $downline->id;
        }
        
            // Count all users in this downline's subtree (including the downline itself)
            $subtreeCount = User::where('ref_path', 'like', $pathPrefix . '%')
            ->where('id', '!=', $userId)
            ->count();
            
            $totalCount += $subtreeCount;
        }
        
        return $totalCount;
    }

    /**
     * Count active direct downlines (users who have cumulative deposits >= MIN_ACTIVATION_AMOUNT).
     */
    private function countActiveDirectDownlines(int $userId): int
    {
        // Get all direct downlines
        $directDownlines = User::where('invited_by_user_id', $userId)->pluck('id');
        
        if ($directDownlines->isEmpty()) {
            return 0;
        }
        
        // Count how many of them are activated (cumulative deposits >= MIN_ACTIVATION_AMOUNT)
        $activeCount = 0;
        foreach ($directDownlines as $downlineId) {
            if ($this->isUserActivated($downlineId)) {
                $activeCount++;
            }
        }
        
        return $activeCount;
    }

    /**
     * Count active subtree size (users with cumulative deposits >= MIN_ACTIVATION_AMOUNT).
     * 
     * Rule: If a direct downline has more active invites than the user, 
     * that downline's team is excluded from the user's active team count.
     * Otherwise, include the downline and their entire subtree.
     */
    private function countActiveSubtreeSize(int $userId): int
    {
        $user = User::findOrFail($userId);
        
        // Get user's active direct invite count
        $userActiveDirectCount = $this->countActiveDirectDownlines($userId);
        
        // Get all direct downlines
        $directDownlines = User::where('invited_by_user_id', $userId)->get();
        
        $totalActiveCount = 0;
        
        foreach ($directDownlines as $downline) {
            // Check if this downline has more active invites than the user
            $downlineActiveDirectCount = $this->countActiveDirectDownlines($downline->id);
            
            if ($downlineActiveDirectCount > $userActiveDirectCount) {
                // Skip this downline's entire team (don't count them)
                continue;
            }
            
            // Include active users in this downline's subtree (including the downline itself)
            $basePath = rtrim($downline->ref_path, '/');
        if ($basePath === '') {
                $pathPrefix = '/' . $downline->id;
        } else {
                $pathPrefix = $basePath . '/' . $downline->id;
        }
        
        $subtreeUserIds = User::where('ref_path', 'like', $pathPrefix . '%')
            ->where('id', '!=', $userId)
            ->pluck('id');
        
        foreach ($subtreeUserIds as $subtreeUserId) {
            if ($this->isUserActivated($subtreeUserId)) {
                    $totalActiveCount++;
                }
            }
        }
        
        return $totalActiveCount;
    }

    /**
     * Check if a user is activated (cumulative deposits >= MIN_ACTIVATION_AMOUNT).
     * 
     * @param int $userId User ID to check
     * @return bool True if user has cumulative deposits >= MIN_ACTIVATION_AMOUNT (1000 USDT)
     */
    public function isUserActivated(int $userId): bool
    {
        // Get all confirmed deposits for this user (in USDT)
        $deposits = Deposit::where('user_id', $userId)
            ->where('status', 'confirmed')
            ->where('currency', 'USDT')
            ->get();
        
        // Sum all deposit amounts using Decimal
        $totalAmount = Decimal::zero();
        foreach ($deposits as $deposit) {
            // Ensure amount is Decimal object
            $amount = $deposit->amount instanceof Decimal ? $deposit->amount : Decimal::of($deposit->amount);
            $totalAmount = $totalAmount->add($amount);
        }
        
        // Compare with minimum activation amount
        $minAmount = Decimal::of(self::MIN_ACTIVATION_AMOUNT);
        // Use compare method: >= 0 means totalAmount >= minAmount
        return $totalAmount->compare($minAmount) >= 0;
    }

    /**
     * Calculate ambassador level based on active team count and active direct downlines.
     * 
     * Level requires BOTH conditions to be met:
     * - Team size (team_active_count): total activated users in referral tree
     * - Direct downlines (direct_active_count): direct activated invitees
     * 
     * Level 1: 3+ direct activated downlines
     * Level 2: 20+ team members AND 5+ direct activated downlines
     * Level 3: 50+ team members AND 8+ direct activated downlines
     * Level 4: 200+ team members AND 15+ direct activated downlines
     * Level 5 (Company Ambassador): 500+ team members AND 20+ direct activated downlines
     */
    private function calculateAmbassadorLevel(int $activeTeamCount, int $activeDirectCount): string
    {
        // Level 5 (Company Ambassador): 500+ team AND 20+ direct
        if ($activeTeamCount >= 500 && $activeDirectCount >= 20) {
            return 'L5';
        }
        // Level 4: 200+ team AND 15+ direct
        elseif ($activeTeamCount >= 200 && $activeDirectCount >= 15) {
            return 'L4';
        }
        // Level 3: 50+ team AND 8+ direct
        elseif ($activeTeamCount >= 50 && $activeDirectCount >= 8) {
            return 'L3';
        }
        // Level 2: 20+ team AND 5+ direct
        elseif ($activeTeamCount >= 20 && $activeDirectCount >= 5) {
            return 'L2';
        }
        // Level 1: 3+ direct (no team requirement)
        elseif ($activeDirectCount >= 3) {
            return 'L1';
        }
        
        return 'L0';
    }

    /**
     * Get dividend rate for ambassador level.
     * 
     * Trading volume dividend rates:
     * Level 1: 0.5%
     * Level 2: 1.0%
     * Level 3: 1.5%
     * Level 4: 2.0%
     * Level 5 (Company Ambassador): 2.5%
     */
    private function getDividendRateForLevel(string $level): float
    {
        return match ($level) {
            'L1' => 0.0050, // 0.5%
            'L2' => 0.0100, // 1.0%
            'L3' => 0.0150, // 1.5%
            'L4' => 0.0200, // 2.0%
            'L5' => 0.0250, // 2.5%
            default => 0.0,
        };
    }

    /**
     * Extract user IDs from ref_path.
     */
    private function extractPathIds(string $refPath): array
    {
        if (empty($refPath) || $refPath === '/') {
            return [];
        }
        
        // Remove leading slash and split
        $parts = explode('/', trim($refPath, '/'));
        return array_filter(array_map('intval', $parts));
    }

    /**
     * Increment direct count for a user.
     */
    private function incrementDirectCount(int $userId): void
    {
        $stat = RefStat::firstOrCreate(
            ['user_id' => $userId],
            [
                'direct_count' => 0,
                'direct_active_count' => 0,
                'team_count' => 0,
                'team_active_count' => 0,
                'ambassador_level' => 'L0',
                'dividend_rate' => 0,
            ]
        );
        
        $stat->increment('direct_count');
    }

    /**
     * Update referral statistics after user registration.
     * 
     * This method should be called after a new user is registered with an inviter.
     * It updates the inviter's direct_count and recalculates team statistics.
     * 
     * Note: recalcTeamStats() will recalculate direct_count from database,
     * so we don't need to call incrementDirectCount() separately.
     * 
     * @param int $inviterId The inviter's user ID
     * @param int $newUserId The newly registered user's ID (for logging purposes)
     */
    public function updateReferralStats(int $inviterId, int $newUserId): void
    {
        // Recalculate team stats for inviter and all upline users
        // This will update direct_count (by counting) and team_count
        $this->recalcTeamStats($inviterId);
    }

    /**
     * Handle direct downline withdrawal paid (detach logic).
     */
    public function onDirectDownlineWithdrawPaid(int $directChildId): void
    {
        DB::transaction(function () use ($directChildId) {
            $child = User::lockForUpdate()->findOrFail($directChildId);
            
            if (!$child->invited_by_user_id) {
                return; // Already detached or root user
            }

            $inviterId = $child->invited_by_user_id;
            $subtreeSize = $this->countSubtreeSize($directChildId);

            // Detach: set ref_path to root, clear inviter
            $oldPath = $child->ref_path;
            $child->update([
                'invited_by_user_id' => null,
                'ref_path' => '/',
                'ref_depth' => 0,
            ]);

            // Update all subtree users' ref_path
            $this->detachSubtree($directChildId, $oldPath);

            // Update inviter's stats
            $inviterStat = RefStat::where('user_id', $inviterId)->first();
            if ($inviterStat) {
                $inviterStat->decrement('direct_count');
                $inviterStat->decrement('team_count', $subtreeSize);
            }

            // Recalculate team stats for inviter's upline
            $this->recalcTeamStats($inviterId);
        });
    }

    /**
     * Detach subtree and update ref_path for all subtree users.
     */
    private function detachSubtree(int $rootUserId, string $oldPathPrefix): void
    {
        $rootUser = User::findOrFail($rootUserId);
        $newPathPrefix = '/';
        
        // Find all users in the subtree
        $subtreeUsers = User::where('ref_path', 'like', $oldPathPrefix . '/' . $rootUserId . '%')
            ->orWhere('id', $rootUserId)
            ->get();

        foreach ($subtreeUsers as $user) {
            if ($user->id === $rootUserId) {
                continue; // Already updated
            }
            
            // Calculate new ref_path based on new tree structure
            // Since we're detaching, all subtree users become root
            $user->update([
                'invited_by_user_id' => null,
                'ref_path' => '/',
                'ref_depth' => 0,
            ]);
        }
    }

    /**
     * Get upline users (up to 3 levels for rewards).
     */
    public function getUplineChain(int $userId, int $maxLevels = 3): array
    {
        $user = User::findOrFail($userId);
        $pathIds = $this->extractPathIds($user->ref_path);
        
        // Return up to maxLevels users (most recent first)
        return array_slice(array_reverse($pathIds), 0, $maxLevels);
    }
}

