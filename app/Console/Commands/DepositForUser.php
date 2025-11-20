<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Deposit\DepositService;
use Illuminate\Console\Command;

class DepositForUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deposit:for-user 
                            {user : 用户标识（邮箱或用户ID）}
                            {amount : 充值金额}
                            {currency=USDT : 币种代码（默认：USDT）}
                            {--force : 跳过确认提示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '为用户手动充值（用于测试或管理）';

    /**
     * Execute the console command.
     */
    public function handle(DepositService $depositService): int
    {
        $userIdentifier = $this->argument('user');
        $amount = $this->argument('amount');
        $currency = $this->argument('currency');
        $force = $this->option('force');

        // 验证金额
        if (!is_numeric($amount) || $amount <= 0) {
            $this->error("无效的金额: {$amount}");
            return Command::FAILURE;
        }

        // 验证币种是否存在
        $currencyModel = Currency::where('name', $currency)->first();
        if (!$currencyModel) {
            $this->error("币种不存在: {$currency}");
            $this->info("可用的币种:");
            $currencies = Currency::where('enabled', true)->pluck('name')->toArray();
            $this->line(implode(', ', $currencies));
            return Command::FAILURE;
        }

        // 查找用户
        $user = null;
        if (is_numeric($userIdentifier)) {
            $user = User::find($userIdentifier);
        } else {
            $user = User::where('email', $userIdentifier)->first();
        }

        if (!$user) {
            $this->error("用户不存在: {$userIdentifier}");
            return Command::FAILURE;
        }

        // 显示用户信息
        $this->info("找到用户:");
        $this->table(
            ['字段', '值'],
            [
                ['ID', $user->id],
                ['邮箱', $user->email],
                ['角色', $user->role],
                ['状态', $user->status],
                ['创建时间', $user->created_at->format('Y-m-d H:i:s')],
            ]
        );

        // 检查并创建 contract 账户（如果不存在）
        $contractAccount = Account::where([
            'user_id' => $user->id,
            'type' => 'contract',
            'currency' => $currency,
        ])->first();

        if (!$contractAccount) {
            $this->info("\n创建 contract 账户...");
            $contractAccount = Account::create([
                'user_id' => $user->id,
                'type' => 'contract',
                'currency' => $currency,
                'available' => '0',
                'frozen' => '0',
            ]);
            $this->info("✓ Contract 账户已创建");
        } else {
            $this->info("\n✓ Contract 账户已存在");
        }

        // 显示充值信息
        $this->info("\n充值信息:");
        $this->table(
            ['字段', '值'],
            [
                ['币种', $currency],
                ['金额', number_format((float) $amount, 6, '.', '')],
                ['状态', '将立即确认并到账'],
            ]
        );

        // 确认操作
        if (!$force) {
            if (!$this->confirm("确定要为该用户充值吗？", false)) {
                $this->info("操作已取消");
                return Command::SUCCESS;
            }
        }

        try {
            // 执行充值
            $deposit = $depositService->manualApply(
                $user->id,
                $currency,
                $amount
            );

            // 显示成功信息
            $this->info("\n✓ 充值成功！");
            $this->table(
                ['字段', '值'],
                [
                    ['充值记录ID', $deposit->id],
                    ['用户ID', $deposit->user_id],
                    ['币种', $deposit->currency],
                    ['金额', $deposit->amount->toFixed(6)],
                    ['状态', $deposit->status],
                    ['确认时间', $deposit->confirmed_at?->format('Y-m-d H:i:s') ?? 'N/A'],
                    ['创建时间', $deposit->created_at->format('Y-m-d H:i:s')],
                ]
            );

            // 检查是否是首次充值（会触发邀请奖励）
            $isFirstDeposit = Deposit::where('user_id', $user->id)
                ->where('status', 'confirmed')
                ->count() === 1;

            if ($isFirstDeposit) {
                $this->info("\nℹ 这是该用户的首次充值，已自动触发邀请奖励。");
            }

            // 显示账户信息
            $spotAccount = Account::where([
                'user_id' => $user->id,
                'type' => 'spot',
                'currency' => $currency,
            ])->first();

            $contractAccount = Account::where([
                'user_id' => $user->id,
                'type' => 'contract',
                'currency' => $currency,
            ])->first();

            $this->info("\n账户余额:");
            $this->table(
                ['账户类型', '币种', '可用余额', '冻结余额'],
                [
                    [
                        'Spot',
                        $currency,
                        $spotAccount ? $spotAccount->available->toFixed(6) : '0.000000',
                        $spotAccount ? $spotAccount->frozen->toFixed(6) : '0.000000',
                    ],
                    [
                        'Contract',
                        $currency,
                        $contractAccount ? $contractAccount->available->toFixed(6) : '0.000000',
                        $contractAccount ? $contractAccount->frozen->toFixed(6) : '0.000000',
                    ],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("\n✗ 充值失败: {$e->getMessage()}");
            $this->error("错误详情: " . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }
    }
}

