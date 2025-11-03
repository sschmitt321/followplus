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

