# 模块 4 完成清单 - 最终版

## ✅ 已完成的操作

### 1. ✅ 数据库迁移已运行
所有迁移已成功执行：
- `symbols` - 交易对表
- `follow_windows` - 跟单窗口表
- `invite_tokens` - 邀请码表
- `follow_orders` - 跟单订单表
- `follow_counters` - 配额计数器表
- `follow_bonus_windows` - 加餐窗口表

### 2. ✅ 种子数据已运行
- 基础交易对已创建（BTC/USDT, ETH/USDT, BNB/USDT, SOL/USDT）

### 3. ✅ 索引名称冲突已修复
所有索引名称已改为表名前缀格式，避免 SQLite 全局唯一性冲突。

## 📋 接下来你可以做什么

### 1. 生成窗口（已完成一次，可随时再次生成）

```bash
# 生成今天的窗口
php artisan follow:generate-windows

# 生成指定日期的窗口
php artisan follow:generate-windows 2025-11-06
```

### 2. 测试 API 端点

```bash
# 1. 登录获取 Token
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-login-1" \
  -d '{"email": "admin@followplus.com", "password": "admin123456"}'

# 2. 获取可用窗口
curl -X GET "http://localhost:8000/api/v1/follow/windows/available?date=$(date +%Y-%m-%d)" \
  -H "Authorization: Bearer {token}"

# 3. 入金（确保有余额）
curl -X POST http://localhost:8000/api/v1/deposits/manual-apply \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-deposit-1" \
  -d '{"amount": "1000", "currency": "USDT"}'

# 4. 下单（需要先获取窗口ID和邀请码）
curl -X POST http://localhost:8000/api/v1/follow/order \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-order-1" \
  -d '{
    "follow_window_id": 1,
    "symbol_id": 1,
    "invite_token": "ABCD1234"
  }'

# 5. 查看订单
curl -X GET http://localhost:8000/api/v1/follow/orders \
  -H "Authorization: Bearer {token}"

# 6. 查看统计
curl -X GET http://localhost:8000/api/v1/follow/summary \
  -H "Authorization: Bearer {token}"
```

### 3. 测试定时任务

```bash
# 手动运行生成窗口任务
php artisan follow:generate-windows

# 手动运行结算任务
php artisan follow:settle-orders

# 查看所有定时任务
php artisan schedule:list
```

### 4. 运行测试（待编写）

```bash
# 运行所有测试
php artisan test

# 运行特定测试文件（待创建）
php artisan test tests/Feature/FollowTest.php
```

## 🔧 已修复的问题

### 索引名称冲突
- **问题**：SQLite 要求索引名称全局唯一，多个表使用了相同的索引名称（如 `idx_status`, `idx_user`）
- **解决**：所有索引名称已改为表名前缀格式：
  - `idx_follow_windows_status`
  - `idx_follow_orders_status`
  - `idx_ref_rewards_status`
  - 等等...

## ⚙️ 定时任务配置

定时任务已在 `routes/console.php` 中配置：

- **生成窗口**：每日 00:05 执行
- **结算订单**：每小时执行

确保 cron 已设置：
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## 📊 当前状态

- ✅ 所有迁移已运行
- ✅ 所有种子数据已创建
- ✅ 所有路由已注册
- ✅ 所有定时任务已配置
- ✅ 索引冲突已修复

## 🎯 下一步建议

1. ✅ **已完成**：运行迁移和种子数据
2. ➡️ **建议**：测试 API 端点（使用上面的 curl 命令）
3. ➡️ **建议**：编写测试用例
4. ➡️ **可选**：开始模块 5 开发

---

**所有代码已就绪，迁移已成功！可以开始测试了！** 🚀

