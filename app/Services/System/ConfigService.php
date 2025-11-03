<?php

namespace App\Services\System;

use App\Models\SystemConfig;
use Illuminate\Support\Facades\Cache;

class ConfigService
{
    /**
     * Get config value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("config:{$key}", 3600, function () use ($key, $default) {
            $config = SystemConfig::where('key', $key)->first();
            return $config?->val ?? $default;
        });
    }

    /**
     * Set config value.
     */
    public function set(string $key, mixed $val, ?int $operatorId = null): SystemConfig
    {
        $config = SystemConfig::updateOrCreate(
            ['key' => $key],
            [
                'val' => is_array($val) ? json_encode($val) : $val,
                'version' => SystemConfig::where('key', $key)->max('version') + 1,
                'updated_by' => $operatorId,
            ]
        );

        Cache::forget("config:{$key}");

        return $config;
    }
}

