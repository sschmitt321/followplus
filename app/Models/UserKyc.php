<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserKyc extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'user_kyc';

    protected $fillable = [
        'user_id',
        'level',
        'status',
        'front_image_url',
        'back_image_url',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'string',
            'status' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
