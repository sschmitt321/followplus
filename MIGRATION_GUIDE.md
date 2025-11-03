# 数据库迁移指南

## 📋 模块 2 数据库迁移

模块 2 需要创建以下数据库表：

1. `currencies` - 币种表
2. `accounts` - 账户表（spot/contract）
3. `ledger_entries` - 账本分录表
4. `user_assets_summary` - 用户资产汇总表
5. `deposits` - 入金记录表
6. `withdrawals` - 提现记录表
7. `internal_transfers` - 内部划转表
8. `swaps` - 闪兑记录表

## 🚀 运行迁移

### 1. 运行所有迁移

```bash
php artisan migrate
```

### 2. 运行种子数据

```bash
php artisan db:seed
```

这会创建：
- 基础币种（USDT, BTC, ETH, USDC）
- 管理员账号

### 3. 如果需要重置数据库

```bash
# 回滚所有迁移（谨慎操作！）
php artisan migrate:rollback

# 或者重置并重新运行
php artisan migrate:fresh --seed
```

## ⚠️ 注意事项

1. **外键约束**: 
   - `accounts` 表依赖 `currencies` 表
   - `ledger_entries` 表依赖 `accounts` 表
   - 其他表依赖 `currencies` 表
   - 迁移会按顺序自动处理

2. **Decimal 精度**: 
   - 所有金额字段使用 `DECIMAL(36,6)` 精度
   - 确保数据库支持该精度

3. **索引**: 
   - 已为常用查询字段添加索引
   - 如需优化，可参考迁移文件中的索引定义

## 📊 表结构概览

### currencies
- `name` (唯一) - 币种名称
- `precision` - 小数精度
- `enabled` - 是否启用

### accounts
- `user_id`, `type`, `currency` (联合唯一)
- `available` - 可用余额
- `frozen` - 冻结余额

### ledger_entries
- `user_id`, `account_id`, `currency`
- `amount` - 金额（正数增加，负数减少）
- `balance_after` - 操作后余额
- `biz_type` - 业务类型
- `ref_id` - 关联业务ID

### user_assets_summary
- `user_id` (唯一)
- `total_balance` - 总余额
- `principal_balance` - 本金余额
- `profit_balance` - 利润余额
- `bonus_balance` - 奖励余额

### deposits / withdrawals / internal_transfers / swaps
- 各自的业务字段
- 状态字段
- 关联用户和币种

## ✅ 验证迁移

运行迁移后，可以验证：

```bash
# 检查迁移状态
php artisan migrate:status

# 查看表结构
php artisan tinker
>>> Schema::getColumnListing('accounts')
>>> Schema::getColumnListing('ledger_entries')
```

## 🔄 回滚迁移

如果需要回滚：

```bash
# 回滚最后一次迁移
php artisan migrate:rollback

# 回滚指定步骤数
php artisan migrate:rollback --step=5

# 回滚所有迁移
php artisan migrate:reset
```

---

**提示**: 生产环境迁移前请备份数据库！

