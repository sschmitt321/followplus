<?php

namespace App\Console\Commands;

use App\Models\FollowOrder;
use App\Models\User;
use Illuminate\Console\Command;

class CheckUserFollowOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'follow:check-user 
                            {user : 用户标识（邮箱或用户ID）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查用户的跟单情况，确定最新的跟单金额';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userIdentifier = $this->argument('user');

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
        $this->info("用户信息:");
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

        // 查询所有跟单订单
        $orders = FollowOrder::where('user_id', $user->id)
            ->with(['followWindow', 'symbol'])
            ->orderBy('created_at', 'desc')
            ->get();

        if ($orders->isEmpty()) {
            $this->warn("\n该用户没有任何跟单订单");
            return Command::SUCCESS;
        }

        // 检查是否有进行中的订单
        $ongoingOrders = $orders->where('status', 'placed');
        $hasOngoingOrder = $ongoingOrders->isNotEmpty();

        $this->info("\n跟单订单统计:");
        $this->table(
            ['统计项', '值'],
            [
                ['总订单数', $orders->count()],
                ['进行中订单', $ongoingOrders->count()],
                ['已结算订单', $orders->where('status', 'settled')->count()],
                ['已过期订单', $orders->where('status', 'expired')->count()],
            ]
        );

        // 显示最新的订单（按创建时间排序）
        $latestOrder = $orders->first();
        
        $this->info("\n最新跟单订单:");
        $this->table(
            ['字段', '值'],
            [
                ['订单ID', $latestOrder->id],
                ['交易对', $latestOrder->symbol->name ?? 'N/A'],
                ['窗口类型', $latestOrder->followWindow->window_type ?? 'N/A'],
                ['跟单金额 (amount_base)', $latestOrder->amount_base->toFixed(6)],
                ['用户输入金额 (amount_input)', $latestOrder->amount_input ? $latestOrder->amount_input->toFixed(6) : 'N/A'],
                ['状态', $latestOrder->status],
                ['利润', $latestOrder->profit ? $latestOrder->profit->toFixed(6) : 'N/A'],
                ['邀请码', $latestOrder->invite_token ?? 'N/A'],
                ['创建时间', $latestOrder->created_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s')],
                ['结算时间', $latestOrder->settled_at ? $latestOrder->settled_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') : 'N/A'],
            ]
        );

        // 如果有进行中的订单，特别标注
        if ($hasOngoingOrder) {
            $this->warn("\n⚠️  该用户有进行中的跟单订单（status='placed'），无法创建新的跟单");
            $this->info("\n进行中的订单列表:");
            $ongoingTableData = [];
            foreach ($ongoingOrders as $order) {
                $ongoingTableData[] = [
                    $order->id,
                    $order->symbol->name ?? 'N/A',
                    $order->amount_base->toFixed(6),
                    $order->created_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                ];
            }
            $this->table(
                ['订单ID', '交易对', '跟单金额', '创建时间'],
                $ongoingTableData
            );
        } else {
            $this->info("\n✓ 该用户当前没有进行中的跟单订单，可以创建新订单");
        }

        // 显示所有订单列表（最近10条）
        $this->info("\n最近10条跟单订单:");
        $recentOrders = $orders->take(10);
        $ordersTableData = [];
        foreach ($recentOrders as $order) {
            $ordersTableData[] = [
                $order->id,
                $order->symbol->name ?? 'N/A',
                $order->amount_base->toFixed(6),
                $order->status,
                $order->profit ? $order->profit->toFixed(6) : 'N/A',
                $order->created_at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            ];
        }
        $this->table(
            ['订单ID', '交易对', '跟单金额', '状态', '利润', '创建时间'],
            $ordersTableData
        );

        // 总结
        $this->info("\n总结:");
        $this->line("最新跟单金额: " . $latestOrder->amount_base->toFixed(6) . " USDT");
        if ($hasOngoingOrder) {
            $this->warn("状态: 有进行中的订单，无法创建新订单");
        } else {
            $this->info("状态: 可以创建新订单");
        }

        return Command::SUCCESS;
    }
}

