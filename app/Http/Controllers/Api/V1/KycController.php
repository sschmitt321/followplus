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
     * 
     * Returns the current KYC (Know Your Customer) verification status for the authenticated user.
     * Includes KYC level (none, basic, advanced) and verification status (pending, approved, rejected).
     * 
     * @return JsonResponse Returns KYC status information:
     * - level: KYC level ("none", "basic", or "advanced")
     * - status: Verification status ("pending", "approved", or "rejected")
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
     * Submit basic KYC information.
     * 
     * Submits basic KYC information (real name) for verification. After submission,
     * the KYC status changes to "pending" and level changes to "basic".
     * 
     * @param Request $request
     * @param string $request->name Required. User's real name (max 255 characters). This will be used for identity verification.
     * 
     * @return JsonResponse Returns success message and updated KYC information:
     * - message: Success confirmation message
     * - kyc: Updated KYC object with level and status
     * 
     * Request example:
     * {
     *   "name": "张三"
     * }
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
     * Submit advanced KYC information.
     * 
     * Submits advanced KYC information including ID card images (front and back).
     * Both images must be provided as URLs. After submission, the KYC level changes to "advanced"
     * and status changes to "pending" for manual review.
     * 
     * @param Request $request
     * @param string $request->front Required. URL of ID card front image. Must be a valid URL pointing to an image file.
     * @param string $request->back Required. URL of ID card back image. Must be a valid URL pointing to an image file.
     * 
     * @return JsonResponse Returns success message and updated KYC information:
     * - message: Success confirmation message
     * - kyc: Updated KYC object with level "advanced" and status "pending"
     * 
     * Request example:
     * {
     *   "front": "https://example.com/uploads/id_front.jpg",
     *   "back": "https://example.com/uploads/id_back.jpg"
     * }
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
