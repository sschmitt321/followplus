<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Referral\TeamRecalcService;
use App\Services\Referral\RewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReferralController extends Controller
{
    public function __construct(
        private TeamRecalcService $teamRecalcService,
        private RewardService $rewardService
    ) {
    }

    /**
     * Recalculate ambassador levels.
     * 
     * Recalculates ambassador levels for all users in the system based on their team size.
     * Ambassador levels (L0-L5) are determined by the total number of users in a user's referral tree.
     * This operation recalculates levels for all users and updates their dividend rates accordingly.
     * 
     * Admin only endpoint. This is a computationally intensive operation and should be run
     * during low-traffic periods or via scheduled job.
     * 
     * @param Request $request
     * @param int|null $request->user_id Optional. If provided, only recalculates for specific user and their team (for testing/debugging).
     * 
     * @return JsonResponse Returns success message indicating recalculation completion
     * 
     * Request example:
     * {
     *   "user_id": 123
     * }
     */
    public function levelRecalc(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        
        try {
            $this->teamRecalcService->recalcAmbassadorLevels();
            
            return response()->json([
                'message' => 'Level recalculation completed',
                'user_id' => $userId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reverse a reward.
     * 
     * Reverses a previously issued referral reward. This operation:
     * - Debits the reward amount from user's account
     * - Changes reward status to "reversed"
     * - Creates audit log entry
     * 
     * Used for correcting errors or handling disputes. Admin only endpoint.
     * 
     * @param Request $request
     * @param int $request->reward_id Required. ID of the reward to reverse. Reward must exist and be in "paid" status.
     * 
     * @return JsonResponse Returns success message and reversed reward ID
     * 
     * Request example:
     * {
     *   "reward_id": 456
     * }
     */
    public function rewardReverse(Request $request): JsonResponse
    {
        $rewardId = $request->input('reward_id');
        
        if (!$rewardId) {
            return response()->json([
                'error' => 'reward_id is required',
            ], 400);
        }

        try {
            $this->rewardService->reverseReward($rewardId);
            
            return response()->json([
                'message' => 'Reward reversed successfully',
                'reward_id' => $rewardId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

