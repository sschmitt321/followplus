<?php

namespace App\Services\Referral;

use App\Models\Deposit;
use App\Models\RefStat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralService
{
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
        
        // Recalculate ambassador level based on team_count
        $newLevel = $this->calculateAmbassadorLevel($teamCount);
        $oldLevel = $stat->ambassador_level;
        
        if ($oldLevel !== $newLevel) {
            $stat->update(['ambassador_level' => $newLevel]);
            
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
        }
    }

    /**
     * Count subtree size (excluding user itself, only counting downlines).
     */
    private function countSubtreeSize(int $userId): int
    {
        $user = User::findOrFail($userId);
        
        // Build path prefix: ref_path + '/' + userId
        // Handle root path (/) correctly to avoid double slashes
        $basePath = rtrim($user->ref_path, '/');
        if ($basePath === '') {
            $pathPrefix = '/' . $userId;
        } else {
            $pathPrefix = $basePath . '/' . $userId;
        }
        
        // Count all users whose ref_path starts with this path (excluding self)
        // This includes direct children (ref_path = '/3') and all descendants (ref_path LIKE '/3/%')
        return User::where('ref_path', 'like', $pathPrefix . '%')
            ->where('id', '!=', $userId)
            ->count();
    }

    /**
     * Count active direct downlines (users who have at least one confirmed deposit).
     */
    private function countActiveDirectDownlines(int $userId): int
    {
        // Get all direct downlines
        $directDownlines = User::where('invited_by_user_id', $userId)->pluck('id');
        
        if ($directDownlines->isEmpty()) {
            return 0;
        }
        
        // Count how many of them have at least one confirmed deposit
        return Deposit::whereIn('user_id', $directDownlines)
            ->where('status', 'confirmed')
            ->distinct()
            ->count('user_id');
    }

    /**
     * Count active subtree size (users with at least one confirmed deposit).
     */
    private function countActiveSubtreeSize(int $userId): int
    {
        $user = User::findOrFail($userId);
        
        // Build path prefix: ref_path + '/' + userId
        $basePath = rtrim($user->ref_path, '/');
        if ($basePath === '') {
            $pathPrefix = '/' . $userId;
        } else {
            $pathPrefix = $basePath . '/' . $userId;
        }
        
        // Get all users in the subtree
        $subtreeUserIds = User::where('ref_path', 'like', $pathPrefix . '%')
            ->where('id', '!=', $userId)
            ->pluck('id');
        
        if ($subtreeUserIds->isEmpty()) {
            return 0;
        }
        
        // Count how many of them have at least one confirmed deposit
        return Deposit::whereIn('user_id', $subtreeUserIds)
            ->where('status', 'confirmed')
            ->distinct()
            ->count('user_id');
    }

    /**
     * Calculate ambassador level based on team count.
     * 
     * L1: 10+ team members
     * L2: 50+ team members
     * L3: 200+ team members
     * L4: 1000+ team members
     * L5: 5000+ team members
     */
    private function calculateAmbassadorLevel(int $teamCount): string
    {
        if ($teamCount >= 5000) {
            return 'L5';
        } elseif ($teamCount >= 1000) {
            return 'L4';
        } elseif ($teamCount >= 200) {
            return 'L3';
        } elseif ($teamCount >= 50) {
            return 'L2';
        } elseif ($teamCount >= 10) {
            return 'L1';
        }
        
        return 'L0';
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

