<?php

namespace Database\Seeders;

use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SymbolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $symbols = [
            ['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true],
            ['base' => 'ETH', 'quote' => 'USDT', 'enabled' => true],
            ['base' => 'BNB', 'quote' => 'USDT', 'enabled' => true],
            ['base' => 'SOL', 'quote' => 'USDT', 'enabled' => true],
        ];

        foreach ($symbols as $symbol) {
            Symbol::firstOrCreate(
                ['base' => $symbol['base'], 'quote' => $symbol['quote']],
                $symbol
            );
        }
    }
}

