<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(
        private KycService $kycService
    ) {
    }

    /**
     * Get KYC status.
     */
    public function status(): JsonResponse
    {
        $user = auth()->user();
        $kyc = $user->kyc;

        return response()->json([
            'level' => $kyc?->level ?? 'none',
            'status' => $kyc?->status ?? 'pending',
        ]);
    }

    /**
     * Submit basic KYC.
     */
    public function submitBasic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $kyc = $this->kycService->submitBasic(auth()->id(), $validated['name']);

        return response()->json([
            'message' => 'Basic KYC submitted successfully',
            'kyc' => [
                'level' => $kyc->level,
                'status' => $kyc->status,
            ],
        ], 200);
    }

    /**
     * Submit advanced KYC.
     */
    public function submitAdvanced(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'front' => 'required|string|url',
            'back' => 'required|string|url',
        ]);

        $kyc = $this->kycService->submitAdvanced(
            auth()->id(),
            $validated['front'],
            $validated['back']
        );

        return response()->json([
            'message' => 'Advanced KYC submitted successfully',
            'kyc' => [
                'level' => $kyc->level,
                'status' => $kyc->status,
            ],
        ], 200);
    }
}
