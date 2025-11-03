<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'trigger_user_id',
        'event_type',
        'amount',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'meta_json' => 'array',
        ];
    }

    /**
     * Get the user who triggered this event.
     */
    public function triggerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trigger_user_id');
    }

    /**
     * Get rewards generated from this event.
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(RefReward::class, 'ref_event_id');
    }
}

