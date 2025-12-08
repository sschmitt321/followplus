<?php

namespace App\Console\Commands;

use App\Models\RefReward;
use App\Models\RefStat;
use App\Support\Decimal;
use Illuminate\Console\Command;

class FixRefTotalRewards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ref:fix-total-rewards 
                            {--dry-run : 只显示将要修改的数据，不实际修改}
                            {--user-id= : 只修复指定用户的 total_rewards}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '修复 total_rewards 字段：只统计因邀请别人产生的奖励，排除 newbie_next_day 奖励';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user-id');

        if ($dryRun) {
            $this->info('🔍 预览模式：只显示将要修改的数据，不会实际修改数据库');
        } else {
            $this->warn('⚠️  将实际修改数据库，请确认！');
            if (!$this->confirm('确定要继续吗？', false)) {
                $this->info('操作已取消');
                return Command::SUCCESS;
            }
        }

        $this->info('开始修复 total_rewards...');
        $this->newLine();

        // 获取需要修复的用户
        $query = RefStat::query();
        if ($userId) {
            $query->where('user_id', $userId);
        }
        $stats = $query->get();

        $fixedCount = 0;
        $totalFixed = Decimal::zero();

        foreach ($stats as $stat) {
            // 计算应该计入 total_rewards 的奖励总和
            $shouldCountRewards = RefReward::where('user_id', $stat->user_id)
                ->where('status', 'confirmed')
                ->whereIn('type', [
                    'referral_10pct',
                    'notifier_5pct',
                    'upline_5pct',
                    'ambassador_oneoff',
                    'dividend',
                ])
                ->get();

            $correctTotal = Decimal::zero();
            foreach ($shouldCountRewards as $reward) {
                $correctTotal = $correctTotal->add($reward->amount);
            }

            // 获取当前的 total_rewards
            $currentTotal = $stat->total_rewards instanceof Decimal 
                ? $stat->total_rewards 
                : Decimal::of($stat->total_rewards ?? 0);

            // 如果不同，需要修复
            if (!$currentTotal->equals($correctTotal)) {
                $diff = $currentTotal->subtract($correctTotal);
                
                $this->line("用户 ID: {$stat->user_id}");
                $this->line("  当前 total_rewards: " . $currentTotal->toFixed(6));
                $this->line("  正确的 total_rewards: " . $correctTotal->toFixed(6));
                $this->line("  差异: " . $diff->toFixed(6));
                
                // 显示需要排除的奖励
                $excludedRewards = RefReward::where('user_id', $stat->user_id)
                    ->where('status', 'confirmed')
                    ->where('type', 'newbie_next_day')
                    ->get();
                
                if ($excludedRewards->isNotEmpty()) {
                    $this->line("  排除的 newbie_next_day 奖励:");
                    foreach ($excludedRewards as $reward) {
                        $this->line("    - 奖励ID {$reward->id}: " . $reward->amount->toFixed(6) . " USDT");
                    }
                }
                
                $this->newLine();

                if (!$dryRun) {
                    $stat->update(['total_rewards' => $correctTotal]);
                    $fixedCount++;
                    $totalFixed = $totalFixed->add($diff->abs());
                } else {
                    $fixedCount++;
                    $totalFixed = $totalFixed->add($diff->abs());
                }
            }
        }

        $this->newLine();
        if ($fixedCount > 0) {
            if ($dryRun) {
                $this->info("预览完成：将修复 {$fixedCount} 个用户的 total_rewards，总差异: " . $totalFixed->toFixed(6) . " USDT");
                $this->info("运行命令时不加 --dry-run 参数将实际执行修复");
            } else {
                $this->info("✓ 修复完成：已修复 {$fixedCount} 个用户的 total_rewards，总差异: " . $totalFixed->toFixed(6) . " USDT");
            }
        } else {
            $this->info("✓ 所有用户的 total_rewards 都是正确的，无需修复");
        }

        return Command::SUCCESS;
    }
}

