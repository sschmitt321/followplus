# TRON USDT 批量转账任务系统使用指南

## 📋 概述

本系统实现了 TRON 地址的批量 USDT 转账管理，包括：
- 余额扫描和状态管理
- 自动 USDT 转账
- 自动 TRX 充值（gas 补给）

## 🗄️ 数据库结构

### addresses_liquidity 表

存储每个地址的余额和处理状态：

- `address`: TRON 地址（唯一）
- `trx_balance`: TRX 余额
- `usdt_balance`: USDT 余额
- `dt_balance`: DT 余额（可选）
- `status`: 状态（见下方状态机说明）
- `gas_strategy`: Gas 策略（USE_TRX / USE_DT / NEED_TOPUP）
- `last_checked_at`: 最近一次余额检查时间
- `last_tx_hash`: 最近一次 USDT 转账 tx hash
- `last_topup_hash`: 最近一次 TRX 补给 tx hash
- `error_code`: 错误码
- `error_message`: 错误信息

### 状态机

- `NEW`: 刚录入的地址，还没被扫描过余额
- `READY_TO_TRANSFER`: 已扫描余额，符合条件，可以直接发起 USDT 转账
- `NEED_TRX_TOPUP`: USDT 足够但 TRX 不足，需要补 TRX
- `TRX_TOPPED_UP`: 已经补过 TRX，等待下一轮余额扫描重新判断
- `SKIP_SMALL_BALANCE`: USDT 太少，当前轮不处理
- `TRANSFER_SENT`: 已经发起转账，等待链上确认
- `DONE`: USDT 转账成功，当前地址处理完毕
- `FAILED`: 某个关键步骤失败，需要人工排查

## ⚙️ 配置

在 `.env` 文件中添加以下配置：

```env
# 批量转账配置 - 启用自动归集任务
TRON_AUTO_COLLECTION=true                 # 启用自动归集任务（必须设置为 true 才会执行定时任务）

# 必要配置（必须配置才能启用自动归集）
TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY=   # 主钱包的私钥（hex 格式，64位十六进制字符串）
                                          # 或者配置 TRON_GAS_BANK_PRIVATE_KEY 作为替代
TRON_BATCH_MAIN_USDT_WALLET=              # 接收 USDT 的目标地址（主钱包）

# 可选配置
TRON_BATCH_MIN_TRX=6.0                    # 最小 TRX 余额阈值（默认：6）
TRON_BATCH_MIN_USDT=50.0                  # 最小 USDT 余额阈值（默认：50）
TRON_BATCH_TRX_TOPUP_AMOUNT=8.0           # 给单个地址充值的 TRX 数量（默认：8）
TRON_BATCH_MAIN_TRX_WALLET=               # 用来给其他地址充值 TRX 的主钱包地址（可选）
TRON_BATCH_SCAN_INTERVAL_SECONDS=300      # 扫描间隔（秒，默认：300，即 5 分钟）
```

### 配置检查

系统会在启动时检查以下配置：
- ✅ `TRON_AUTO_COLLECTION=true` - 必须启用
- ✅ `TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY` 或 `TRON_GAS_BANK_PRIVATE_KEY` - 至少配置一个
- ✅ `TRON_BATCH_MAIN_USDT_WALLET` - 必须配置

**如果配置不完整，定时任务将不会执行**，但可以手动运行命令进行测试。

## 🚀 使用方式

### 1. 数据库迁移

首先运行数据库迁移：

```bash
php artisan migrate
```

### 2. 同步地址

**重要**：系统会自动从 `user_tron_wallets` 表同步地址，无需手动导入！

#### 方式 1：命令行同步

```bash
# 手动同步地址（从 user_tron_wallets 表）
php artisan liquidity:sync-addresses
```

#### 方式 2：API 同步

```bash
POST /api/v1/batch-transfer/addresses/sync
Authorization: Bearer {admin_token}
```

#### 方式 3：自动同步

余额扫描命令会自动同步地址（默认启用）：

```bash
php artisan liquidity:scan-balances
```

如果不想自动同步，可以使用 `--no-sync` 选项：

```bash
php artisan liquidity:scan-balances --no-sync
```

#### 手动导入（可选）

如果需要导入不在 `user_tron_wallets` 表中的额外地址：

```bash
# 命令行导入
php artisan liquidity:import-addresses TAddress1 TAddress2 TAddress3

# API 导入
POST /api/v1/batch-transfer/addresses/import
Authorization: Bearer {admin_token}

{
  "addresses": ["TAddress1", "TAddress2"]
}
```

### 3. 余额扫描

系统会自动定时扫描余额（默认每 5 分钟），也可以手动触发：

#### 命令行

```bash
# 扫描所有待扫描地址
php artisan liquidity:scan-balances

# 限制扫描数量
php artisan liquidity:scan-balances --limit=10

# 扫描特定状态
php artisan liquidity:scan-balances --status=NEW,TRX_TOPPED_UP
```

#### API

```bash
POST /api/v1/batch-transfer/scan-balances
Authorization: Bearer {admin_token}

{
  "limit": 10,
  "status": "NEW,TRX_TOPPED_UP"
}
```

### 4. USDT 转账

系统会自动处理符合条件的地址（默认每分钟），也可以手动触发：

#### 命令行

```bash
# 处理所有符合条件的地址
php artisan liquidity:transfer-usdt

# 限制处理数量
php artisan liquidity:transfer-usdt --limit=5
```

#### API

```bash
POST /api/v1/batch-transfer/transfer-usdt
Authorization: Bearer {admin_token}

{
  "limit": 5
}
```

### 5. TRX 充值

系统会自动处理需要 TRX 的地址（默认每分钟），也可以手动触发：

#### 命令行

```bash
# 处理所有需要 TRX 的地址
php artisan liquidity:topup-trx

# 限制处理数量
php artisan liquidity:topup-trx --limit=5
```

#### API

```bash
POST /api/v1/batch-transfer/topup-trx
Authorization: Bearer {admin_token}

{
  "limit": 5
}
```

### 6. 查看地址列表和统计

#### API

```bash
# 获取地址列表
GET /api/v1/batch-transfer/addresses?status=READY_TO_TRANSFER&page=1&per_page=20
Authorization: Bearer {admin_token}

# 获取统计信息
GET /api/v1/batch-transfer/stats
Authorization: Bearer {admin_token}
```

## 📅 定时任务配置

系统已在 `routes/console.php` 中配置了定时任务，但**只有在配置完整的情况下才会启用**：

### 启用条件

定时任务只有在以下条件**全部满足**时才会执行：

1. ✅ `TRON_AUTO_COLLECTION=true` 已设置
2. ✅ `TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY` 或 `TRON_GAS_BANK_PRIVATE_KEY` 已配置
3. ✅ `TRON_BATCH_MAIN_USDT_WALLET` 已配置

### 定时任务频率

如果配置完整，定时任务将按以下频率执行：

- **余额扫描**: 每 5 分钟执行一次
- **USDT 转账**: 每分钟执行一次
- **TRX 充值**: 每分钟执行一次

### 检查定时任务状态

```bash
# 查看所有定时任务（包括是否启用）
php artisan schedule:list

# 如果看到 liquidity:* 命令，说明配置正确
# 如果没有看到，检查配置和日志
```

### 确保 Laravel Scheduler 正在运行

```bash
# 在 crontab 中添加（如果还没有）
* * * * * cd /path/to/followplus && php artisan schedule:run >> /dev/null 2>&1
```

### 配置不完整时的处理

如果配置不完整，系统会在日志中记录原因：

```bash
# 查看日志了解为什么定时任务未启用
tail -f storage/logs/laravel.log | grep "Auto collection"
```

## 🔄 工作流程

1. **导入地址** → 状态：`NEW`
2. **余额扫描** → 根据余额更新状态：
   - `trx >= MIN_TRX && usdt >= MIN_USDT` → `READY_TO_TRANSFER`
   - `usdt >= MIN_USDT && trx < MIN_TRX` → `NEED_TRX_TOPUP`
   - `usdt < MIN_USDT` → `SKIP_SMALL_BALANCE`
3. **TRX 充值**（如果需要）→ 状态：`TRX_TOPPED_UP` → 等待下一轮扫描
4. **USDT 转账** → 状态：`TRANSFER_SENT` → `DONE`（成功）或 `FAILED`（失败）

## 📊 日志

系统会记录详细的日志：

- `storage/logs/liquidity-scan.log`: 余额扫描日志
- `storage/logs/liquidity-transfer.log`: USDT 转账日志
- `storage/logs/liquidity-topup.log`: TRX 充值日志
- `storage/logs/laravel.log`: 通用错误日志

## ⚠️ 注意事项

1. **私钥管理**: 
   - 批量转账需要地址的私钥，系统会从 `user_tron_wallets` 表中查找
   - 如果地址不在该表中，需要确保有其他方式获取私钥

2. **主钱包配置**:
   - `TRON_BATCH_MAIN_TRX_WALLET`: 用于给其他地址充值 TRX 的钱包
   - `TRON_BATCH_MAIN_USDT_WALLET`: 接收 USDT 的目标钱包
   - 确保主钱包有足够的 TRX 余额

3. **Gas 费用**:
   - USDT 转账需要消耗约 5-6 TRX 作为 gas
   - 确保地址有足够的 TRX 或配置了自动充值

4. **并发控制**:
   - 系统使用 `withoutOverlapping()` 防止并发执行
   - 如果任务卡住，会自动释放锁（10 分钟超时）

5. **错误处理**:
   - 失败的地址会标记为 `FAILED` 状态
   - 检查 `error_code` 和 `error_message` 字段了解失败原因

## 🛠️ 故障排查

### 地址一直处于 NEW 状态

- 检查定时任务是否正常运行：`php artisan schedule:list`
- 手动执行扫描：`php artisan liquidity:scan-balances`
- 查看日志：`tail -f storage/logs/liquidity-scan.log`

### USDT 转账失败

- 检查地址是否有足够的 TRX（gas）
- 检查私钥是否正确配置
- 查看日志：`tail -f storage/logs/liquidity-transfer.log`

### TRX 充值失败

- 检查主钱包地址和私钥配置
- 检查主钱包是否有足够的 TRX 余额
- 查看日志：`tail -f storage/logs/liquidity-topup.log`

## 📝 示例

### 完整流程示例

```bash
# 1. 同步地址（从 user_tron_wallets 表，首次运行）
php artisan liquidity:sync-addresses

# 2. 扫描余额（会自动同步新地址）
php artisan liquidity:scan-balances

# 3. 查看状态
# 通过 API 或数据库查询

# 4. 如果需要，手动触发 TRX 充值
php artisan liquidity:topup-trx

# 5. 等待下一轮扫描后，自动或手动触发 USDT 转账
php artisan liquidity:transfer-usdt
```

**注意**：余额扫描命令会自动同步地址，所以通常只需要运行 `liquidity:scan-balances` 即可。

## 🔐 安全建议

1. **私钥安全**: 
   - 不要在代码中硬编码私钥
   - 使用环境变量存储敏感信息
   - 定期轮换主钱包私钥

2. **权限控制**:
   - API 接口需要管理员权限
   - 限制批量操作的数量和频率

3. **监控告警**:
   - 监控失败率
   - 设置余额告警（主钱包余额不足）
   - 监控异常状态地址数量

