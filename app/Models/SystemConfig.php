<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemConfig extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'key',
        'val',
        'version',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
