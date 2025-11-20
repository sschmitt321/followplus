<?php

namespace App\Services\Kyc;

use App\Models\User;
use App\Models\UserKyc;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KycService
{
    /**
     * Submit basic KYC (deprecated - use submit instead).
     * 
     * @deprecated Use submit() instead
     */
    public function submitBasic(int $userId, string $name): UserKyc
    {
        return $this->submit($userId, $name);
    }

    /**
     * Submit KYC information (name and ID card images).
     * 
     * @param int $userId User ID
     * @param string $name User's real name
     * @param string|UploadedFile|null $frontImage Front image URL or file (optional)
     * @param string|UploadedFile|null $backImage Back image URL or file (optional)
     * @return UserKyc
     */
    public function submit(int $userId, string $name, string|UploadedFile|null $frontImage = null, string|UploadedFile|null $backImage = null): UserKyc
    {
        $user = User::findOrFail($userId);

        // Update profile name
        $user->profile()->updateOrCreate(
            ['user_id' => $userId],
            ['name' => $name]
        );

        // Get existing KYC record to preserve data
        $existingKyc = $user->kyc;

        // Prepare update data
        $updateData = [
            'status' => 'pending', // Reset status when resubmitting
        ];

        // Handle image uploads
        if ($frontImage) {
            $updateData['front_image_url'] = $this->handleImageInput($userId, $frontImage, 'front');
        } elseif ($existingKyc && $existingKyc->front_image_url) {
            // Preserve existing front image if not provided
            $updateData['front_image_url'] = $existingKyc->front_image_url;
        }

        if ($backImage) {
            $updateData['back_image_url'] = $this->handleImageInput($userId, $backImage, 'back');
        } elseif ($existingKyc && $existingKyc->back_image_url) {
            // Preserve existing back image if not provided
            $updateData['back_image_url'] = $existingKyc->back_image_url;
        }

        // Determine level based on images (existing or new)
        $hasFrontImage = isset($updateData['front_image_url']) && !empty($updateData['front_image_url']);
        $hasBackImage = isset($updateData['back_image_url']) && !empty($updateData['back_image_url']);

        if ($hasFrontImage && $hasBackImage) {
            // Both images available - advanced level
            $updateData['level'] = 'advanced';
        } else {
            // Only name or partial images - basic level
            $updateData['level'] = 'basic';
        }

        // Clear review reason when resubmitting
        $updateData['review_reason'] = null;

        return $user->kyc()->updateOrCreate(
            ['user_id' => $userId],
            $updateData
        );
    }

    /**
     * Upload KYC image file.
     * 
     * @param int $userId User ID
     * @param UploadedFile $file Uploaded image file
     * @param string $type Image type ('front' or 'back')
     * @return string File path relative to storage root
     */
    public function uploadImage(int $userId, UploadedFile $file, string $type = 'front'): string
    {
        // Validate file type
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new \InvalidArgumentException('Invalid file type. Only JPEG, PNG, and WebP images are allowed.');
        }

        // Validate file size (max 5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException('File size exceeds maximum limit of 5MB.');
        }

        // Generate unique filename
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        
        // Store in private storage: kyc/{user_id}/{type}/{filename}
        $path = $file->storeAs(
            "kyc/{$userId}/{$type}",
            $filename,
            'private'
        );

        return $path;
    }

    /**
     * Get image URL for access.
     * 
     * @param string $path File path relative to storage root
     * @return string Route URL for accessing the image
     */
    public function getImageUrl(string $path): string
    {
        // Extract type from path: kyc/{user_id}/{type}/{filename}
        $parts = explode('/', $path);
        $type = $parts[2] ?? 'front'; // Extract 'front' or 'back' from path
        
        // Return route URL that will be handled by controller
        return url('/api/v1/kyc/image/' . $type);
    }

    /**
     * Submit advanced KYC (deprecated - use submit instead).
     * 
     * @deprecated Use submit() instead
     */
    public function submitAdvanced(int $userId, string|UploadedFile $frontImage, string|UploadedFile $backImage): UserKyc
    {
        $user = User::findOrFail($userId);
        $name = $user->profile?->name ?? '';
        
        return $this->submit($userId, $name, $frontImage, $backImage);
    }

    /**
     * Handle image input (file or URL).
     * 
     * @param int $userId User ID
     * @param string|UploadedFile $image Image URL or file
     * @param string $type Image type ('front' or 'back')
     * @return string Image URL or storage path
     */
    private function handleImageInput(int $userId, string|UploadedFile $image, string $type): string
    {
        if ($image instanceof UploadedFile) {
            // Upload file and return storage path
            return $this->uploadImage($userId, $image, $type);
        } else {
            // Return URL as-is (backward compatibility)
            return $image;
        }
    }

    /**
     * Review KYC (admin only).
     */
    public function review(int $kycId, string $status, ?string $reason = null): UserKyc
    {
        $kyc = UserKyc::findOrFail($kycId);

        if (!in_array($status, ['approved', 'rejected'])) {
            throw new \InvalidArgumentException('Invalid status');
        }

        $kyc->update([
            'status' => $status,
            'review_reason' => $reason,
        ]);

        return $kyc;
    }
}

