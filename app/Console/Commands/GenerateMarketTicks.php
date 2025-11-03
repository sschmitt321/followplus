<?php

namespace App\Console\Commands;

use App\Services\Market\MarketService;
use Illuminate\Console\Command;

class GenerateMarketTicks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:generate-ticks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate fake market tick data for all enabled symbols';

    /**
     * Execute the console command.
     */
    public function handle(MarketService $marketService): int
    {
        $this->info('Generating market ticks...');

        $count = $marketService->generateFakeTicks();

        $this->info("Generated {$count} tick(s) successfully.");

        return Command::SUCCESS;
    }
}

