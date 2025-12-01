<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BindUserInviter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:bind-inviter 
                            {user : 被邀请者（用户ID、邮箱或手机号）}
                            {inviter : 邀请者（用户ID、邮箱、手机号或邀请码）}
                            {--force : 强制替换已有邀请关系}
                            {--update-subtree : 更新被邀请者所有下级的ref_path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '手动关联用户的邀请关系（将用户A设置为用户B的邀请者）';

    /**
     * Execute the console command.
     */
    public function handle(ReferralService $referralService): int
    {
        $userIdentifier = $this->argument('user');
        $inviterIdentifier = $this->argument('inviter');
        $force = $this->option('force');
        $updateSubtree = $this->option('update-subtree');

        // 查找被邀请者
        $user = $this->findUser($userIdentifier);
        if (!$user) {
            $this->error("找不到用户: {$userIdentifier}");
            return Command::FAILURE;
        }

        // 查找邀请者
        $inviter = $this->findUser($inviterIdentifier);
        if (!$inviter) {
            $this->error("找不到邀请者: {$inviterIdentifier}");
            return Command::FAILURE;
        }

        // 验证不能自己邀请自己
        if ($user->id === $inviter->id) {
            $this->error("不能将自己设置为自己的邀请者");
            return Command::FAILURE;
        }

        // 检查是否已有邀请者
        $oldInviterId = $user->invited_by_user_id;
        $oldInviter = $oldInviterId ? User::find($oldInviterId) : null;

        // 显示当前信息
        $this->info("被邀请者信息:");
        $this->table(
            ['字段', '值'],
            [
                ['ID', $user->id],
                ['邮箱', $user->email ?? 'N/A'],
                ['手机', $user->phone ?? 'N/A'],
                ['邀请码', $user->invite_code],
                ['当前邀请者ID', $oldInviterId ?? '无'],
                ['当前邀请路径', $user->ref_path],
                ['当前邀请深度', $user->ref_depth],
            ]
        );

        $this->info("邀请者信息:");
        $this->table(
            ['字段', '值'],
            [
                ['ID', $inviter->id],
                ['邮箱', $inviter->email ?? 'N/A'],
                ['手机', $inviter->phone ?? 'N/A'],
                ['邀请码', $inviter->invite_code],
                ['邀请路径', $inviter->ref_path],
                ['邀请深度', $inviter->ref_depth],
            ]
        );

        // 检查是否已有邀请者
        if ($oldInviterId && !$force) {
            $this->warn("⚠️  警告：该用户已有邀请者（用户ID: {$oldInviterId}）");
            if (!$this->confirm("确定要替换邀请关系吗？", false)) {
                $this->info("操作已取消");
                return Command::SUCCESS;
            }
        }

        // 检查循环引用（邀请者是否在被邀请者的子树中）
        if ($this->isCircularReference($user, $inviter)) {
            $this->error("❌ 错误：不能将用户设置为其下级的邀请者（会造成循环引用）");
            return Command::FAILURE;
        }

        // 计算新的邀请路径和深度
        $newRefPath = rtrim($inviter->ref_path, '/') . '/' . $inviter->id;
        $newRefDepth = $inviter->ref_depth + 1;

        // 显示将要执行的操作
        $this->info("将要执行的操作:");
        $this->table(
            ['项目', '旧值', '新值'],
            [
                ['邀请者ID', $oldInviterId ?? '无', $inviter->id],
                ['邀请路径', $user->ref_path, $newRefPath],
                ['邀请深度', $user->ref_depth, $newRefDepth],
            ]
        );

        // 如果被邀请者有下级，显示影响范围
        $subtreeCount = $this->countSubtree($user->id);
        if ($subtreeCount > 0) {
            $this->warn("⚠️  注意：该用户有 {$subtreeCount} 个下级，他们的邀请路径也会被更新");
            if ($updateSubtree) {
                $this->info("✓ 将更新所有下级的邀请路径");
            } else {
                $this->warn("⚠️  警告：未使用 --update-subtree 选项，下级的邀请路径可能不正确");
                if (!$this->confirm("确定要继续吗？", false)) {
                    $this->info("操作已取消");
                    return Command::SUCCESS;
                }
            }
        }

        // 确认操作
        if (!$force) {
            if (!$this->confirm("确定要执行此操作吗？", true)) {
                $this->info("操作已取消");
                return Command::SUCCESS;
            }
        }

        try {
            DB::transaction(function () use ($user, $inviter, $oldInviterId, $newRefPath, $newRefDepth, $updateSubtree, $referralService) {
                // Lock users for update
                $user = User::lockForUpdate()->findOrFail($user->id);
                $inviter = User::lockForUpdate()->findOrFail($inviter->id);

                // 1. 更新被邀请者的邀请关系
                $oldRefPath = $user->ref_path;
                $user->update([
                    'invited_by_user_id' => $inviter->id,
                    'ref_path' => $newRefPath,
                    'ref_depth' => $newRefDepth,
                ]);

                // 2. 如果被邀请者有下级，更新所有下级的ref_path
                if ($updateSubtree && $oldRefPath !== '/') {
                    $this->updateSubtreeRefPath($user->id, $oldRefPath, $newRefPath);
                }

                // 3. 更新旧邀请者的统计（如果存在且被替换）
                if ($oldInviterId && $oldInviterId !== $inviter->id) {
                    $referralService->recalcTeamStats($oldInviterId);
                }

                // 4. 更新新邀请者的统计
                $referralService->recalcTeamStats($inviter->id);
            });

            $this->info("✓ 邀请关系绑定成功！");
            
            // 显示更新后的统计
            $this->info("更新后的邀请者统计:");
            $inviterStat = \App\Models\RefStat::where('user_id', $inviter->id)->first();
            if ($inviterStat) {
                $this->table(
                    ['字段', '值'],
                    [
                        ['直接邀请人数', $inviterStat->direct_count],
                        ['直接邀请人数（已激活）', $inviterStat->direct_active_count ?? 0],
                        ['团队总人数', $inviterStat->team_count],
                        ['团队总人数（已激活）', $inviterStat->team_active_count ?? 0],
                        ['大使等级', $inviterStat->ambassador_level],
                    ]
                );
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("绑定失败: {$e->getMessage()}");
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

    /**
     * Check if inviter is in user's subtree (circular reference check).
     */
    private function isCircularReference(User $user, User $inviter): bool
    {
        // If user has no ref_path, no circular reference possible
        if (empty($user->ref_path) || $user->ref_path === '/') {
            return false;
        }

        // Check if inviter's ID is in user's ref_path
        $pathIds = explode('/', trim($user->ref_path, '/'));
        return in_array($inviter->id, array_map('intval', $pathIds));
    }

    /**
     * Count subtree size for a user.
     */
    private function countSubtree(int $userId): int
    {
        $user = User::findOrFail($userId);
        
        $basePath = rtrim($user->ref_path, '/');
        if ($basePath === '') {
            $pathPrefix = '/' . $userId;
        } else {
            $pathPrefix = $basePath . '/' . $userId;
        }
        
        return User::where('ref_path', 'like', $pathPrefix . '%')
            ->where('id', '!=', $userId)
            ->count();
    }

    /**
     * Update ref_path for all users in subtree.
     */
    private function updateSubtreeRefPath(int $rootUserId, string $oldRefPath, string $newRefPath): void
    {
        $rootUser = User::findOrFail($rootUserId);
        
        // Build old path prefix
        $basePath = rtrim($oldRefPath, '/');
        if ($basePath === '') {
            $oldPathPrefix = '/' . $rootUserId;
        } else {
            $oldPathPrefix = $basePath . '/' . $rootUserId;
        }

        // Build new path prefix
        $basePath = rtrim($newRefPath, '/');
        if ($basePath === '') {
            $newPathPrefix = '/' . $rootUserId;
        } else {
            $newPathPrefix = $basePath . '/' . $rootUserId;
        }

        // Find all users in the subtree
        $subtreeUsers = User::where('ref_path', 'like', $oldPathPrefix . '%')
            ->where('id', '!=', $rootUserId)
            ->get();

        $updatedCount = 0;
        foreach ($subtreeUsers as $subtreeUser) {
            // Replace old path prefix with new path prefix
            $oldUserPath = $subtreeUser->ref_path;
            $newUserPath = str_replace($oldPathPrefix, $newPathPrefix, $oldUserPath);
            
            // Calculate new depth
            $newDepth = substr_count($newUserPath, '/') - 1;
            
            $subtreeUser->update([
                'ref_path' => $newUserPath,
                'ref_depth' => $newDepth,
            ]);
            
            $updatedCount++;
        }

        if ($updatedCount > 0) {
            $this->info("✓ 已更新 {$updatedCount} 个下级的邀请路径");
        }
    }
}
