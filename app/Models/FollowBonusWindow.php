<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowBonusWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reason',
        'start_date',
        'end_date',
        'daily_extra_quota',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Get the user this bonus window belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if bonus window is active for a given date.
     */
    public function isActiveForDate(string $date): bool
    {
        $checkDate = \Carbon\Carbon::parse($date);
        return $checkDate->gte($this->start_date) && $checkDate->lte($this->end_date);
    }
}

