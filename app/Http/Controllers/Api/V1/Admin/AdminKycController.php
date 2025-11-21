<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserKyc;
use App\Services\Audit\AuditService;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminKycController extends Controller
{
    public function __construct(
        private KycService $kycService,
        private AuditService $auditService
    ) {
    }

    /**
     * Get all KYC records.
     * 
     * Returns list of all KYC submissions with pagination.
     * Can be filtered by status and level.
     * 
     * @param Request $request Query parameters
     * @param string|null $request->status Optional. Filter by status: "pending", "approved", "rejected"
     * @param string|null $request->level Optional. Filter by level: "basic", "advanced"
     * @param int|null $request->page Optional. Page number (default: 1)
     * @param int|null $request->per_page Optional. Items per page (default: 20)
     * 
     * @return JsonResponse Returns paginated list of KYC records
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,approved,rejected',
            'level' => 'nullable|string|in:basic,advanced',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = UserKyc::with('user:id,email,phone,created_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['level'])) {
            $query->where('level', $validated['level']);
        }

        $perPage = $validated['per_page'] ?? 20;
        $kycRecords = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'kyc_records' => $kycRecords->map(function ($kyc) {
                return [
                    'id' => $kyc->id,
                    'user_id' => $kyc->user_id,
                    'user_email' => $kyc->user->email,
                    'user_phone' => $kyc->user->phone,
                    'level' => $kyc->level,
                    'status' => $kyc->status,
                    'front_image_url' => $kyc->front_image_url,
                    'back_image_url' => $kyc->back_image_url,
                    'review_reason' => $kyc->review_reason,
                    'created_at' => $kyc->created_at->toIso8601String(),
                    'updated_at' => $kyc->updated_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $kycRecords->currentPage(),
                'per_page' => $kycRecords->perPage(),
                'total' => $kycRecords->total(),
                'last_page' => $kycRecords->lastPage(),
            ],
        ]);
    }

    /**
     * Get single KYC record details.
     * 
     * Returns detailed information about a specific KYC submission.
     * 
     * @param int $id KYC record ID (path parameter)
     * 
     * @return JsonResponse Returns KYC record with user information
     */
    public function show(int $id): JsonResponse
    {
        $kyc = UserKyc::with('user.profile')->findOrFail($id);

        return response()->json([
            'kyc' => [
                'id' => $kyc->id,
                'user_id' => $kyc->user_id,
                'user' => [
                    'id' => $kyc->user->id,
                    'email' => $kyc->user->email,
                    'phone' => $kyc->user->phone,
                    'name' => $kyc->user->profile?->name,
                    'created_at' => $kyc->user->created_at->toIso8601String(),
                ],
                'level' => $kyc->level,
                'status' => $kyc->status,
                'front_image_url' => $kyc->front_image_url,
                'back_image_url' => $kyc->back_image_url,
                'review_reason' => $kyc->review_reason,
                'created_at' => $kyc->created_at->toIso8601String(),
                'updated_at' => $kyc->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Approve KYC submission.
     * 
     * Approves a pending KYC submission. Changes status to "approved".
     * 
     * @param Request $request
     * @param int $id KYC record ID (path parameter)
     * @param string|null $request->reason Optional. Approval reason/notes
     * 
     * @return JsonResponse Returns success message and updated KYC status
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $kyc = UserKyc::findOrFail($id);
            $oldStatus = $kyc->status;

            if ($kyc->status !== 'pending') {
                return response()->json([
                    'error' => 'Only pending KYC records can be approved',
                ], 400);
            }

            $validated = $request->validate([
                'reason' => 'nullable|string|max:1000',
            ]);

            $this->kycService->review($kyc->id, 'approved', $validated['reason'] ?? null);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'kyc_approve',
                'user_kyc',
                ['status' => $oldStatus],
                ['status' => 'approved', 'reason' => $validated['reason'] ?? null]
            );

            return response()->json([
                'message' => 'KYC approved successfully',
                'kyc' => [
                    'id' => $kyc->id,
                    'status' => 'approved',
                    'review_reason' => $validated['reason'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('KYC approval failed', [
                'kyc_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reject KYC submission.
     * 
     * Rejects a pending KYC submission. Changes status to "rejected".
     * Reason is required to help user understand why it was rejected.
     * 
     * @param Request $request
     * @param int $id KYC record ID (path parameter)
     * @param string $request->reason Required. Rejection reason
     * 
     * @return JsonResponse Returns success message and updated KYC status
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $kyc = UserKyc::findOrFail($id);
            $oldStatus = $kyc->status;

            if ($kyc->status !== 'pending') {
                return response()->json([
                    'error' => 'Only pending KYC records can be rejected',
                ], 400);
            }

            $validated = $request->validate([
                'reason' => 'required|string|max:1000',
            ]);

            $this->kycService->review($kyc->id, 'rejected', $validated['reason']);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'kyc_reject',
                'user_kyc',
                ['status' => $oldStatus],
                ['status' => 'rejected', 'reason' => $validated['reason']]
            );

            return response()->json([
                'message' => 'KYC rejected successfully',
                'kyc' => [
                    'id' => $kyc->id,
                    'status' => 'rejected',
                    'review_reason' => $validated['reason'],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('KYC rejection failed', [
                'kyc_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

