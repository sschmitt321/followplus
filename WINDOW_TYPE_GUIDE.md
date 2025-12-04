# 跟单窗口类型说明

本文档详细说明三种跟单窗口类型的区别、参与条件和配额逻辑。

---

## 窗口类型概览

| 窗口类型 | 中文名称 | 参与条件 | 配额类型 | 典型时间 |
|---------|---------|---------|---------|---------|
| `fixed_daily` | 固定每日窗口 | 所有用户（有余额即可） | 基础配额 | 13:00, 20:00 |
| `newbie_bonus` | 新人加餐窗 | 注册后第2-6天的用户 | 加餐配额 | 12:00, 14:00, 19:00, 21:00 |
| `inviter_bonus` | 邀请人加餐窗 | 至少1个有效邀请的用户 | 加餐配额 | 12:00, 14:00, 19:00, 21:00 |

---

## 1. fixed_daily（固定每日窗口）

### 参与条件
- ✅ **所有用户都可以参与**（只要账户有余额）
- ✅ 无其他限制条件

### 配额逻辑
- **配额类型**：基础配额（Base Quota）
- **每日配额**：**2次**
- **配额字段**：`base_quota_used`

### 使用场景
- 每日固定时间开放，所有用户都可以参与
- 通常设置为 13:00 和 20:00

### 代码逻辑
```php
// 检查参与权限
if ($windowType === 'fixed_daily') {
    return true; // 所有有余额的用户都可以参与
}

// 配额检查
if ($windowType === 'fixed_daily') {
    return $counter->base_quota_used < 2; // 基础配额：2次/天
}
```

---

## 2. newbie_bonus（新人加餐窗）

### 参与条件
- ✅ **注册后第2-6天的用户**可以参与
- ❌ 注册当天（第1天）不能参与
- ❌ 注册7天及以后不能参与
- ✅ 必须账户有余额

### 时间计算逻辑
```php
$joinDate = $user->first_joined_at->setTimezone('Asia/Shanghai')->startOfDay();
$today = TimeHelper::now()->startOfDay();
$daysSinceJoin = $joinDate->diffInDays($today);

// 参与条件：daysSinceJoin >= 1 && daysSinceJoin <= 5
// diffInDays: 0=day1, 1=day2, 2=day3, 3=day4, 4=day5, 5=day6
// 即：注册后第2天、第3天、第4天、第5天、第6天可以参与
```

**示例**：
- 用户 2025-01-15 注册
- 2025-01-15（第1天）❌ 不能参与
- 2025-01-16（第2天）✅ 可以参与
- 2025-01-17（第3天）✅ 可以参与
- 2025-01-18（第4天）✅ 可以参与
- 2025-01-19（第5天）✅ 可以参与
- 2025-01-20（第6天）✅ 可以参与
- 2025-01-21（第7天）❌ 不能参与

### 配额逻辑
- **配额类型**：加餐配额（Extra Quota）
- **基础加餐配额**：**4次/天**
- **额外配额**：如果用户有 `FollowBonusWindow` 记录，可以额外增加配额
- **配额字段**：`extra_quota_used`

### 使用场景
- 为新用户提供额外的跟单机会
- 通常设置为 12:00, 14:00, 19:00, 21:00

### 代码逻辑
```php
// 检查参与权限
if ($windowType === 'newbie_bonus') {
    $joinDate = $user->first_joined_at->setTimezone('Asia/Shanghai')->startOfDay();
    $today = TimeHelper::now()->startOfDay();
    $daysSinceJoin = $joinDate->diffInDays($today);
    return $daysSinceJoin >= 1 && $daysSinceJoin <= 5; // 第2-6天
}

// 配额检查
if ($windowType === 'newbie_bonus') {
    $extraQuota = 4 + 额外配额; // 基础4次 + 额外配额
    return $counter->extra_quota_used < $extraQuota;
}
```

---

## 3. inviter_bonus（邀请人加餐窗）

### 参与条件
- ✅ **至少有一个有效邀请**的用户可以参与
- ✅ 必须账户有余额

### 有效邀请判断
对于邀请者A，其直接邀请的每个用户B：
- 如果B的总充值金额 >= A的总金额，则B算作A的一个**有效邀请**
- 如果B的总充值金额 < A的总金额，则B**无效**

**参与条件**：只要A有至少1个有效邀请，A就可以参与 `inviter_bonus` 窗口

**示例**：
- 邀请者A有5000U总金额
- A邀请了B（总充值1500U）、C（总充值1000U）、D（总充值2000U）
- B: 1500 < 5000，无效 ❌
- C: 1000 < 5000，无效 ❌
- D: 2000 < 5000，无效 ❌
- 结果：0个有效邀请，**不能参与** ❌

**示例2**：
- 邀请者A有5000U总金额
- A邀请了B（总充值6000U）、C（总充值1000U）、D（总充值5000U）
- B: 6000 >= 5000，有效 ✅
- C: 1000 < 5000，无效 ❌
- D: 5000 >= 5000，有效 ✅
- 结果：2个有效邀请，**可以参与** ✅

### 配额逻辑
- **配额类型**：加餐配额（Extra Quota）
- **基础加餐配额**：**4次/天**
- **额外配额**：如果用户有 `FollowBonusWindow` 记录，可以额外增加配额
- **配额字段**：`extra_quota_used`

### 使用场景
- 为活跃的邀请人提供额外的跟单机会
- 通常设置为 12:00, 14:00, 19:00, 21:00

### 代码逻辑
```php
// 检查参与权限
if ($windowType === 'inviter_bonus') {
    $inviterBalance = getTotalBalance($user->id);
    if ($inviterBalance == 0) {
        return false; // 没有余额，不能参与
    }
    
    $directInvites = getDirectInvites($user->id);
    $validInviteCount = 0;
    
    foreach ($directInvites as $invitedUser) {
        $invitedDeposit = getTotalDeposit($invitedUser->id);
        if ($invitedDeposit >= $inviterBalance) {
            $validInviteCount++; // 有效邀请
        }
    }
    
    return $validInviteCount > 0; // 至少1个有效邀请
}

// 配额检查（与 newbie_bonus 相同）
if ($windowType === 'inviter_bonus') {
    $extraQuota = 4 + 额外配额; // 基础4次 + 额外配额
    return $counter->extra_quota_used < $extraQuota;
}
```

---

## 配额系统详解

### 配额类型

#### 1. 基础配额（Base Quota）
- **配额数量**：2次/天
- **使用窗口**：`fixed_daily`
- **配额字段**：`follow_counters.base_quota_used`
- **特点**：所有用户共享相同的配额限制

#### 2. 加餐配额（Extra Quota）
- **基础配额**：4次/天（所有用户都有）
- **额外配额**：通过 `FollowBonusWindow` 记录增加
- **使用窗口**：`newbie_bonus`、`inviter_bonus`
- **配额字段**：`follow_counters.extra_quota_used`
- **特点**：符合条件的用户可以获得额外配额

### 额外配额机制

用户可以通过以下方式获得额外配额：

1. **新人奖励**（`newbie_days2to6`）
   - 注册后第2-6天的用户
   - 每日额外配额：4次（默认）

2. **邀请人奖励**（`inviter_ratio30pct`）
   - 至少1个有效邀请的用户（被邀请人充值金额 >= 邀请人总金额）
   - 每日额外配额：4次（默认）

**配额计算公式**：
```
总加餐配额 = 基础加餐配额（4次）+ 额外配额（根据 FollowBonusWindow 记录）
```

---

## 奖励率

所有窗口类型都使用相同的奖励率计算逻辑：

- **奖励率范围**：`reward_rate_min` 到 `reward_rate_max`（默认：0.5 - 0.6，即 50% - 60%）
- **利润计算**：`利润 = 投入金额 × 随机奖励率`
- **随机性**：每次结算时，在最小值和最大值之间随机生成一个奖励率

---

## 使用建议

### 创建窗口时的选择

1. **固定窗口（fixed_daily）**
   - 适合：每日固定时间，所有用户都可以参与
   - 时间建议：13:00, 20:00

2. **新人加餐窗（newbie_bonus）**
   - 适合：为新用户提供额外机会
   - 时间建议：12:00, 14:00, 19:00, 21:00
   - 注意：只有注册后第2-6天的用户可以参与

3. **邀请人加餐窗（inviter_bonus）**
   - 适合：激励活跃的邀请人
   - 时间建议：12:00, 14:00, 19:00, 21:00
   - 注意：只有至少1个有效邀请的用户可以参与（被邀请人充值金额 >= 邀请人总金额）

---

## 常见问题

### Q1: 用户可以同时参与多种窗口类型吗？
**A:** 可以。用户每天可以：
- 使用基础配额参与 `fixed_daily` 窗口（最多2次）
- 使用加餐配额参与 `newbie_bonus` 或 `inviter_bonus` 窗口（最多4次 + 额外配额）

### Q2: 新用户第1天可以参与加餐窗吗？
**A:** 不可以。`newbie_bonus` 窗口只允许注册后第2-6天的用户参与。

### Q3: 如何判断是否有有效邀请？
**A:** 对于邀请者A，其直接邀请的每个用户B：如果B的总充值金额 >= A的总金额，则B算作A的一个有效邀请。只要A有至少1个有效邀请，A就可以参与 `inviter_bonus` 窗口。

**示例**：
- A有5000U，邀请了B（充值6000U）、C（充值1000U）、D（充值5000U）
- B和D是有效邀请（>= 5000U），C无效（< 5000U）
- A有2个有效邀请，可以参与 ✅

### Q4: 配额是每天重置吗？
**A:** 是的。配额按日期（`date` 字段）统计，每天重置。

### Q5: 用户可以同时满足 newbie_bonus 和 inviter_bonus 的条件吗？
**A:** 可以。如果用户同时满足两个条件，可以参与两种类型的加餐窗，但共享同一个加餐配额池。

---

## 相关代码文件

- `app/Services/Follow/FollowService.php` - 参与权限检查
- `app/Services/Follow/FollowQuotaService.php` - 配额管理
- `app/Models/FollowWindow.php` - 窗口模型
- `app/Models/FollowCounter.php` - 配额计数器模型
- `app/Models/FollowBonusWindow.php` - 额外配额记录模型

