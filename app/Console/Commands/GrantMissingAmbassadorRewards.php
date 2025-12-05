<?php

namespace App\Console\Commands;

use App\Models\RefReward;
use App\Models\RefStat;
use App\Models\User;
use App\Services\Referral\RewardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GrantMissingAmbassadorRewards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:grant-missing-ambassador-rewards 
                            {--user-id= : 指定用户ID（可选，如果指定则只处理该用户）}
                            {--dry-run : 仅显示将要补发的用户，不实际执行}
                            {--force : 跳过确认提示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '扫描并补发已升级但未收到奖励的代言人奖励';

    /**
     * Execute the console command.
     */
    public function handle(RewardService $rewardService): int
    {
        $userId = $this->option('user-id');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('  扫描并补发代言人升级奖励');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        if ($dryRun) {
            $this->warn('⚠️  运行在 DRY-RUN 模式，不会实际修改数据');
            $this->newLine();
        }

        // 查找所有有代言人等级的用户（L1-L5）
        $query = RefStat::whereIn('ambassador_level', ['L1', 'L2', 'L3', 'L4', 'L5']);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        $ambassadors = $query->get();

        $this->info("找到 {$ambassadors->count()} 个代言人用户");
        $this->newLine();

        if ($ambassadors->isEmpty()) {
            $this->info('✓ 没有需要检查的代言人用户');
            return Command::SUCCESS;
        }

        // 检查每个代言人是否已收到对应等级的奖励
        $missingRewards = [];
        $rewardAmounts = [
            'L1' => 50,
            'L2' => 200,
            'L3' => 500,
            'L4' => 1500,
            'L5' => 3000,
        ];

        foreach ($ambassadors as $stat) {
            $level = $stat->ambassador_level;
            $bizId = "ambassador_{$level}_{$stat->user_id}";
            
            // 检查是否已有该等级的奖励记录
            $existingReward = RefReward::where('user_id', $stat->user_id)
                ->where('type', 'ambassador_oneoff')
                ->where('biz_id', $bizId)
                ->where('status', 'confirmed')
                ->first();
            
            if (!$existingReward) {
                $user = User::find($stat->user_id);
                $missingRewards[] = [
                    'user_id' => $stat->user_id,
                    'phone' => $user ? $user->phone : 'N/A',
                    'email' => $user ? $user->email : 'N/A',
                    'level' => $level,
                    'expected_amount' => $rewardAmounts[$level] ?? 0,
                    'current_reward_total' => $stat->ambassador_reward_total instanceof \App\Support\Decimal 
                        ? $stat->ambassador_reward_total->toString() 
                        : $stat->ambassador_reward_total,
                ];
            }
        }

        if (empty($missingRewards)) {
            $this->info('✓ 所有代言人都已收到对应等级的奖励');
            return Command::SUCCESS;
        }

        // 显示需要补发的用户
        $this->warn("⚠️  发现 " . count($missingRewards) . " 个用户需要补发奖励：");
        $this->newLine();

        $this->table(
            ['用户ID', '手机号', '邮箱', '等级', '应发放金额', '当前奖励总额'],
            array_map(function ($item) {
                return [
                    $item['user_id'],
                    $item['phone'] ?? 'N/A',
                    $item['email'] ?? 'N/A',
                    $item['level'],
                    $item['expected_amount'] . ' USDT',
                    $item['current_reward_total'] . ' USDT',
                ];
            }, $missingRewards)
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('✓ DRY-RUN 模式：以上用户将被补发奖励');
            return Command::SUCCESS;
        }

        // 确认操作
        if (!$force) {
            $this->warn('⚠️  警告：这将为以上用户补发代言人升级奖励');
            if (!$this->confirm('确定要继续吗？', false)) {
                $this->info('操作已取消');
                return Command::SUCCESS;
            }
        }

        // 执行补发
        $this->info('正在补发奖励...');
        $this->newLine();

        $grantedCount = 0;
        $errorCount = 0;

        foreach ($missingRewards as $item) {
            try {
                DB::transaction(function () use ($item, $rewardService, &$grantedCount) {
                    // 调用奖励发放方法（会自动检查是否已发放）
                    $rewardService->grantAmbassadorOneOff($item['user_id'], $item['level']);
                    
                    $grantedCount++;
                    
                    // 记录日志
                    Log::info('GrantMissingAmbassadorRewards: 补发代言人奖励', [
                        'user_id' => $item['user_id'],
                        'level' => $item['level'],
                        'amount' => $item['expected_amount'],
                    ]);

                    $this->info("  ✓ 用户 {$item['user_id']} ({$item['phone']}): {$item['level']} - {$item['expected_amount']} USDT");
                });
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("  ✗ 用户 {$item['user_id']}: {$e->getMessage()}");
                Log::error('GrantMissingAmbassadorRewards: 补发失败', [
                    'user_id' => $item['user_id'],
                    'level' => $item['level'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("✓ 扫描完成：成功补发 {$grantedCount} 个用户，失败 {$errorCount} 个");
        $this->info('═══════════════════════════════════════════════════════════');

        return Command::SUCCESS;
    }
}

