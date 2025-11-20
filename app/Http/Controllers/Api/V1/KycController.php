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
     * Submit basic KYC information (deprecated - use submit instead).
     * 
     * @deprecated Use submit() instead
     */
    public function submitBasic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $kyc = $this->kycService->submit(auth()->id(), $validated['name']);

        return response()->json([
            'message' => 'KYC submitted successfully',
            'kyc' => [
                'level' => $kyc->level,
                'status' => $kyc->status,
            ],
        ], 200);
    }

    /**
     * Upload KYC image.
     * 
     * Uploads a single KYC image file (front or back of ID card).
     * Returns the file path and a temporary access URL.
     * 
     * @param Request $request
     * @param \Illuminate\Http\UploadedFile $request->image Required. Image file (JPEG, PNG, or WebP, max 5MB)
     * @param string $request->type Optional. Image type: "front" or "back" (default: "front")
     * 
     * @return JsonResponse Returns file path and access URL
     * 
     * Request: multipart/form-data
     * - image: file (required)
     * - type: string (optional, "front" or "back")
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120', // Max 5MB
            'type' => 'nullable|string|in:front,back',
        ]);

        try {
            $file = $request->file('image');
            $type = $validated['type'] ?? 'front';
            
            $path = $this->kycService->uploadImage(auth()->id(), $file, $type);
            $url = $this->kycService->getImageUrl($path);

            return response()->json([
                'message' => 'Image uploaded successfully',
                'path' => $path,
                'url' => $url,
                'type' => $type,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            \Log::error('KYC image upload failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to upload image',
            ], 500);
        }
    }

    /**
     * Submit KYC information.
     * 
     * Submits KYC information including name and ID card images (front and back).
     * All fields are submitted together. If images are provided, level will be "advanced",
     * otherwise it will be "basic".
     * 
     * Images can be provided as files (multipart/form-data) or URLs (JSON).
     * After submission, status changes to "pending" for manual review.
     * 
     * @param Request $request
     * @param string $request->name Required. User's real name (max 255 characters)
     * @param \Illuminate\Http\UploadedFile|string|null $request->front Optional. Front image file or URL
     * @param \Illuminate\Http\UploadedFile|string|null $request->back Optional. Back image file or URL
     * 
     * @return JsonResponse Returns success message and updated KYC information
     * 
     * Request examples:
     * 
     * 1. File upload (multipart/form-data):
     *    - name: "张三"
     *    - front: file
     *    - back: file
     * 
     * 2. URL (application/json):
     *    {
     *      "name": "张三",
     *      "front": "https://example.com/uploads/id_front.jpg",
     *      "back": "https://example.com/uploads/id_back.jpg"
     *    }
     * 
     * 3. Name only (application/json):
     *    {
     *      "name": "张三"
     *    }
     */
    public function submit(Request $request): JsonResponse
    {
        // Check if request contains files
        $hasFiles = $request->hasFile('front') || $request->hasFile('back');
        
        if ($hasFiles) {
            // File upload mode
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'front' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
                'back' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            ]);

            $name = $validated['name'];
            $frontImage = $request->file('front');
            $backImage = $request->file('back');
        } else {
            // URL or name-only mode
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'front' => 'nullable|string|url',
                'back' => 'nullable|string|url',
            ]);

            $name = $validated['name'];
            $frontImage = $validated['front'] ?? null;
            $backImage = $validated['back'] ?? null;
        }

        try {
            $kyc = $this->kycService->submit(
                auth()->id(),
                $name,
                $frontImage,
                $backImage
            );

            return response()->json([
                'message' => 'KYC submitted successfully',
                'kyc' => [
                    'level' => $kyc->level,
                    'status' => $kyc->status,
                ],
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            \Log::error('KYC submission failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to submit KYC information',
            ], 500);
        }
    }

    /**
     * Submit advanced KYC information (deprecated - use submit instead).
     * 
     * @deprecated Use submit() instead
     */
    public function submitAdvanced(Request $request): JsonResponse
    {
        // Check if request contains files
        $hasFiles = $request->hasFile('front') || $request->hasFile('back');
        
        if ($hasFiles) {
            // File upload mode
            $validated = $request->validate([
                'front' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
                'back' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
            ]);

            $frontImage = $request->file('front');
            $backImage = $request->file('back');
        } else {
            // URL mode (backward compatibility)
            $validated = $request->validate([
                'front' => 'required|string|url',
                'back' => 'required|string|url',
            ]);

            $frontImage = $validated['front'];
            $backImage = $validated['back'];
        }

        try {
            $user = auth()->user();
            $name = $user->profile?->name ?? '';
            
            $kyc = $this->kycService->submit(
                auth()->id(),
                $name,
                $frontImage,
                $backImage
            );

            return response()->json([
                'message' => 'Advanced KYC submitted successfully',
                'kyc' => [
                    'level' => $kyc->level,
                    'status' => $kyc->status,
                ],
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            \Log::error('KYC submission failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to submit KYC information',
            ], 500);
        }
    }

    /**
     * Get KYC image.
     * 
     * Returns the KYC image file. Only the owner of the KYC record can access their images.
     * 
     * @param string $type Image type: "front" or "back"
     * 
     * @return \Illuminate\Http\Response|JsonResponse Returns image file or error
     */
    public function getImage(string $type): \Illuminate\Http\Response|JsonResponse
    {
        if (!in_array($type, ['front', 'back'])) {
            return response()->json([
                'error' => 'Invalid image type',
            ], 400);
        }

        $user = auth()->user();
        $kyc = $user->kyc;

        if (!$kyc) {
            return response()->json([
                'error' => 'KYC record not found',
            ], 404);
        }

        $imagePath = $type === 'front' ? $kyc->front_image_url : $kyc->back_image_url;

        if (!$imagePath) {
            return response()->json([
                'error' => 'Image not found',
            ], 404);
        }

        // Check if it's a storage path (not a URL)
        if (!filter_var($imagePath, FILTER_VALIDATE_URL)) {
            // Return file from storage
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk('private');
                
                if (!$disk->exists($imagePath)) {
                    return response()->json([
                        'error' => 'Image file not found',
                    ], 404);
                }

                $file = $disk->get($imagePath);
                $mimeType = $disk->mimeType($imagePath);

                return response($file, 200)
                    ->header('Content-Type', $mimeType)
                    ->header('Content-Disposition', 'inline; filename="' . basename($imagePath) . '"');
            } catch (\Exception $e) {
                \Log::error('Failed to access image file', [
                    'path' => $imagePath,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'error' => 'Failed to access image',
                ], 500);
            }
        }

        // If it's already a URL, redirect to it
        return redirect($imagePath);
    }
}
