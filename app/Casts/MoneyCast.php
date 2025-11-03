<?php

namespace App\Casts;

use App\Support\Decimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class MoneyCast implements CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Decimal
    {
        if ($value === null) {
            return null;
        }

        return Decimal::of($value);
    }

    /**
     * Transform the attribute to its underlying model values.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Decimal) {
            return $value->toFixed(6);
        }

        return Decimal::of($value)->toFixed(6);
    }
}

