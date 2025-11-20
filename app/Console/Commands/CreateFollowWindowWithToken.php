<?php

namespace App\Console\Commands;

use App\Models\FollowWindow;
use App\Models\InviteToken;
use App\Models\Symbol;
use App\Services\Audit\AuditService;
use App\Support\TimeHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateFollowWindowWithToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'follow:create-window-with-token
                            {symbol_id : 交易对ID (1=BTC/USDT, 2=ETH/USDT, 3=BNB/USDT, 4=SOL/USDT)}
                            {window_type : 窗口类型 (fixed_daily/newbie_bonus/inviter_bonus)}
                            {start_time : 开始时间 (格式: YYYY-MM-DD HH:MM:SS 或 YYYY-MM-DD)}
                            {duration_hours : 持续时间（小时）}
                            {--token= : 自定义邀请码（可选，不提供则自动生成）}
                            {--reward-min=0.5 : 最小奖励率 (0-1)}
                            {--reward-max=0.6 : 最大奖励率 (0-1)}
                            {--auto-token : 自动生成邀请码（默认启用）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '创建跟单窗口并自动生成邀请码';

    /**
     * Execute the console command.
     */
    public function handle(AuditService $auditService): int
    {
        $symbolId = (int) $this->argument('symbol_id');
        $windowType = $this->argument('window_type');
        $startTime = $this->argument('start_time');
        $durationHours = (float) $this->argument('duration_hours');
        $customToken = $this->option('token');
        $rewardMin = (float) $this->option('reward-min');
        $rewardMax = (float) $this->option('reward-max');

        // 验证交易对
        $symbol = Symbol::find($symbolId);
        if (!$symbol) {
            $this->error("交易对 ID {$symbolId} 不存在");
            return Command::FAILURE;
        }

        // 验证窗口类型
        if (!in_array($windowType, ['fixed_daily', 'newbie_bonus', 'inviter_bonus'])) {
            $this->error("无效的窗口类型: {$windowType}");
            $this->info("支持的窗口类型: fixed_daily, newbie_bonus, inviter_bonus");
            return Command::FAILURE;
        }

        // 解析开始时间（按 UTC+8 时区处理，然后转换为 UTC 存储）
        try {
            // 如果只提供了日期，默认使用当前 UTC+8 时间
            if (strlen($startTime) === 10) {
                $startTime = $startTime . ' ' . TimeHelper::now()->format('H:i:s');
            }
            // 将输入时间解析为 UTC+8 时区，然后转换为 UTC 存储
            $startAt = TimeHelper::createFromFormat('Y-m-d H:i:s', $startTime)->utc();
        } catch (\Exception $e) {
            $this->error("无效的开始时间格式: {$startTime}");
            $this->info("请使用格式: YYYY-MM-DD HH:MM:SS 或 YYYY-MM-DD");
            $this->info("注意：输入时间按 UTC+8（中国时区）处理");
            return Command::FAILURE;
        }

        // 计算过期时间
        $expireAt = $startAt->copy()->addHours($durationHours);

        // 验证奖励率
        if ($rewardMin < 0 || $rewardMin > 1 || $rewardMax < 0 || $rewardMax > 1) {
            $this->error("奖励率必须在 0-1 之间");
            return Command::FAILURE;
        }

        if ($rewardMax < $rewardMin) {
            $this->error("最大奖励率必须大于等于最小奖励率");
            return Command::FAILURE;
        }

        // 显示信息（显示 UTC+8 时间）
        $this->info("创建跟单窗口...");
        $startAtUtc8 = $startAt->copy()->setTimezone('Asia/Shanghai');
        $expireAtUtc8 = $expireAt->copy()->setTimezone('Asia/Shanghai');
        $this->table(
            ['字段', '值'],
            [
                ['交易对', "{$symbol->base}/{$symbol->quote} (ID: {$symbolId})"],
                ['窗口类型', $windowType],
                ['开始时间', $startAtUtc8->format('Y-m-d H:i:s') . ' (UTC+8)'],
                ['过期时间', $expireAtUtc8->format('Y-m-d H:i:s') . ' (UTC+8)'],
                ['持续时间', "{$durationHours} 小时"],
                ['奖励率', "{$rewardMin} - {$rewardMax}"],
            ]
        );

        try {
            // 创建窗口
            $window = FollowWindow::create([
                'symbol_id' => $symbolId,
                'window_type' => $windowType,
                'start_at' => $startAt,
                'expire_at' => $expireAt,
                'reward_rate_min' => $rewardMin,
                'reward_rate_max' => $rewardMax,
                'status' => 'active',
            ]);

            $this->info("✓ 跟单窗口创建成功！窗口 ID: {$window->id}");

            // 创建邀请码
            $token = $customToken ?? strtoupper(Str::random(8));
            
            $inviteToken = InviteToken::create([
                'follow_window_id' => $window->id,
                'token' => $token,
                'valid_after' => $startAt,
                'valid_before' => $expireAt,
                'symbol_id' => $symbolId,
            ]);

            $this->info("✓ 邀请码创建成功！");
            $this->newLine();
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("  跟单窗口和邀请码创建完成");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->newLine();
            
            $this->table(
                ['项目', '值'],
                [
                    ['窗口 ID', $window->id],
                    ['交易对', "{$symbol->base}/{$symbol->quote}"],
                    ['窗口类型', $windowType],
                    ['开始时间', $startAtUtc8->format('Y-m-d H:i:s') . ' (UTC+8)'],
                    ['过期时间', $expireAtUtc8->format('Y-m-d H:i:s') . ' (UTC+8)'],
                    ['邀请码', $token],
                    ['邀请码生效时间', $startAtUtc8->format('Y-m-d H:i:s') . ' (UTC+8)'],
                    ['邀请码失效时间', $expireAtUtc8->format('Y-m-d H:i:s') . ' (UTC+8)'],
                ]
            );

            // 记录审计日志
            $auditService->log(
                1, // 系统用户 ID（命令行操作）
                'follow_window_create',
                'follow_window',
                null,
                $window->toArray()
            );

            $auditService->log(
                1,
                'invite_token_create',
                'invite_token',
                null,
                $inviteToken->toArray()
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("创建失败: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

