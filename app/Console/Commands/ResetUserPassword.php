<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetUserPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:reset-password 
                            {email : 用户邮箱地址}
                            {password : 新密码}
                            {--force : 跳过确认提示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置指定用户的密码';

    /**
     * Execute the console command.
     */
    public function handle(AuthService $authService): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $force = $this->option('force');

        // 验证邮箱格式
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("无效的邮箱地址: {$email}");
            return Command::FAILURE;
        }

        // 验证密码长度
        if (strlen($password) < 8) {
            $this->error("密码长度至少需要8个字符");
            return Command::FAILURE;
        }

        // 查找用户
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("用户不存在: {$email}");
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

        // 确认操作
        if (!$force) {
            if (!$this->confirm("确定要重置该用户的密码吗？", false)) {
                $this->info("操作已取消");
                return Command::SUCCESS;
            }
        }

        try {
            // 重置密码
            $authService->adminResetPassword($email, $password);

            $this->info("✓ 密码重置成功！");
            $this->info("用户 {$email} 的新密码已设置");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("密码重置失败: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

