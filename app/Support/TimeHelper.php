<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Time helper for UTC+8 timezone operations.
 */
class TimeHelper
{
    /**
     * Get current time in UTC+8 (Asia/Shanghai).
     */
    public static function now(): Carbon
    {
        return now()->setTimezone('Asia/Shanghai');
    }

    /**
     * Parse a datetime string as UTC+8 timezone.
     */
    public static function parse(string $datetime): Carbon
    {
        return Carbon::parse($datetime, 'Asia/Shanghai');
    }

    /**
     * Create from format in UTC+8 timezone.
     */
    public static function createFromFormat(string $format, string $datetime): Carbon
    {
        return Carbon::createFromFormat($format, $datetime, 'Asia/Shanghai');
    }
}

