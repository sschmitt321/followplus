<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_user_id',
        'type',
        'amount',
        'status',
        'ref_event_id',
        'biz_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
        ];
    }

    /**
     * Get the user who receives this reward.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the source user (e.g., the referred user).
     */
    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    /**
     * Get the event that triggered this reward.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(RefEvent::class, 'ref_event_id');
    }
}

