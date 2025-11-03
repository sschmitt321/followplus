<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RefReward;
use App\Models\RefStat;
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
            // Create default stat
            $stat = RefStat::create([
                'user_id' => $user->id,
                'direct_count' => 0,
                'team_count' => 0,
                'ambassador_level' => 'L0',
                'dividend_rate' => 0,
            ]);
        }

        return response()->json([
            'direct_count' => $stat->direct_count,
            'team_count' => $stat->team_count,
            'level' => $stat->ambassador_level,
            'dividend_rate' => (float) $stat->dividend_rate,
            'total_rewards' => $stat->total_rewards->toFixed(6),
        ]);
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
     * @param string|null $request->status Optional. Filter by reward status. Allowed values: "pending", "paid", "reversed".
     * @param int|null $request->page Optional. Page number for pagination (default: 1)
     * 
     * @return JsonResponse Returns paginated reward list with metadata:
     * - rewards: Array of reward records with type, amount, status, source_user_id, and timestamp
     * - pagination: Pagination metadata (current_page, total_pages, total)
     * 
     * Query example: ?type=direct&status=paid&page=1
     */
    public function rewards(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        $query = RefReward::where('user_id', $user->id);
        
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
                return [
                    'id' => $reward->id,
                    'type' => $reward->type,
                    'amount' => $reward->amount->toFixed(6),
                    'status' => $reward->status,
                    'source_user_id' => $reward->source_user_id,
                    'created_at' => $reward->created_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $rewards->currentPage(),
                'total_pages' => $rewards->lastPage(),
                'total' => $rewards->total(),
            ],
        ]);
    }
}

