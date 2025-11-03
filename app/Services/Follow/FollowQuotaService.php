<?php

namespace App\Services\Follow;

use App\Models\FollowBonusWindow;
use App\Models\FollowCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FollowQuotaService
{
    private const BASE_QUOTA_DAILY = 2; // 每日基础配额
    private const EXTRA_QUOTA_DAILY = 4; // 每日加餐配额

    /**
     * Check if user has quota for placing an order.
     */
    public function hasQuota(int $userId, string $date, string $windowType): bool
    {
        $counter = $this->getOrCreateCounter($userId, $date);
        
        if ($windowType === 'fixed_daily') {
            // Use base quota
            return $counter->base_quota_used < self::BASE_QUOTA_DAILY;
        } else {
            // Use extra quota (bonus windows)
            $extraQuota = $this->getExtraQuota($userId, $date);
            return $counter->extra_quota_used < $extraQuota;
        }
    }

    /**
     * Consume quota for an order.
     */
    public function consumeQuota(int $userId, string $date, string $windowType): void
    {
        DB::transaction(function () use ($userId, $date, $windowType) {
            $counter = FollowCounter::lockForUpdate()
                ->firstOrCreate(
                    ['user_id' => $userId, 'date' => $date],
                    [
                        'base_quota_used' => 0,
                        'extra_quota_used' => 0,
                    ]
                );

            if ($windowType === 'fixed_daily') {
                if ($counter->base_quota_used >= self::BASE_QUOTA_DAILY) {
                    throw new \Exception('Base quota exhausted');
                }
                $counter->increment('base_quota_used');
            } else {
                $extraQuota = $this->getExtraQuota($userId, $date);
                if ($counter->extra_quota_used >= $extraQuota) {
                    throw new \Exception('Extra quota exhausted');
                }
                $counter->increment('extra_quota_used');
            }
        });
    }

    /**
     * Get extra quota for user on a specific date.
     */
    public function getExtraQuota(int $userId, string $date): int
    {
        $baseExtra = self::EXTRA_QUOTA_DAILY;
        
        // Check bonus windows
        $bonusWindows = FollowBonusWindow::where('user_id', $userId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->get();

        $additionalQuota = 0;
        foreach ($bonusWindows as $bonusWindow) {
            if ($bonusWindow->isActiveForDate($date)) {
                $additionalQuota += $bonusWindow->daily_extra_quota;
            }
        }

        return $baseExtra + $additionalQuota;
    }

    /**
     * Get remaining quota for user.
     */
    public function getRemainingQuota(int $userId, string $date, string $windowType): array
    {
        $counter = $this->getOrCreateCounter($userId, $date);
        
        if ($windowType === 'fixed_daily') {
            return [
                'type' => 'base',
                'used' => $counter->base_quota_used,
                'total' => self::BASE_QUOTA_DAILY,
                'remaining' => self::BASE_QUOTA_DAILY - $counter->base_quota_used,
            ];
        } else {
            $extraQuota = $this->getExtraQuota($userId, $date);
            return [
                'type' => 'extra',
                'used' => $counter->extra_quota_used,
                'total' => $extraQuota,
                'remaining' => $extraQuota - $counter->extra_quota_used,
            ];
        }
    }

    /**
     * Get or create counter for user and date.
     */
    private function getOrCreateCounter(int $userId, string $date): FollowCounter
    {
        return FollowCounter::firstOrCreate(
            ['user_id' => $userId, 'date' => $date],
            [
                'base_quota_used' => 0,
                'extra_quota_used' => 0,
            ]
        );
    }
}

