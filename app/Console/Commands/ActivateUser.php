<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;

class ActivateUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:activate-user 
                            {user : 用户ID、邮箱或手机号}
                            {--force : 跳过确认提示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '手动激活用户（将用户标记为已激活，即使累计充值未达到1000U）';

    /**
     * Execute the console command.
     */
    public function handle(ReferralService $referralService): int
    {
        $userIdentifier = $this->argument('user');
        $force = $this->option('force');

        // 查找用户
        $user = $this->findUser($userIdentifier);
        if (!$user) {
            $this->error("找不到用户: {$userIdentifier}");
            return Command::FAILURE;
        }

        // 显示用户信息
        $this->info("用户信息:");
        $this->table(
            ['字段', '值'],
            [
                ['ID', $user->id],
                ['邮箱', $user->email ?? 'N/A'],
                ['手机', $user->phone ?? 'N/A'],
                ['邀请码', $user->invite_code],
                ['邀请者ID', $user->invited_by_user_id ?? '无'],
            ]
        );

        // 检查当前激活状态
        $isActivated = $referralService->isUserActivated($user->id);
        $this->info("当前激活状态: " . ($isActivated ? "✓ 已激活" : "✗ 未激活"));

        // 计算累计充值金额
        $totalDeposits = \App\Models\Deposit::where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->where('currency', 'USDT')
            ->get()
            ->reduce(function ($carry, $deposit) {
                return $carry->add($deposit->amount);
            }, \App\Support\Decimal::zero());

        $this->info("累计充值金额: {$totalDeposits->toFixed(2)} USDT");
        $this->info("激活要求: 1000.00 USDT");

        if ($isActivated) {
            $this->warn("⚠️  用户已经激活，无需重复操作");
            return Command::SUCCESS;
        }

        // 确认操作
        if (!$force) {
            $this->warn("⚠️  警告：此操作将手动激活用户，即使累计充值未达到1000U");
            if (!$this->confirm("确定要继续吗？", false)) {
                $this->info("操作已取消");
                return Command::SUCCESS;
            }
        }

        try {
            // 创建一个虚拟充值记录来达到激活条件
            // 这个记录只用于激活判断，不会影响用户的实际余额
            $minAmount = \App\Support\Decimal::of(1000);
            $neededAmount = $minAmount->subtract($totalDeposits);
            
            if ($neededAmount->isPositive()) {
                // 创建虚拟充值记录（不通过 LedgerService，所以不影响余额）
                // 使用特殊的 TXID 标记这是手动激活
                $virtualDeposit = \App\Models\Deposit::create([
                    'user_id' => $user->id,
                    'currency' => 'USDT',
                    'amount' => $neededAmount,
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'txid' => 'MANUAL_ACTIVATION_' . time(),
                ]);

                $this->info("✓ 已创建虚拟充值记录: {$neededAmount->toFixed(2)} USDT（仅用于激活判断，不影响余额）");
            } else {
                // 如果累计充值已经达到或超过1000U，直接更新统计即可
                $this->info("✓ 用户累计充值已达到1000U，直接更新统计");
            }

            // 更新邀请者的统计数据
            if ($user->invited_by_user_id) {
                $referralService->recalcTeamStats($user->invited_by_user_id);
                $this->info("✓ 已更新邀请者统计数据");
            }

            // 验证激活状态
            $isNowActivated = $referralService->isUserActivated($user->id);
            if ($isNowActivated) {
                $this->info("✓ 用户已成功激活！");
                
                // 显示更新后的统计
                if ($user->invited_by_user_id) {
                    $inviterStat = \App\Models\RefStat::where('user_id', $user->invited_by_user_id)->first();
                    if ($inviterStat) {
                        $this->info("邀请者更新后的统计:");
                        $this->table(
                            ['字段', '值'],
                            [
                                ['直接邀请人数（已激活）', $inviterStat->direct_active_count ?? 0],
                                ['团队总人数（已激活）', $inviterStat->team_active_count ?? 0],
                            ]
                        );
                    }
                }
            } else {
                $this->error("✗ 激活失败，请检查日志");
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("激活失败: {$e->getMessage()}");
            $this->error("错误详情: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Find user by ID, email, phone, or invite code.
     */
    private function findUser(string $identifier): ?User
    {
        // Try as ID first
        if (is_numeric($identifier)) {
            $user = User::find((int) $identifier);
            if ($user) {
                return $user;
            }
        }

        // Try as email
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $identifier)->first();
            if ($user) {
                return $user;
            }
        }

        // Try as phone
        $user = User::where('phone', $identifier)->first();
        if ($user) {
            return $user;
        }

        // Try as invite code
        $user = User::where('invite_code', $identifier)->first();
        if ($user) {
            return $user;
        }

        return null;
    }
}

