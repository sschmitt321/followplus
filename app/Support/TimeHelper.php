<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Time helper for UTC+8 (Asia/Shanghai) timezone operations.
 * 
 * All time operations in this application use UTC+8 timezone.
 * Database stores timestamps in UTC, but all comparisons and displays use UTC+8.
 */
class TimeHelper
{
    /**
     * Timezone constant for UTC+8 (Asia/Shanghai).
     */
    public const TIMEZONE = 'Asia/Shanghai';

    /**
     * Get current time in UTC+8 (Asia/Shanghai).
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /**
     * Parse a datetime string as UTC+8 timezone.
     */
    public static function parse(string $datetime): Carbon
    {
        return Carbon::parse($datetime, self::TIMEZONE);
    }

    /**
     * Create from format in UTC+8 timezone.
     */
    public static function createFromFormat(string $format, string $datetime): Carbon
    {
        return Carbon::createFromFormat($format, $datetime, self::TIMEZONE);
    }

    /**
     * Convert a Carbon instance to UTC+8 timezone.
     * If the instance is already in UTC+8, returns it as-is.
     */
    public static function toUtc8(Carbon $carbon): Carbon
    {
        return $carbon->setTimezone(self::TIMEZONE);
    }

    /**
     * Convert a Carbon instance to UTC for database storage.
     * Database stores timestamps in UTC.
     */
    public static function toUtc(Carbon $carbon): Carbon
    {
        return $carbon->utc();
    }

    /**
     * Get current UTC+8 time as UTC for database queries.
     * Use this when comparing with database timestamps stored in UTC.
     */
    public static function nowUtc(): Carbon
    {
        return self::now()->utc();
    }

    /**
     * Convert a database UTC timestamp to UTC+8 for comparison.
     */
    public static function fromDatabase(Carbon|string $timestamp): Carbon
    {
        if (is_string($timestamp)) {
            $carbon = Carbon::parse($timestamp, 'UTC');
        } else {
            $carbon = $timestamp->copy();
        }
        return $carbon->setTimezone(self::TIMEZONE);
    }

    /**
     * Convert a UTC+8 timestamp to UTC for database storage.
     */
    public static function toDatabase(Carbon|string $timestamp): Carbon
    {
        if (is_string($timestamp)) {
            $carbon = Carbon::parse($timestamp, self::TIMEZONE);
        } else {
            $carbon = $timestamp->copy();
            if ($carbon->timezone->getName() !== 'UTC') {
                $carbon = $carbon->setTimezone(self::TIMEZONE);
            }
        }
        return $carbon->utc();
    }
}

