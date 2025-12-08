<?php

namespace App\Console\Commands;

use App\Models\RefStat;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RevokeAmbassadorBenefits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:revoke-ambassador-benefits 
                            {--dry-run : 仅显示将要撤销的用户，不实际执行}
                            {--force : 跳过确认提示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '扫描并撤销不符合条件的代言人福利（直属下级数低于3人时撤销）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('  扫描代言人福利撤销');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        if ($dryRun) {
            $this->warn('⚠️  运行在 DRY-RUN 模式，不会实际修改数据');
            $this->newLine();
        }

        // 查找所有有代言人等级的用户（L1-L5）
        $ambassadors = RefStat::whereIn('ambassador_level', ['L1', 'L2', 'L3', 'L4', 'L5'])
            ->get();

        $this->info("找到 {$ambassadors->count()} 个代言人用户");
        $this->newLine();

        if ($ambassadors->isEmpty()) {
            $this->info('✓ 没有需要检查的代言人用户');
            return Command::SUCCESS;
        }

        // 检查每个代言人
        $toRevoke = [];
        foreach ($ambassadors as $stat) {
            $directActiveCount = $stat->direct_active_count ?? 0;
            
            // 如果激活的直属下级数 < 3，需要撤销福利
            if ($directActiveCount < 3) {
                $user = User::find($stat->user_id);
                $toRevoke[] = [
                    'user_id' => $stat->user_id,
                    'phone' => $user ? $user->phone : 'N/A',
                    'email' => $user ? $user->email : 'N/A',
                    'current_level' => $stat->ambassador_level,
                    'direct_active_count' => $directActiveCount,
                    'dividend_rate' => $stat->dividend_rate,
                ];
            }
        }

        if (empty($toRevoke)) {
            $this->info('✓ 所有代言人都符合条件，无需撤销');
            return Command::SUCCESS;
        }

        // 显示需要撤销的用户
        $this->warn("⚠️  发现 " . count($toRevoke) . " 个用户需要撤销代言人福利：");
        $this->newLine();

        $this->table(
            ['用户ID', '手机号', '邮箱', '当前等级', '激活直属下级数', '分红比例'],
            array_map(function ($item) {
                return [
                    $item['user_id'],
                    $item['phone'] ?? 'N/A',
                    $item['email'] ?? 'N/A',
                    $item['current_level'],
                    $item['direct_active_count'],
                    number_format((float)$item['dividend_rate'] * 100, 2) . '%',
                ];
            }, $toRevoke)
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('✓ DRY-RUN 模式：以上用户将被撤销代言人福利');
            return Command::SUCCESS;
        }

        // 确认操作
        if (!$force) {
            $this->warn('⚠️  警告：这将撤销以上用户的代言人福利（等级降为 L0，分红比例设为 0）');
            if (!$this->confirm('确定要继续吗？', false)) {
                $this->info('操作已取消');
                return Command::SUCCESS;
            }
        }

        // 执行撤销
        $this->info('正在撤销代言人福利...');
        $this->newLine();

        $revokedCount = 0;
        $errorCount = 0;

        foreach ($toRevoke as $item) {
            try {
                DB::transaction(function () use ($item, &$revokedCount) {
                    $stat = RefStat::lockForUpdate()->where('user_id', $item['user_id'])->first();
                    
                    if (!$stat) {
                        throw new \Exception("RefStat not found for user {$item['user_id']}");
                    }

                    $oldLevel = $stat->ambassador_level;
                    $oldDividendRate = $stat->dividend_rate;

                    // 撤销代言人福利
                    $stat->update([
                        'ambassador_level' => 'L0',
                        'dividend_rate' => 0.0,
                    ]);

                    $revokedCount++;

                    // 记录日志
                    Log::info('RevokeAmbassadorBenefits: 撤销代言人福利', [
                        'user_id' => $item['user_id'],
                        'old_level' => $oldLevel,
                        'new_level' => 'L0',
                        'old_dividend_rate' => $oldDividendRate,
                        'new_dividend_rate' => 0.0,
                        'direct_active_count' => $item['direct_active_count'],
                    ]);

                    $this->info("  ✓ 用户 {$item['user_id']} ({$item['phone']}): {$oldLevel} → L0");
                });
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("  ✗ 用户 {$item['user_id']}: {$e->getMessage()}");
                Log::error('RevokeAmbassadorBenefits: 撤销失败', [
                    'user_id' => $item['user_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("✓ 扫描完成：成功撤销 {$revokedCount} 个用户，失败 {$errorCount} 个");
        $this->info('═══════════════════════════════════════════════════════════');

        return Command::SUCCESS;
    }
}

