<?php

namespace App\Services\Referral;

use App\Models\RefStat;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReferralService
{
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
     */
    public function recalcTeamStats(int $userId): void
    {
        $user = User::findOrFail($userId);
        
        // Get all users in the upline path
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
        
        // Count total team size (including all subtree)
        $teamCount = $this->countSubtreeSize($userId);
        
        // Get or create ref_stat
        $stat = RefStat::firstOrCreate(
            ['user_id' => $userId],
            [
                'direct_count' => 0,
                'team_count' => 0,
                'ambassador_level' => 'L0',
                'dividend_rate' => 0,
            ]
        );
        
        $stat->update([
            'direct_count' => $directCount,
            'team_count' => $teamCount,
        ]);
        
        // Recalculate ambassador level based on team_count
        $newLevel = $this->calculateAmbassadorLevel($teamCount);
        if ($stat->ambassador_level !== $newLevel) {
            $stat->update(['ambassador_level' => $newLevel]);
        }
    }

    /**
     * Count subtree size (excluding user itself, only counting downlines).
     */
    private function countSubtreeSize(int $userId): int
    {
        $user = User::findOrFail($userId);
        $pathPrefix = $user->ref_path . '/' . $userId;
        
        // Count all users whose ref_path starts with this path (excluding self)
        return User::where('ref_path', 'like', $pathPrefix . '%')
            ->where('id', '!=', $userId)
            ->count();
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
                'team_count' => 0,
                'ambassador_level' => 'L0',
                'dividend_rate' => 0,
            ]
        );
        
        $stat->increment('direct_count');
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

