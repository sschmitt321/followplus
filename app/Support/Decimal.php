<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Decimal utility class for handling monetary calculations.
 */
class Decimal
{
    private BigDecimal $value;

    public function __construct(string|int|float|Decimal|BigDecimal $value)
    {
        if ($value instanceof Decimal) {
            $this->value = $value->value;
        } elseif ($value instanceof BigDecimal) {
            $this->value = $value;
        } else {
            $this->value = BigDecimal::of((string) $value);
        }
    }

    public static function of(string|int|float|Decimal|BigDecimal $value): self
    {
        return new self($value);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function add(Decimal|string|int|float $other): self
    {
        $other = $this->normalize($other);
        return new self($this->value->plus($other->value));
    }

    public function subtract(Decimal|string|int|float $other): self
    {
        $other = $this->normalize($other);
        return new self($this->value->minus($other->value));
    }

    public function multiply(Decimal|string|int|float $other): self
    {
        $other = $this->normalize($other);
        return new self($this->value->multipliedBy($other->value));
    }

    public function divide(Decimal|string|int|float $other, int $scale = 6): self
    {
        $other = $this->normalize($other);
        return new self($this->value->dividedBy($other->value, $scale, RoundingMode::HALF_UP));
    }

    public function percentage(Decimal|string|int|float $percent, int $scale = 6): self
    {
        $percent = $this->normalize($percent);
        return $this->multiply($percent)->divide(100, $scale);
    }

    public function round(int $scale = 6): self
    {
        return new self($this->value->toScale($scale, RoundingMode::HALF_UP));
    }

    public function compare(Decimal|string|int|float $other): int
    {
        $other = $this->normalize($other);
        return $this->value->compareTo($other->value);
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function greaterThan(Decimal|string|int|float $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(Decimal|string|int|float $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function equals(Decimal|string|int|float $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function toFixed(int $scale = 6): string
    {
        return (string) $this->value->toScale($scale, RoundingMode::HALF_UP);
    }

    public function toString(): string
    {
        // Format to 6 decimal places to match database storage
        return $this->toFixed(6);
    }

    public function toFloat(): float
    {
        return $this->value->toFloat();
    }

    public function toInt(): int
    {
        return $this->value->toInt();
    }

    public function abs(): self
    {
        return new self($this->value->abs());
    }

    public function negate(): self
    {
        return new self($this->value->negated());
    }

    private function normalize(Decimal|string|int|float $value): Decimal
    {
        if ($value instanceof Decimal) {
            return $value;
        }
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}

