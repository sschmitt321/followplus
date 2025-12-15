<?php

namespace App\Console\Commands;

use App\Models\UserKyc;
use App\Services\Audit\AuditService;
use App\Services\Kyc\KycService;
use Illuminate\Console\Command;

class ReviewKyc extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kyc:review 
                            {--id= : KYC记录ID（用于审核指定记录）}
                            {--action= : 审核操作（approve/reject）}
                            {--reason= : 审核原因（拒绝时必填）}
                            {--all : 列出所有KYC记录（包括已审核的）}
                            {--status= : 按状态筛选（pending/approved/rejected）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '批量查询和审核KYC身份认证数据';

    /**
     * Execute the console command.
     */
    public function handle(KycService $kycService, AuditService $auditService): int
    {
        $id = $this->option('id');
        $action = $this->option('action');
        $reason = $this->option('reason');
        $all = $this->option('all');
        $status = $this->option('status');

        // 如果指定了ID，执行审核操作
        if ($id) {
            return $this->reviewKycRecord($id, $action, $reason, $kycService, $auditService);
        }

        // 否则列出KYC记录（默认列出待审核的）
        return $this->listKycRecords($all, $status);
    }

    /**
     * 列出KYC记录
     */
    private function listKycRecords(bool $all = false, ?string $status = null): int
    {
        $query = UserKyc::with('user:id,email,phone', 'user.profile:id,user_id,name');

        // 默认只显示待审核的，除非指定了--all或--status
        if (!$all && !$status) {
            $query->where('status', 'pending');
        } elseif ($status) {
            if (!in_array($status, ['pending', 'approved', 'rejected'])) {
                $this->error("无效的状态: {$status}。有效值: pending, approved, rejected");
                return Command::FAILURE;
            }
            $query->where('status', $status);
        }

        $kycRecords = $query->orderBy('created_at', 'desc')->get();

        if ($kycRecords->isEmpty()) {
            $statusText = $status ? "状态为 {$status} 的" : '待审核的';
            $this->info("没有找到{$statusText}KYC记录。");
            return Command::SUCCESS;
        }

        // 准备表格数据
        $tableData = [];
        foreach ($kycRecords as $kyc) {
            $tableData[] = [
                'ID' => $kyc->id,
                '用户ID' => $kyc->user_id,
                '用户邮箱' => $kyc->user->email ?? 'N/A',
                '手机号' => $kyc->user->phone ?? 'N/A',
                '用户姓名' => $kyc->user->profile?->name ?? 'N/A',
                '等级' => $kyc->level,
                '状态' => $this->formatStatus($kyc->status),
                '提交时间' => $kyc->created_at->format('Y-m-d H:i:s'),
                '审核时间' => $kyc->updated_at->format('Y-m-d H:i:s'),
            ];
        }

        $this->info("\n找到 " . $kycRecords->count() . " 条KYC记录：\n");
        $this->table(
            ['ID', '用户ID', '用户邮箱', '手机号', '用户姓名', '等级', '状态', '提交时间', '审核时间'],
            $tableData
        );

        // 显示详细信息选项
        if ($kycRecords->count() > 0) {
            $this->info("\n提示：使用以下命令审核记录：");
            $this->line("  审核通过: php artisan kyc:review --id=<ID> --action=approve [--reason=\"审核原因\"]");
            $this->line("  审核拒绝: php artisan kyc:review --id=<ID> --action=reject --reason=\"拒绝原因\"");
        }

        return Command::SUCCESS;
    }

    /**
     * 审核KYC记录
     */
    private function reviewKycRecord(
        int $id,
        ?string $action,
        ?string $reason,
        KycService $kycService,
        AuditService $auditService
    ): int {
        $kyc = UserKyc::with('user:id,email,phone', 'user.profile:id,user_id,name')->find($id);

        if (!$kyc) {
            $this->error("KYC记录不存在: {$id}");
            return Command::FAILURE;
        }

        // 显示KYC记录详情
        $this->info("\nKYC记录详情：");
        $this->table(
            ['字段', '值'],
            [
                ['ID', $kyc->id],
                ['用户ID', $kyc->user_id],
                ['用户邮箱', $kyc->user->email ?? 'N/A'],
                ['手机号', $kyc->user->phone ?? 'N/A'],
                ['用户姓名', $kyc->user->profile?->name ?? 'N/A'],
                ['等级', $kyc->level],
                ['当前状态', $this->formatStatus($kyc->status)],
                ['提交时间', $kyc->created_at->format('Y-m-d H:i:s')],
                ['正面图片', $kyc->front_image_url ? '已上传' : '未上传'],
                ['背面图片', $kyc->back_image_url ? '已上传' : '未上传'],
                ['审核原因', $kyc->review_reason ?? 'N/A'],
            ]
        );

        // 检查状态
        if ($kyc->status !== 'pending') {
            $this->warn("\n警告：该KYC记录的状态为 '{$kyc->status}'，不是待审核状态。");
            if (!$this->confirm("是否仍要继续审核？", false)) {
                $this->info("操作已取消");
                return Command::SUCCESS;
            }
        }

        // 如果没有指定action，询问用户
        if (!$action) {
            $selected = $this->choice(
                '请选择审核操作',
                ['通过', '拒绝'],
                0
            );
            $action = $selected === '通过' ? 'approve' : 'reject';
        }

        // 验证action
        if (!in_array($action, ['approve', 'reject'])) {
            $this->error("无效的操作: {$action}。有效值: approve, reject");
            return Command::FAILURE;
        }

        // 如果是拒绝，必须提供原因
        if ($action === 'reject') {
            if (!$reason) {
                $reason = $this->ask('请输入拒绝原因（必填）');
                if (empty($reason)) {
                    $this->error("拒绝原因不能为空");
                    return Command::FAILURE;
                }
            }
        } else {
            // 如果是通过，原因可选
            if (!$reason) {
                $reason = $this->ask('请输入审核备注（可选，直接回车跳过）', '');
                if (empty($reason)) {
                    $reason = null;
                }
            }
        }

        // 确认操作
        $actionText = $action === 'approve' ? '通过' : '拒绝';
        $this->info("\n审核操作：{$actionText}");
        if ($reason) {
            $this->info("审核原因：{$reason}");
        }

        if (!$this->confirm("确定要执行此操作吗？", true)) {
            $this->info("操作已取消");
            return Command::SUCCESS;
        }

        try {
            $oldStatus = $kyc->status;
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';

            // 执行审核
            $kycService->review($kyc->id, $newStatus, $reason);

            // 记录审计日志（使用null表示系统操作，因为命令行操作没有实际用户）
            $auditService->log(
                null, // 系统操作
                $action === 'approve' ? 'kyc_approve' : 'kyc_reject',
                'user_kyc',
                ['status' => $oldStatus, 'kyc_id' => $kyc->id],
                ['status' => $newStatus, 'reason' => $reason, 'kyc_id' => $kyc->id]
            );

            $this->info("\n✓ KYC审核成功！");
            $this->table(
                ['字段', '值'],
                [
                    ['KYC ID', $kyc->id],
                    ['用户邮箱', $kyc->user->email ?? 'N/A'],
                    ['操作', $actionText],
                    ['新状态', $this->formatStatus($newStatus)],
                    ['审核原因', $reason ?? 'N/A'],
                    ['审核时间', \App\Support\TimeHelper::now()->format('Y-m-d H:i:s')],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("\n✗ KYC审核失败: {$e->getMessage()}");
            $this->error("错误详情: " . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }
    }

    /**
     * 格式化状态显示
     */
    private function formatStatus(string $status): string
    {
        return match ($status) {
            'pending' => '待审核',
            'approved' => '已通过',
            'rejected' => '已拒绝',
            default => $status,
        };
    }
}

