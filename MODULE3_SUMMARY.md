# 模块 3 实现总结

## ✅ 已完成工作

### 1. 数据库迁移（3 张表）
- ✅ `ref_stats` - 邀请统计表（直接邀请数、团队总数、大使等级、分红比例等）
- ✅ `ref_events` - 邀请事件表（首充、次日奖励、等级晋升、分红等事件）
- ✅ `ref_rewards` - 奖励记录表（奖励类型、金额、状态、业务ID等）

### 2. 模型和关系
- ✅ `RefStat` - 邀请统计模型
- ✅ `RefEvent` - 邀请事件模型
- ✅ `RefReward` - 奖励记录模型

### 3. 服务层（3 个服务）
- ✅ `ReferralService` - 邀请关系服务
  - `bindInviter()` - 绑定邀请人
  - `recalcTeamStats()` - 重算团队统计
  - `onDirectDownlineWithdrawPaid()` - 处理直接下线提现断链
  - `getUplineChain()` - 获取上级链（最多3层）

- ✅ `RewardService` - 奖励发放服务
  - `grantReferralOnDeposit()` - 首充奖励（10%推荐人，5%通知人/上级）
  - `grantNewbieNextDay()` - 新人次日奖励（10%）
  - `grantAmbassadorOneOff()` - 等级一次性奖励
  - `dispatchDividend()` - 周期分红派发
  - `reverseReward()` - 奖励冲正

- ✅ `TeamRecalcService` - 团队重算服务
  - `recalcAll()` - 批量重算
  - `recalcAmbassadorLevels()` - 重算等级和分红比例

### 4. 控制器（2 个）
- ✅ `ReferralController` - 用户端邀请接口
  - `GET /api/v1/ref/summary` - 获取邀请统计
  - `GET /api/v1/ref/rewards` - 获取奖励历史

- ✅ `AdminReferralController` - 管理端接口
  - `POST /api/v1/admin/ref/level-recalc` - 手工重算等级
  - `POST /api/v1/admin/ref/reward-reverse` - 奖励冲正

### 5. 定时任务（2 个）
- ✅ `GrantNewbieNextDayRewards` - T+1 次日奖励发放（每日 00:10）
- ✅ `DispatchDividends` - 周期分红派发（每周一 00:00）

### 6. 中间件
- ✅ `AdminMiddleware` - 管理员权限验证

### 7. 集成模块2
- ✅ 首充奖励触发：在 `DepositService::confirm()` 中集成
- ✅ 提现断链逻辑：在 `WithdrawService::markPaid()` 中集成（新人提现触发）

## 📋 API 端点汇总

### 用户端
- `GET /api/v1/ref/summary` - 获取邀请统计
- `GET /api/v1/ref/rewards?type=&status=` - 获取奖励历史

### 管理端（需要 admin 角色）
- `POST /api/v1/admin/ref/level-recalc` - 手工重算等级
- `POST /api/v1/admin/ref/reward-reverse` - 奖励冲正

## 🔑 核心功能

### 1. 邀请关系树
- 使用 `ref_path` 字段存储邀请路径（如 `/1/2/3`）
- 支持无层级上限的邀请树
- 自动维护 `direct_count` 和 `team_count`

### 2. 奖励规则
- **首充奖励**：
  - 推荐人获得 10%
  - 通知人获得 5%（如有）
  - 上级获得 5%（无通知人时）
  - 第二级上级获得 5%

- **新人次日奖励**：
  - 被推荐人 T+1 获得首充金额的 10%

- **等级奖励**：
  - L1: 10+ 团队成员，一次性奖励 100 USDT，分红 0.01%
  - L2: 50+ 团队成员，一次性奖励 500 USDT，分红 0.02%
  - L3: 200+ 团队成员，一次性奖励 2000 USDT，分红 0.05%
  - L4: 1000+ 团队成员，一次性奖励 10000 USDT，分红 0.1%
  - L5: 5000+ 团队成员，一次性奖励 50000 USDT，分红 0.2%

### 3. 断链逻辑
- 当直接下线（新人，注册7天内）提现完成时：
  - 移除邀请关系（`invited_by_user_id` 设为 null）
  - 重写 `ref_path` 为根路径 `/`
  - 更新邀请者的 `direct_count` 和 `team_count`
  - 触发等级和分红重算

### 4. 幂等性保护
- 所有奖励通过 `biz_id` 字段实现幂等性
- 防止重复发放奖励

## 🚀 运行迁移

### 步骤 1: 运行迁移

```bash
php artisan migrate
```

这会创建以下表：
- ref_stats
- ref_events
- ref_rewards

### 步骤 2: 验证

```bash
# 检查迁移状态
php artisan migrate:status

# 查看表结构
php artisan tinker
>>> \App\Models\RefStat::count()
>>> \App\Models\RefEvent::count()
```

## ⚙️ 定时任务配置

定时任务已在 `routes/console.php` 中配置。确保 cron 已设置：

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## 🔗 集成说明

### 与模块2的集成

1. **首充奖励触发**：
   - `DepositService` 在确认入金后自动调用 `RewardService::grantReferralOnDeposit()`
   - 如果用户没有邀请人，不会触发奖励

2. **提现断链**：
   - `WithdrawService` 在标记提现为已支付后，检查用户是否为新人
   - 如果是新人且有邀请人，调用 `ReferralService::onDirectDownlineWithdrawPaid()`

## 📝 待完成

- [ ] 编写测试用例（奖励计算、断链逻辑、等级晋升）
- [ ] 优化团队统计查询性能（大数据量场景）
- [ ] 添加奖励通知功能
- [ ] 完善分红计算公式（基于实际平台收益）

## 🎯 下一步

1. ✅ 运行迁移
2. ✅ 验证 API 端点
3. ➡️ 编写测试用例
4. ➡️ 开始模块 4（Follow Core）

---

**提示**: 模块3的核心功能已完成，可以与模块2配合使用。

