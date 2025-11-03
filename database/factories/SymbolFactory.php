<?php

namespace Database\Factories;

use App\Models\Symbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Symbol>
 */
class SymbolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base' => $this->faker->randomElement(['BTC', 'ETH', 'BNB', 'SOL']),
            'quote' => 'USDT',
            'enabled' => true,
        ];
    }

    /**
     * Indicate that the symbol is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}

