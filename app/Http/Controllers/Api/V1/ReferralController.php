<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RefReward;
use App\Models\RefStat;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    /**
     * Get referral summary for current user.
     * 
     * Returns referral program statistics for the authenticated user, including:
     * - Direct referrals count (users directly invited)
     * - Team references count (total users in referral tree)
     * - Ambassador level (L0-L5 based on team size)
     * - Dividend rate (profit sharing percentage based on level)
     * - Total rewards earned from referral program
     * 
     * If no referral statistics exist for the user, default values are created and returned.
     * 
     * @return JsonResponse Returns referral summary:
     * - direct_count: Number of directly invited users
     * - team_count: Total number of users in referral team tree
     * - level: Ambassador level (L0, L1, L2, L3, L4, or L5)
     * - dividend_rate: Profit sharing rate (0-1, based on ambassador level)
     * - total_rewards: Total rewards earned from referral program
     */
    public function summary(): JsonResponse
    {
        $user = auth()->user();
        $stat = RefStat::where('user_id', $user->id)->first();
        
        if (!$stat) {
            // Create default stat with total_rewards set to 0
            $stat = RefStat::create([
                'user_id' => $user->id,
                'direct_count' => 0,
                'direct_active_count' => 0,
                'team_count' => 0,
                'team_active_count' => 0,
                'ambassador_level' => 'L0',
                'dividend_rate' => 0,
                'total_rewards' => '0.000000', // Ensure total_rewards is not null
            ]);
        }

        // Ensure total_rewards is not null (handle case where it might be null in database)
        $totalRewards = $stat->total_rewards ?? Decimal::zero();

        return response()->json([
            'direct_count' => $stat->direct_count,
            'direct_active_count' => $stat->direct_active_count ?? 0,
            'team_count' => $stat->team_count,
            'team_active_count' => $stat->team_active_count ?? 0,
            'level' => $stat->ambassador_level,
            'dividend_rate' => (float) $stat->dividend_rate,
            'total_rewards' => $totalRewards->toFixed(6),
        ]);
    }

    /**
     * Get reward type label in Chinese.
     */
    private function getRewardTypeLabel(string $type): string
    {
        return match ($type) {
            'referral_10pct' => '首充推荐奖励',
            'notifier_5pct' => '通知人奖励',
            'upline_5pct' => '上级奖励',
            'newbie_next_day' => '新人次日奖励',
            'ambassador_oneoff' => '等级一次性奖励',
            'ambassador_oneoff_deduction' => '等级下降扣除',
            'dividend' => '周期分红',
            default => $type,
        };
    }

    /**
     * Get reward status label in Chinese.
     */
    private function getRewardStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '待确认',
            'confirmed' => '已确认',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    /**
     * Get reward history.
     * 
     * Returns paginated list of referral rewards earned by the authenticated user.
     * Supports filtering by reward type and status. Rewards are earned from:
     * - Direct referrals (users directly invited)
     * - Team referrals (users in referral tree)
     * - Ambassador dividends (profit sharing based on level)
     * 
     * @param Request $request Query parameters
     * @param string|null $request->type Optional. Filter by reward type. Allowed values depend on reward types configured in system.
     * @param string|null $request->status Optional. Filter by reward status. Allowed values: "pending", "confirmed", "cancelled".
     * @param int|null $request->page Optional. Page number for pagination (default: 1)
     * 
     * @return JsonResponse Returns paginated reward list with metadata:
     * - rewards: Array of reward records with type, type_label, amount, status, status_label, source_user info, and timestamp
     * - pagination: Pagination metadata (current_page, total_pages, total)
     * 
     * Query example: ?type=referral_10pct&status=confirmed&page=1
     */
    public function rewards(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        $query = RefReward::where('user_id', $user->id)
            ->with('sourceUser:id,email,phone'); // Load source user info
        
        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->get('type'));
        }
        
        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }
        
        $rewards = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'rewards' => $rewards->map(function ($reward) {
                $sourceUserInfo = null;
                if ($reward->source_user_id && $reward->sourceUser) {
                    $sourceUserInfo = [
                        'id' => $reward->sourceUser->id,
                        'email' => $reward->sourceUser->email,
                        'phone' => $reward->sourceUser->phone,
                    ];
                }

                return [
                    'id' => $reward->id,
                    'type' => $reward->type,
                    'type_label' => $this->getRewardTypeLabel($reward->type),
                    'amount' => $reward->amount->toFixed(6),
                    'status' => $reward->status,
                    'status_label' => $this->getRewardStatusLabel($reward->status),
                    'source_user_id' => $reward->source_user_id,
                    'source_user' => $sourceUserInfo,
                    'created_at' => $reward->created_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                    'created_at_iso' => $reward->created_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $rewards->currentPage(),
                'total_pages' => $rewards->lastPage(),
                'total' => $rewards->total(),
                'per_page' => $rewards->perPage(),
            ],
        ]);
    }
}

