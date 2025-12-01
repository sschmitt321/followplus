<?php

namespace App\Console\Commands;

use App\Models\RefStat;
use App\Models\User;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;

class RecalcReferralStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:recalc-stats 
                            {user_id? : 用户ID（可选，如果指定则只重算该用户及其上级）}
                            {--all : 重算所有用户的统计数据}
                            {--force : 跳过确认提示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重新计算邀请统计数据（direct_count, team_count, ambassador_level）';

    /**
     * Execute the console command.
     */
    public function handle(ReferralService $referralService): int
    {
        $userId = $this->argument('user_id');
        $all = $this->option('all');
        $force = $this->option('force');

        // 参数验证
        if ($userId && $all) {
            $this->error('不能同时指定 user_id 和 --all 选项');
            return Command::FAILURE;
        }

        if (!$userId && !$all) {
            $this->error('请指定 user_id 或使用 --all 选项重算所有用户');
            $this->info('使用示例:');
            $this->info('  php artisan referral:recalc-stats 3          # 重算用户3及其上级');
            $this->info('  php artisan referral:recalc-stats --all      # 重算所有用户');
            return Command::FAILURE;
        }

        // 重算所有用户
        if ($all) {
            if (!$force) {
                $this->warn('⚠️  警告：这将重新计算所有用户的统计数据，可能耗时较长！');
                if (!$this->confirm('确定要继续吗？', false)) {
                    $this->info('操作已取消');
                    return Command::SUCCESS;
                }
            }

            $this->info('开始重新计算所有用户的统计数据...');
            $users = User::all();
            $total = $users->count();
            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $successCount = 0;
            $errorCount = 0;

            foreach ($users as $user) {
                try {
                    $referralService->recalcTeamStats($user->id);
                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->newLine();
                    $this->error("用户 {$user->id} 重算失败: {$e->getMessage()}");
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info("✓ 完成！成功: {$successCount}, 失败: {$errorCount}, 总计: {$total}");
            return Command::SUCCESS;
        }

        // 重算指定用户
        if (!is_numeric($userId)) {
            $this->error("无效的用户ID: {$userId}");
            return Command::FAILURE;
        }

        $userId = (int) $userId;
        $user = User::find($userId);

        if (!$user) {
            $this->error("用户不存在: {$userId}");
            return Command::FAILURE;
        }

        // 显示用户信息
        $this->info("找到用户:");
        $this->table(
            ['字段', '值'],
            [
                ['ID', $user->id],
                ['邀请码', $user->invite_code],
                ['邀请路径', $user->ref_path],
                ['邀请深度', $user->ref_depth],
                ['直接邀请人ID', $user->invited_by_user_id ?? '无'],
            ]
        );

        // 显示当前统计数据
        $currentStat = RefStat::where('user_id', $userId)->first();
        if ($currentStat) {
            $this->info("当前统计数据:");
            $this->table(
                ['字段', '值'],
                [
                    ['直接邀请人数', $currentStat->direct_count],
                    ['直接邀请人数（已激活）', $currentStat->direct_active_count ?? 0],
                    ['团队总人数', $currentStat->team_count],
                    ['团队总人数（已激活）', $currentStat->team_active_count ?? 0],
                    ['大使等级', $currentStat->ambassador_level],
                    ['分红比例', $currentStat->dividend_rate],
                ]
            );
        } else {
            $this->warn("该用户暂无统计数据，将创建新的统计记录");
        }

        // 确认操作
        if (!$force) {
            $this->info("将重新计算用户 {$userId} 及其所有上级的统计数据");
            if (!$this->confirm('确定要继续吗？', true)) {
                $this->info('操作已取消');
                return Command::SUCCESS;
            }
        }

        try {
            $this->info("正在重新计算统计数据...");
            
            // 执行重算
            $referralService->recalcTeamStats($userId);

            // 显示更新后的统计数据
            $updatedStat = RefStat::where('user_id', $userId)->first();
            
            if ($updatedStat) {
                $this->info("✓ 重算完成！更新后的统计数据:");
                $this->table(
                    ['字段', '旧值', '新值'],
                    [
                        [
                            '直接邀请人数',
                            $currentStat->direct_count ?? 0,
                            $updatedStat->direct_count,
                        ],
                        [
                            '直接邀请人数（已激活）',
                            $currentStat->direct_active_count ?? 0,
                            $updatedStat->direct_active_count ?? 0,
                        ],
                        [
                            '团队总人数',
                            $currentStat->team_count ?? 0,
                            $updatedStat->team_count,
                        ],
                        [
                            '团队总人数（已激活）',
                            $currentStat->team_active_count ?? 0,
                            $updatedStat->team_active_count ?? 0,
                        ],
                        [
                            '大使等级',
                            $currentStat->ambassador_level ?? 'L0',
                            $updatedStat->ambassador_level,
                        ],
                        [
                            '分红比例',
                            $currentStat->dividend_rate ?? 0,
                            $updatedStat->dividend_rate,
                        ],
                    ]
                );

                // 检查是否有等级变化
                if ($currentStat && $currentStat->ambassador_level !== $updatedStat->ambassador_level) {
                    $this->info("🎉 用户等级已从 {$currentStat->ambassador_level} 升级到 {$updatedStat->ambassador_level}！");
                }
            } else {
                $this->info("✓ 重算完成！已创建新的统计记录");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("重算失败: {$e->getMessage()}");
            $this->error("错误详情: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

