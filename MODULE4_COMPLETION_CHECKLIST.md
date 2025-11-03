# 模块 4 完成清单

## ✅ 你需要完成的操作

### 1. 运行数据库迁移

```bash
php artisan migrate
```

这会创建以下表：
- `symbols` - 交易对表
- `follow_windows` - 跟单窗口表
- `invite_tokens` - 邀请码表
- `follow_orders` - 跟单订单表
- `follow_counters` - 配额计数器表
- `follow_bonus_windows` - 加餐窗口表

### 2. 运行种子数据

```bash
php artisan db:seed
```

或者单独运行：

```bash
php artisan db:seed --class=SymbolSeeder
```

这会创建基础交易对（BTC/USDT, ETH/USDT, BNB/USDT, SOL/USDT）。

### 3. 生成测试窗口（可选，用于测试）

```bash
# 生成今天的窗口
php artisan follow:generate-windows

# 生成指定日期的窗口
php artisan follow:generate-windows 2025-11-06
```

### 4. 验证路由

```bash
php artisan route:list --path=follow
```

应该看到以下路由：
- `GET api/v1/follow/windows/available`
- `POST api/v1/follow/order`
- `GET api/v1/follow/orders`
- `GET api/v1/follow/summary`

### 5. 验证定时任务

```bash
# 查看所有定时任务
php artisan schedule:list

# 手动运行定时任务（测试）
php artisan follow:generate-windows
php artisan follow:settle-orders
```

### 6. 运行测试（待编写）

```bash
# 运行所有测试
php artisan test

# 运行特定测试文件（待创建）
php artisan test tests/Feature/FollowTest.php
```

## 📋 测试建议

### 手动测试流程

1. **生成窗口**：
   ```bash
   php artisan follow:generate-windows
   ```

2. **登录获取 Token**：
   ```bash
   curl -X POST http://localhost:8000/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -H "Idempotency-Key: test-login-1" \
     -d '{"email": "admin@followplus.com", "password": "admin123456"}'
   ```

3. **获取可用窗口**：
   ```bash
   curl -X GET http://localhost:8000/api/v1/follow/windows/available \
     -H "Authorization: Bearer {token}"
   ```

4. **入金（确保有余额）**：
   ```bash
   curl -X POST http://localhost:8000/api/v1/deposits/manual-apply \
     -H "Authorization: Bearer {token}" \
     -H "Content-Type: application/json" \
     -H "Idempotency-Key: test-deposit-1" \
     -d '{"amount": "1000", "currency": "USDT"}'
   ```

5. **下单**：
   ```bash
   curl -X POST http://localhost:8000/api/v1/follow/order \
     -H "Authorization: Bearer {token}" \
     -H "Content-Type: application/json" \
     -H "Idempotency-Key: test-order-1" \
     -d '{
       "follow_window_id": 1,
       "symbol_id": 1,
       "invite_token": "ABCD1234"
     }'
   ```

6. **查看订单**：
   ```bash
   curl -X GET http://localhost:8000/api/v1/follow/orders \
     -H "Authorization: Bearer {token}"
   ```

7. **查看统计**：
   ```bash
   curl -X GET http://localhost:8000/api/v1/follow/summary \
     -H "Authorization: Bearer {token}"
   ```

## ⚠️ 注意事项

1. **定时任务**：确保服务器已配置 cron 来运行 Laravel 调度器
   ```bash
   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **时区设置**：确保 `config/app.php` 中的时区设置正确

3. **金额计算**：下单金额会自动计算为总资产的1%，前端传入的 `amount_input` 仅用于审计

4. **配额逻辑**：
   - 固定窗使用基础配额（每日2次）
   - 加餐窗使用额外配额（每日4次 + 动态额外配额）

5. **结算时间**：窗口过期后，结算任务每小时运行一次，会自动结算所有过期窗口

## 🐛 常见问题

### 问题1: 迁移失败
**解决**：检查数据库连接和权限，确保表不存在冲突

### 问题2: 找不到 Symbol
**解决**：运行 `php artisan db:seed --class=SymbolSeeder`

### 问题3: 窗口不存在
**解决**：运行 `php artisan follow:generate-windows` 生成窗口

### 问题4: 配额不足
**解决**：检查用户当日的配额使用情况，配额每日重置

## 📝 下一步

1. ✅ 运行迁移和种子数据
2. ✅ 测试 API 端点
3. ➡️ 编写测试用例（优先级测试、结算测试等）
4. ➡️ 完善加餐窗口分配逻辑
5. ➡️ 开始模块 5 开发

---

**所有代码已就绪，可以开始测试！** 🚀

