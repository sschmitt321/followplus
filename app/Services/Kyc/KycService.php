<?php

namespace App\Services\Kyc;

use App\Models\User;
use App\Models\UserKyc;

class KycService
{
    /**
     * Submit basic KYC.
     */
    public function submitBasic(int $userId, string $name): UserKyc
    {
        $user = User::findOrFail($userId);

        // Update profile name
        $user->profile()->updateOrCreate(
            ['user_id' => $userId],
            ['name' => $name]
        );

        // Update KYC level
        return $user->kyc()->updateOrCreate(
            ['user_id' => $userId],
            [
                'level' => 'basic',
                'status' => 'pending',
            ]
        );
    }

    /**
     * Submit advanced KYC.
     */
    public function submitAdvanced(int $userId, string $frontImageUrl, string $backImageUrl): UserKyc
    {
        $user = User::findOrFail($userId);

        return $user->kyc()->updateOrCreate(
            ['user_id' => $userId],
            [
                'level' => 'advanced',
                'status' => 'pending',
                'front_image_url' => $frontImageUrl,
                'back_image_url' => $backImageUrl,
            ]
        );
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

