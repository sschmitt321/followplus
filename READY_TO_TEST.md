# 🎉 模块 2 代码已完成，准备测试

## ✅ 完成状态

所有模块 2 的代码已经实现完成：

- ✅ 8 张数据库表迁移
- ✅ 8 个模型（含关系和 Cast）
- ✅ Decimal 工具类和 MoneyCast
- ✅ 6 个服务类（Ledger, Assets, Deposit, Withdraw, Transfer, Swap）
- ✅ 5 个控制器（Wallets, Deposit, Withdraw, Transfer, Swap）
- ✅ API 路由配置
- ✅ 种子数据（币种和管理员）

## 🚀 运行迁移和种子数据

### 步骤 1: 运行数据库迁移

```bash
php artisan migrate
```

这会创建以下表：
- currencies
- accounts  
- ledger_entries
- user_assets_summary
- deposits
- withdrawals
- internal_transfers
- swaps

### 步骤 2: 运行种子数据

```bash
php artisan db:seed
```

这会创建：
- 基础币种：USDT, BTC, ETH, USDC
- 管理员账号：admin@followplus.com / admin123456

### 步骤 3: 验证迁移

```bash
# 检查迁移状态
php artisan migrate:status

# 验证币种数据
php artisan tinker
>>> \App\Models\Currency::count()
>>> \App\Models\Currency::all()
```

## 📋 API 端点清单

所有端点都需要 `Authorization: Bearer {token}` header（除了认证相关）：

### 钱包
- `GET /api/v1/wallets` - 获取钱包信息

### 入金
- `GET /api/v1/deposits` - 入金历史
- `POST /api/v1/deposits/manual-apply` - 手动申请入金（测试用）

### 提现
- `GET /api/v1/withdrawals` - 提现历史
- `GET /api/v1/withdrawals/calc-withdrawable` - 计算可提现金额
- `POST /api/v1/withdrawals/apply` - 申请提现

### 划转
- `POST /api/v1/transfer` - 账户间划转

### 闪兑
- `POST /api/v1/swap/quote` - 获取报价
- `POST /api/v1/swap/confirm` - 确认闪兑

## 🔑 测试流程建议

### 1. 登录获取 Token

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-login-1" \
  -d '{
    "email": "admin@followplus.com",
    "password": "admin123456"
  }'
```

### 2. 获取钱包信息

```bash
curl -X GET http://localhost:8000/api/v1/wallets \
  -H "Authorization: Bearer {token}"
```

### 3. 手动申请入金（测试）

```bash
curl -X POST http://localhost:8000/api/v1/deposits/manual-apply \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-deposit-1" \
  -d '{
    "amount": "1000.00",
    "currency": "USDT"
  }'
```

### 4. 查看余额

```bash
curl -X GET http://localhost:8000/api/v1/me \
  -H "Authorization: Bearer {token}"
```

### 5. 计算可提现金额

```bash
curl -X GET http://localhost:8000/api/v1/withdrawals/calc-withdrawable \
  -H "Authorization: Bearer {token}"
```

### 6. 申请提现

```bash
curl -X POST http://localhost:8000/api/v1/withdrawals/apply \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-withdraw-1" \
  -d '{
    "amount": "100.00",
    "to_address": "Txxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "currency": "USDT",
    "withdraw_password": "123456"
  }'
```

## ⚠️ 重要提示

1. **所有 POST 请求都需要 `Idempotency-Key` header**
2. **需要先登录获取 JWT token**
3. **金额使用字符串格式传递**（如 "100.00"）
4. **测试环境使用 SQLite 内存数据库**，运行测试无需迁移

## 📝 下一步

运行迁移后，可以：

1. ✅ 测试 API 端点
2. ✅ 编写测试用例
3. ➡️ 开始模块 3（Referral & Rewards Engine）

---

**所有代码已就绪，可以开始测试！** 🚀

