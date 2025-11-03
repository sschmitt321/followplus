# 模块 4 实现总结

## ✅ 已完成工作

### 1. 数据库迁移（6 张表）
- ✅ `symbols` - 交易对表
- ✅ `follow_windows` - 跟单窗口表
- ✅ `invite_tokens` - 邀请码表
- ✅ `follow_orders` - 跟单订单表
- ✅ `follow_counters` - 配额计数器表
- ✅ `follow_bonus_windows` - 加餐窗口表

### 2. 模型和关系
- ✅ `Symbol` - 交易对模型
- ✅ `FollowWindow` - 跟单窗口模型
- ✅ `InviteToken` - 邀请码模型
- ✅ `FollowOrder` - 跟单订单模型
- ✅ `FollowCounter` - 配额计数器模型
- ✅ `FollowBonusWindow` - 加餐窗口模型

### 3. 服务层（2 个服务）
- ✅ `FollowService` - 跟单服务
  - `placeOrder()` - 下单
  - `settleExpiredWindows()` - 批量结算
  - `getAvailableWindows()` - 获取可用窗口
  - `getSummary()` - 获取用户统计

- ✅ `FollowQuotaService` - 配额服务
  - `hasQuota()` - 检查配额
  - `consumeQuota()` - 消费配额
  - `getExtraQuota()` - 获取额外配额
  - `getRemainingQuota()` - 获取剩余配额

### 4. 控制器（1 个）
- ✅ `FollowController` - 跟单控制器
  - `GET /api/v1/follow/windows/available` - 获取可用窗口
  - `POST /api/v1/follow/order` - 下单
  - `GET /api/v1/follow/orders` - 获取订单列表
  - `GET /api/v1/follow/summary` - 获取统计汇总

### 5. 定时任务（2 个）
- ✅ `GenerateFollowWindows` - 生成窗口（每日 00:05）
- ✅ `SettleFollowOrders` - 结算订单（每小时）

### 6. 种子数据
- ✅ `SymbolSeeder` - 创建基础交易对（BTC/USDT, ETH/USDT, BNB/USDT, SOL/USDT）

## 📋 API 端点汇总

### 跟单相关
- `GET /api/v1/follow/windows/available?date=YYYY-MM-DD` - 获取可用窗口
- `POST /api/v1/follow/order` - 下单
- `GET /api/v1/follow/orders?status=` - 获取订单列表
- `GET /api/v1/follow/summary` - 获取统计汇总

## 🔑 核心功能

### 1. 窗口类型
- **固定窗**：每日 13:00 和 20:00（`fixed_daily`）
- **加餐窗**：每日 12:00, 14:00, 19:00, 21:00（`newbie_bonus` / `inviter_bonus`）

### 2. 配额规则
- **基础配额**：每日 2 次（固定窗）
- **加餐配额**：每日 4 次（加餐窗）
- **额外配额**：通过 `follow_bonus_windows` 表动态分配

### 3. 下单规则
- 下单金额 = 总资产 × 1%（后端自动计算）
- 必须提供有效的邀请码
- 窗口必须处于有效时间范围内
- 必须有足够的配额

### 4. 结算规则
- 窗口过期后自动结算
- 利润 = `amount_base × random(reward_rate_min, reward_rate_max)`
- 默认奖励率：50% - 60%
- 利润自动入账到用户账户

### 5. 邀请码
- 每个窗口有唯一的邀请码
- 邀请码在窗口时间范围内有效
- 全员使用同一邀请码（同一窗口）

## 🚀 运行迁移和种子数据

### 步骤 1: 运行迁移

```bash
php artisan migrate
```

这会创建以下表：
- symbols
- follow_windows
- invite_tokens
- follow_orders
- follow_counters
- follow_bonus_windows

### 步骤 2: 运行种子数据

```bash
php artisan db:seed --class=SymbolSeeder
```

这会创建基础交易对。

### 步骤 3: 生成今天的窗口（测试用）

```bash
php artisan follow:generate-windows
```

### 步骤 4: 验证

```bash
# 检查迁移状态
php artisan migrate:status

# 查看交易对
php artisan tinker
>>> \App\Models\Symbol::all()

# 查看窗口
>>> \App\Models\FollowWindow::whereDate('start_at', today())->get()
```

## ⚙️ 定时任务配置

定时任务已在 `routes/console.php` 中配置：

- **生成窗口**：每日 00:05 执行
- **结算订单**：每小时执行

确保 cron 已设置：

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## 📝 待完成

- [ ] 编写测试用例（配额优先级、结算逻辑、窗口验证）
- [ ] 完善加餐窗口分配逻辑（新人第2-6天、邀请人比例≥30%）
- [ ] 添加订单过期处理（长时间未结算的订单标记为过期）
- [ ] 优化窗口查询性能

## 🎯 下一步

1. ✅ 运行迁移和种子数据
2. ✅ 生成窗口测试
3. ✅ 验证 API 端点
4. ➡️ 编写测试用例
5. ➡️ 开始模块 5（Market, System, Admin & Observability）

---

**提示**: 模块4的核心功能已完成，可以开始测试和编写测试用例。

