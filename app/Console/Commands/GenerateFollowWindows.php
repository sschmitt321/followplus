<?php

namespace App\Console\Commands;

use App\Models\FollowWindow;
use App\Models\InviteToken;
use App\Models\Symbol;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateFollowWindows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'follow:generate-windows {date?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate follow windows for a date (default: today)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $targetDate = \Carbon\Carbon::parse($date);

        $this->info("Generating follow windows for {$date}...");

        // Get enabled symbols
        $symbols = Symbol::where('enabled', true)->get();

        if ($symbols->isEmpty()) {
            $this->error('No enabled symbols found');
            return Command::FAILURE;
        }

        $fixedHours = [13, 20]; // Fixed windows at 13:00 and 20:00
        $bonusHours = [12, 14, 19, 21]; // Bonus windows at 12:00, 14:00, 19:00, 21:00

        $windowCount = 0;
        $tokenCount = 0;

        foreach ($symbols as $symbol) {
            // Generate fixed windows
            foreach ($fixedHours as $hour) {
                $startAt = $targetDate->copy()->setTime($hour, 0, 0);
                $expireAt = $startAt->copy()->addHours(1); // 1 hour window

                $window = FollowWindow::create([
                    'symbol_id' => $symbol->id,
                    'window_type' => 'fixed_daily',
                    'start_at' => $startAt,
                    'expire_at' => $expireAt,
                    'reward_rate_min' => 0.5,
                    'reward_rate_max' => 0.6,
                    'status' => 'active',
                ]);

                // Generate invite token (same token for all users)
                $token = $this->generateToken();
                InviteToken::create([
                    'follow_window_id' => $window->id,
                    'token' => $token,
                    'valid_after' => $startAt,
                    'valid_before' => $expireAt,
                    'symbol_id' => $symbol->id,
                ]);

                $windowCount++;
                $tokenCount++;
            }

            // Generate bonus windows
            foreach ($bonusHours as $hour) {
                $startAt = $targetDate->copy()->setTime($hour, 0, 0);
                $expireAt = $startAt->copy()->addHours(1);

                $window = FollowWindow::create([
                    'symbol_id' => $symbol->id,
                    'window_type' => 'newbie_bonus', // Can be changed to inviter_bonus based on logic
                    'start_at' => $startAt,
                    'expire_at' => $expireAt,
                    'reward_rate_min' => 0.5,
                    'reward_rate_max' => 0.6,
                    'status' => 'active',
                ]);

                // Generate invite token
                $token = $this->generateToken();
                InviteToken::create([
                    'follow_window_id' => $window->id,
                    'token' => $token,
                    'valid_after' => $startAt,
                    'valid_before' => $expireAt,
                    'symbol_id' => $symbol->id,
                ]);

                $windowCount++;
                $tokenCount++;
            }
        }

        $this->info("Generated {$windowCount} windows and {$tokenCount} tokens");

        return Command::SUCCESS;
    }

    /**
     * Generate a unique invite token.
     */
    private function generateToken(): string
    {
        return strtoupper(Str::random(8));
    }
}

