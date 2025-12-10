<?php

namespace App\Console\Commands;

use App\Models\RefStat;
use App\Models\User;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckAmbassadorLevelCompliance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:check-level-compliance 
                            {--user-id= : 只检查指定用户}
                            {--level= : 只检查指定等级（L1-L5）}
                            {--dry-run : 仅显示不符合条件的用户，不实际降级}
                            {--auto-downgrade : 自动降级不符合条件的用户（需要确认）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查所有代言人用户是否符合当前等级要求，列出不符合条件的用户供管理员审核';

    /**
     * Execute the console command.
     */
    public function handle(ReferralService $referralService): int
    {
        $userId = $this->option('user-id');
        $level = $this->option('level');
        $dryRun = $this->option('dry-run');
        $autoDowngrade = $this->option('auto-downgrade');

        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('  检查代言人等级合规性');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        if ($dryRun) {
            $this->warn('⚠️  运行在 DRY-RUN 模式，不会实际修改数据');
            $this->newLine();
        }

        // 构建查询
        $query = RefStat::whereIn('ambassador_level', ['L1', 'L2', 'L3', 'L4', 'L5']);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if ($level) {
            if (!in_array($level, ['L1', 'L2', 'L3', 'L4', 'L5'])) {
                $this->error("无效的等级: {$level}，必须是 L1, L2, L3, L4, 或 L5");
                return Command::FAILURE;
            }
            $query->where('ambassador_level', $level);
        }

        $ambassadors = $query->get();

        $this->info("找到 {$ambassadors->count()} 个代言人用户需要检查");
        $this->newLine();

        if ($ambassadors->isEmpty()) {
            $this->info('✓ 没有需要检查的代言人用户');
            return Command::SUCCESS;
        }

        // 等级要求定义
        $levelRequirements = [
            'L1' => ['team_active' => 0, 'direct_active' => 3],      // L1: 3+ 直属激活下级（无团队要求）
            'L2' => ['team_active' => 20, 'direct_active' => 5],     // L2: 20+ 团队 AND 5+ 直属
            'L3' => ['team_active' => 50, 'direct_active' => 8],     // L3: 50+ 团队 AND 8+ 直属
            'L4' => ['team_active' => 200, 'direct_active' => 15],   // L4: 200+ 团队 AND 15+ 直属
            'L5' => ['team_active' => 500, 'direct_active' => 20],    // L5: 500+ 团队 AND 20+ 直属
        ];

        // 检查每个代言人
        $nonCompliant = [];
        foreach ($ambassadors as $stat) {
            $currentLevel = $stat->ambassador_level;
            $teamActiveCount = $stat->team_active_count ?? 0;
            $directActiveCount = $stat->direct_active_count ?? 0;
            
            $requirements = $levelRequirements[$currentLevel];
            
            // 检查是否符合当前等级要求
            $meetsTeamRequirement = $teamActiveCount >= $requirements['team_active'];
            $meetsDirectRequirement = $directActiveCount >= $requirements['direct_active'];
            $isCompliant = $meetsTeamRequirement && $meetsDirectRequirement;
            
            if (!$isCompliant) {
                // 计算应该的等级
                $expectedLevel = $this->calculateExpectedLevel($teamActiveCount, $directActiveCount);
                
                $user = User::find($stat->user_id);
                $nonCompliant[] = [
                    'user_id' => $stat->user_id,
                    'phone' => $user ? ($user->phone ?? 'N/A') : 'N/A',
                    'email' => $user ? ($user->email ?? 'N/A') : 'N/A',
                    'current_level' => $currentLevel,
                    'expected_level' => $expectedLevel,
                    'team_active_count' => $teamActiveCount,
                    'direct_active_count' => $directActiveCount,
                    'team_requirement' => $requirements['team_active'],
                    'direct_requirement' => $requirements['direct_active'],
                    'meets_team' => $meetsTeamRequirement,
                    'meets_direct' => $meetsDirectRequirement,
                ];
            }
        }

        if (empty($nonCompliant)) {
            $this->info('✓ 所有代言人都符合当前等级要求');
            return Command::SUCCESS;
        }

        // 显示不符合条件的用户
        $this->warn("⚠️  发现 " . count($nonCompliant) . " 个用户不符合当前等级要求：");
        $this->newLine();

        // 按等级分组显示
        $groupedByLevel = [];
        foreach ($nonCompliant as $item) {
            $level = $item['current_level'];
            if (!isset($groupedByLevel[$level])) {
                $groupedByLevel[$level] = [];
            }
            $groupedByLevel[$level][] = $item;
        }

        foreach ($groupedByLevel as $level => $items) {
            $this->info("【{$level} 级代言人】");
            $this->table(
                ['用户ID', '手机号', '邮箱', '当前等级', '应降级至', '团队激活数', '直属激活数', '团队要求', '直属要求', '不符合项'],
                array_map(function ($item) {
                    $issues = [];
                    if (!$item['meets_team']) {
                        $issues[] = '团队数不足';
                    }
                    if (!$item['meets_direct']) {
                        $issues[] = '直属数不足';
                    }
                    
                    return [
                        $item['user_id'],
                        $item['phone'] ?? 'N/A',
                        $item['email'] ?? 'N/A',
                        $item['current_level'],
                        $item['expected_level'],
                        $item['team_active_count'],
                        $item['direct_active_count'],
                        $item['team_requirement'] > 0 ? $item['team_requirement'] : '无要求',
                        $item['direct_requirement'],
                        implode(', ', $issues),
                    ];
                }, $items)
            );
            $this->newLine();
        }

        // 显示统计信息
        $this->info('统计信息：');
        $levelStats = [];
        foreach ($nonCompliant as $item) {
            $fromLevel = $item['current_level'];
            $toLevel = $item['expected_level'];
            $key = "{$fromLevel} → {$toLevel}";
            if (!isset($levelStats[$key])) {
                $levelStats[$key] = 0;
            }
            $levelStats[$key]++;
        }
        
        foreach ($levelStats as $change => $count) {
            $this->line("  {$change}: {$count} 人");
        }
        $this->newLine();

        if ($dryRun) {
            $this->info('✓ DRY-RUN 模式：以上用户将被降级到符合的最高等级');
            return Command::SUCCESS;
        }

        if (!$autoDowngrade) {
            $this->info('提示：使用 --auto-downgrade 选项可以自动降级不符合条件的用户');
            return Command::SUCCESS;
        }

        // 确认操作
        $this->warn('⚠️  警告：这将降级以上用户的等级到符合的最高等级');
        if (!$this->confirm('确定要继续吗？', false)) {
            $this->info('操作已取消');
            return Command::SUCCESS;
        }

        // 执行降级
        $this->info('正在降级不符合条件的用户...');
        $this->newLine();

        $downgradedCount = 0;
        $errorCount = 0;

        foreach ($nonCompliant as $item) {
            try {
                DB::transaction(function () use ($item, &$downgradedCount, $referralService) {
                    $stat = RefStat::lockForUpdate()->where('user_id', $item['user_id'])->first();
                    
                    if (!$stat) {
                        throw new \Exception("RefStat not found for user {$item['user_id']}");
                    }

                    $oldLevel = $stat->ambassador_level;
                    $newLevel = $item['expected_level'];
                    $oldDividendRate = $stat->dividend_rate;
                    
                    // 计算新等级的分红比例
                    $newDividendRate = $this->getDividendRateForLevel($newLevel);

                    // 更新等级和分红比例
                    $stat->update([
                        'ambassador_level' => $newLevel,
                        'dividend_rate' => $newDividendRate,
                    ]);

                    $downgradedCount++;

                    // 记录日志
                    Log::info('CheckAmbassadorLevelCompliance: 降级代言人等级', [
                        'user_id' => $item['user_id'],
                        'old_level' => $oldLevel,
                        'new_level' => $newLevel,
                        'old_dividend_rate' => $oldDividendRate,
                        'new_dividend_rate' => $newDividendRate,
                        'team_active_count' => $item['team_active_count'],
                        'direct_active_count' => $item['direct_active_count'],
                    ]);

                    $this->info("  ✓ 用户 {$item['user_id']} ({$item['phone']}): {$oldLevel} → {$newLevel}");
                });
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("  ✗ 用户 {$item['user_id']}: {$e->getMessage()}");
                Log::error('CheckAmbassadorLevelCompliance: 降级失败', [
                    'user_id' => $item['user_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("✓ 检查完成：成功降级 {$downgradedCount} 个用户，失败 {$errorCount} 个");
        $this->info('═══════════════════════════════════════════════════════════');

        return Command::SUCCESS;
    }

    /**
     * Calculate expected ambassador level based on active team count and active direct downlines.
     */
    private function calculateExpectedLevel(int $activeTeamCount, int $activeDirectCount): string
    {
        // Level 5 (Company Ambassador): 500+ team AND 20+ direct
        if ($activeTeamCount >= 500 && $activeDirectCount >= 20) {
            return 'L5';
        }
        // Level 4: 200+ team AND 15+ direct
        elseif ($activeTeamCount >= 200 && $activeDirectCount >= 15) {
            return 'L4';
        }
        // Level 3: 50+ team AND 8+ direct
        elseif ($activeTeamCount >= 50 && $activeDirectCount >= 8) {
            return 'L3';
        }
        // Level 2: 20+ team AND 5+ direct
        elseif ($activeTeamCount >= 20 && $activeDirectCount >= 5) {
            return 'L2';
        }
        // Level 1: 3+ direct (no team requirement)
        elseif ($activeDirectCount >= 3) {
            return 'L1';
        }
        
        return 'L0';
    }

    /**
     * Get dividend rate for ambassador level.
     */
    private function getDividendRateForLevel(string $level): float
    {
        return match ($level) {
            'L1' => 0.0050, // 0.5%
            'L2' => 0.0100, // 1.0%
            'L3' => 0.0150, // 1.5%
            'L4' => 0.0200, // 2.0%
            'L5' => 0.0250, // 2.5%
            default => 0.0,
        };
    }
}



