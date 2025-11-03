<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            ['name' => 'USDT', 'precision' => 2, 'enabled' => true],
            ['name' => 'BTC', 'precision' => 8, 'enabled' => true],
            ['name' => 'ETH', 'precision' => 6, 'enabled' => true],
            ['name' => 'USDC', 'precision' => 2, 'enabled' => true],
        ];

        foreach ($currencies as $currency) {
            Currency::firstOrCreate(
                ['name' => $currency['name']],
                $currency
            );
        }

        $this->command->info('Currencies seeded successfully');
    }
}
