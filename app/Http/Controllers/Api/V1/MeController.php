<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    /**
     * Get current user information.
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $user->load(['profile', 'kyc']);

        // Get assets summary
        $summary = \App\Services\Assets\AssetsService::class;
        $assetsService = app($summary);
        $assetsSummary = $assetsService->getSummary($user->id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'invite_code' => $user->invite_code,
                'role' => $user->role,
                'status' => $user->status,
                'first_joined_at' => $user->first_joined_at,
            ],
            'profile' => $user->profile ? [
                'name' => $user->profile->name,
                'city' => $user->profile->city,
            ] : null,
            'kyc' => $user->kyc ? [
                'level' => $user->kyc->level,
                'status' => $user->kyc->status,
            ] : null,
            'role' => $user->role,
            'assets' => [
                'total_balance' => $assetsSummary->total_balance->toFixed(6),
                'principal_balance' => $assetsSummary->principal_balance->toFixed(6),
                'profit_balance' => $assetsSummary->profit_balance->toFixed(6),
                'bonus_balance' => $assetsSummary->bonus_balance->toFixed(6),
            ],
        ]);
    }
}
