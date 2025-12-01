# 邀请关系逻辑说明

## 📊 数据表结构

### 1. `users` 表（邀请关系）
- `invited_by_user_id`: 直接邀请人的用户ID（注册时设置）
- `ref_path`: 邀请路径，如 `/1/2/3`（表示用户3的邀请链：1 -> 2 -> 3）
- `ref_depth`: 邀请深度（0表示根用户）

### 2. `ref_stats` 表（统计数据）
- `direct_count`: **直接邀请人数**（注册就算，不需要充值）
- `team_count`: **团队总人数**（包含所有间接邀请的下线）

## 🔗 邀请关系建立流程

### 注册时建立邀请关系

**流程**：
1. 用户注册时提供 `invite_code`（邀请码）
2. 系统查找邀请码对应的用户（邀请者）
3. 设置新用户的 `invited_by_user_id` = 邀请者的ID
4. 设置新用户的 `ref_path` = 邀请者的 `ref_path` + '/' + 邀请者ID
5. **立即更新邀请者的统计数据**（调用 `updateReferralStats()`）

**代码位置**：`app/Services/Auth/AuthService.php::register()`

```php
// 注册时建立邀请关系
if ($inviteCode) {
    $inviter = User::where('invite_code', $inviteCode)->first();
    if ($inviter) {
        $user->invited_by_user_id = $inviter->id;
        $user->ref_path = $inviter->ref_path . '/' . $inviter->id;
    }
}

// 立即更新统计数据
if ($inviter && $this->referralService) {
    $this->referralService->updateReferralStats($inviter->id, $user->id);
}
```

## 📈 统计数据计算逻辑

### `direct_count`（直接邀请人数）

**计算方式**：
```sql
SELECT COUNT(*) FROM users WHERE invited_by_user_id = 3
```

**说明**：
- ✅ **注册就算数**，不需要充值
- 统计所有 `invited_by_user_id = 3` 的用户数量
- 包括已删除的用户（软删除）

### `team_count`（团队总人数）

**计算方式**：
```php
// 根据 ref_path 统计所有子树用户
// 例如：user_id=3 的 ref_path 是 '/'
// 构建路径前缀：'/3'（ref_path + '/' + userId）
// 查找所有 ref_path LIKE '/3%' 的用户（包括 /3, /3/4, /3/4/5 等）
// 排除用户自己（WHERE id != 3）
```

**代码实现**（`ReferralService::countSubtreeSize()`）：
```php
private function countSubtreeSize(int $userId): int
{
    $user = User::findOrFail($userId);
    
    // 构建路径前缀：ref_path + '/' + userId
    // 正确处理根路径（/）的情况
    $basePath = rtrim($user->ref_path, '/');
    if ($basePath === '') {
        $pathPrefix = '/' . $userId;  // 根用户：/3
    } else {
        $pathPrefix = $basePath . '/' . $userId;  // 非根用户：/1/2/3
    }
    
    // 统计所有 ref_path 以该路径开头的用户（排除自己）
    return User::where('ref_path', 'like', $pathPrefix . '%')
        ->where('id', '!=', $userId)
        ->count();
}
```

**说明**：
- ✅ **注册就算数**，不需要充值
- 包含直接邀请和间接邀请的所有下线
- 递归统计整个邀请树（通过 ref_path 前缀匹配）
- 排除用户自己

## 🎁 奖励触发时机

### 1. 首充奖励（充值触发）

**触发时机**：用户首次充值时

**奖励规则**：
- 直接邀请人：10% 首充金额
- 通知人/上级：5% 首充金额
- 第二级上级：5% 首充金额

**代码位置**：`app/Services/Deposit/DepositService.php::confirm()`

```php
// 充值确认时触发邀请奖励
if ($this->rewardService) {
    $this->rewardService->grantReferralOnDeposit(
        $deposit->user_id,
        $deposit->amount
    );
}
```

### 2. 新人次日奖励（充值触发）

**触发时机**：用户首次充值后的第二天（T+1）

**奖励规则**：
- 被邀请人：10% 首充金额

**代码位置**：定时任务 `rewards:grant-newbie-next-day`（每天 00:10 执行）

## 🔄 统计数据更新机制

### 自动更新时机

1. **用户注册时**：自动更新邀请者的统计数据
   - 调用 `ReferralService::updateReferralStats()`
   - 自动更新邀请者的 `direct_count` 和 `team_count`
   - 同时更新邀请链上所有上级用户的 `team_count`
   - ⚠️ 注意：此时激活用户数为 0（用户还未充值）
   
2. **用户首次充值时**：自动更新邀请者的激活用户数统计
   - 调用 `RewardService::grantReferralOnDeposit()`
   - 发放首充奖励后，自动调用 `ReferralService::recalcTeamStats()`
   - 更新邀请者的 `direct_active_count` 和 `team_active_count`
   - 同时更新邀请链上所有上级用户的 `team_active_count`
   - ✅ 只在首次充值时触发（有幂等性检查，后续充值不会重复触发）
   
3. **用户提现时**：如果新人提现，会断链并更新统计
   - 调用 `ReferralService::onDirectDownlineWithdrawPaid()`
   - 移除邀请关系，更新相关统计数据

### 是否需要定期跑脚本？

**答案**：**通常不需要**，但建议定期检查数据一致性

**原因**：
- ✅ 注册时已自动更新统计（调用 `updateReferralStats()`）
- ✅ 提现断链时已自动更新统计
- ⚠️ 但如果历史数据有问题，或代码逻辑有 bug，可能需要重新计算

**手动重新计算命令**（如果需要）：

```bash
# 方法1：重新计算特定用户的统计（推荐）
php artisan tinker
>>> $service = app(\App\Services\Referral\ReferralService::class);
>>> $service->recalcTeamStats(3);  // 会更新用户3及其所有上级的统计

# 方法2：批量重新计算所有用户的统计
>>> $teamService = app(\App\Services\Referral\TeamRecalcService::class);
>>> $teamService->recalcAll();  // 重新计算所有用户的统计数据

# 方法3：使用 SQL 直接更新（不推荐，可能不准确）
UPDATE ref_stats 
SET direct_count = (
    SELECT COUNT(*) 
    FROM users 
    WHERE invited_by_user_id = 3 
      AND deleted_at IS NULL
)
WHERE user_id = 3;
```

**注意**：`recalcTeamStats()` 方法会：
1. 重新计算指定用户自己的统计
2. 重新计算该用户所有上级（ref_path 中的用户）的统计
3. 自动更新大使等级（如果达到升级条件）

## 📝 总结

### 邀请数量计算规则

| 指标 | 计算时机 | 是否需要充值 |
|------|---------|------------|
| `direct_count` | **注册时** | ❌ 不需要，注册就算 |
| `direct_active_count` | **首次充值时** | ✅ 需要，首次充值激活后更新 |
| `team_count` | **注册时** | ❌ 不需要，注册就算 |
| `team_active_count` | **首次充值时** | ✅ 需要，首次充值激活后更新 |

**示例**：
- 用户A邀请了B、C、D三个人
- B和C充值了，D未充值
- 结果：`direct_count = 3`，`direct_active_count = 2`

### 奖励发放规则

| 奖励类型 | 触发时机 | 是否需要充值 |
|---------|---------|------------|
| 首充奖励（邀请人） | 首次充值 | ✅ 需要 |
| 新人次日奖励 | 首次充值后T+1 | ✅ 需要 |

### 关键区别

- **邀请数量统计**：注册就算，不需要充值
- **奖励发放**：需要充值才触发

## 🔍 查询示例

### 查询 user_id=3 的邀请数据

```sql
-- 方法1：从统计表查询（推荐，更快）
SELECT direct_count, team_count 
FROM ref_stats 
WHERE user_id = 3;

-- 方法2：直接从 users 表统计（最准确）
SELECT COUNT(*) as direct_count
FROM users
WHERE invited_by_user_id = 3
  AND deleted_at IS NULL;

-- 查看被邀请的用户详情
SELECT id, email, phone, ref_path, created_at
FROM users
WHERE invited_by_user_id = 3
ORDER BY created_at DESC;
```

### 使用 Laravel Tinker

```php
// 查询直接邀请人数
$directCount = \App\Models\User::where('invited_by_user_id', 3)->count();

// 查询统计表
$stat = \App\Models\RefStat::where('user_id', 3)->first();
echo "直接邀请: {$stat->direct_count}, 团队总数: {$stat->team_count}";

// 重新计算统计
$service = app(\App\Services\Referral\ReferralService::class);
$service->recalcTeamStats(3);
```

