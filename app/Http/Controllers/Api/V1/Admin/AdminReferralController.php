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

