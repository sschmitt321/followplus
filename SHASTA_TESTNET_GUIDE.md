# Shasta 测试网收款功能测试指南

## 📋 前置准备

### 1. 环境变量配置

确保 `.env` 文件中已配置以下参数：

```env
# Shasta 测试网节点 URL
TRON_NODE_URL=https://api.shasta.trongrid.io

# Shasta 测试网 USDT 合约地址（你已设置）
TRON_USDT_CONTRACT=你的测试网USDT合约地址

# 热钱包地址和私钥（你已设置）
TRON_HOT_WALLET_ADDRESS=你的热钱包地址
TRON_HOT_WALLET_PRIVATE_KEY=你的热钱包私钥

# 私钥加密密钥（如果还没有，需要生成）
TRON_PK_ENC_KEY=你的32字节加密密钥

# 确认数要求（测试网可以设置更少，比如 1-3）
TRON_REQUIRED_CONFIRMATIONS=1
```

### 2. 确保用户有 Tron 钱包地址

每个用户需要一个 Tron 充值地址。如果用户还没有，系统会自动通过 HD 钱包生成。

## 🧪 测试步骤

### 步骤 1: 获取用户的 Tron 充值地址

```bash
# 方式 1: 通过 API 获取（需要先登录）
curl -X GET http://localhost:8000/api/v1/wallets \
  -H "Authorization: Bearer {token}"
```

或者通过 Tinker：

```bash
php artisan tinker
>>> $user = \App\Models\User::first();
>>> $walletService = app(\App\Services\Tron\TronHdWalletService::class);
>>> $wallet = $walletService->deriveAddressForUser($user->id);
>>> echo "Address: " . $wallet['address'] . PHP_EOL;
>>> echo "Private Key: " . $wallet['private_key'] . PHP_EOL;
```

### 步骤 2: 从 Shasta 测试网水龙头获取测试 USDT

1. 访问 Shasta 测试网水龙头：https://www.trongrid.io/faucet
2. 输入你的用户充值地址
3. 获取测试 USDT（通常每次可以获取 10000 USDT）

### 步骤 3: 向用户充值地址发送测试 USDT

使用 TronLink 或其他钱包工具，向步骤 1 获取的地址发送测试 USDT。

**注意**：确保发送地址有足够的 TRX 作为 gas（可以从水龙头获取 TRX）。

### 步骤 4: 扫描充值记录

运行扫描命令，查找新的充值：

```bash
php artisan tron:scan-deposits
```

输出示例：
```
Scanning for new Tron deposits...
Found 1 new deposit(s).
```

### 步骤 5: 更新确认数并入账

运行更新确认数命令，当确认数达到要求后会自动入账：

```bash
php artisan tron:update-confirms
```

输出示例：
```
Updating deposit confirmations...
Credited 1 deposit(s).
```

### 步骤 6: 验证充值记录

通过 Tinker 查看充值记录：

```bash
php artisan tinker
>>> $deposits = \App\Models\TronDeposit::orderBy('created_at', 'desc')->limit(5)->get();
>>> foreach ($deposits as $deposit) {
...     echo "TXID: {$deposit->txid}\n";
...     echo "Address: {$deposit->tron_address}\n";
...     echo "Amount: {$deposit->amount}\n";
...     echo "Status: {$deposit->status}\n";
...     echo "Confirmations: {$deposit->confirmations}/{$deposit->required_confirmations}\n";
...     echo "---\n";
... }
```

或者通过 API：

```bash
curl -X GET http://localhost:8000/api/v1/deposits \
  -H "Authorization: Bearer {token}"
```

### 步骤 7: 验证用户余额

```bash
curl -X GET http://localhost:8000/api/v1/me \
  -H "Authorization: Bearer {token}"
```

查看 `assets` 字段中的余额是否已更新。

## 🔄 自动化测试脚本

可以创建一个测试脚本来自动化整个流程：

```bash
#!/bin/bash

# 1. 扫描充值
echo "Step 1: Scanning for deposits..."
php artisan tron:scan-deposits

# 2. 更新确认数
echo "Step 2: Updating confirmations..."
php artisan tron:update-confirms

# 3. 显示最新充值记录
echo "Step 3: Latest deposits:"
php artisan tinker --execute="
\$deposits = \App\Models\TronDeposit::orderBy('created_at', 'desc')->limit(3)->get();
foreach (\$deposits as \$deposit) {
    echo \"TXID: {\$deposit->txid}\n\";
    echo \"Amount: {\$deposit->amount} USDT\n\";
    echo \"Status: {\$deposit->status}\n\";
    echo \"Confirmations: {\$deposit->confirmations}/{\$deposit->required_confirmations}\n\";
    echo \"---\n\";
}
"
```

## 📊 充值状态说明

- **pending**: 已发现充值，等待确认数达到要求
- **confirmed**: 确认数已达到要求，但尚未入账
- **credited**: 已入账到用户账户

## ⚠️ 常见问题

### 1. 扫描不到充值记录

**可能原因**：
- 节点 URL 配置错误
- USDT 合约地址配置错误
- 时间范围太短（默认扫描最近 1 小时）

**解决方法**：
- 检查 `.env` 配置
- 修改 `TronDepositService::scanNewDeposits()` 中的时间范围

### 2. 确认数不更新

**可能原因**：
- 节点 API 返回错误
- 交易 ID 不正确

**解决方法**：
- 检查日志：`storage/logs/laravel.log`
- 手动查询交易确认数

### 3. 入账失败

**可能原因**：
- 数据库事务失败
- 用户账户不存在

**解决方法**：
- 检查日志
- 确认用户 ID 正确

## 🔍 调试命令

### 查看节点连接状态

```bash
php artisan tinker --execute="
\$client = app(\App\Services\Tron\TronNodeClient::class);
\$blockNumber = \$client->getCurrentBlockNumber();
echo 'Current block number: ' . \$blockNumber . PHP_EOL;
"
```

### 查看用户钱包地址

```bash
php artisan tinker --execute="
\$wallets = \App\Models\UserTronWallet::all();
foreach (\$wallets as \$wallet) {
    echo \"User ID: {\$wallet->user_id}, Address: {\$wallet->tron_address}\n\";
}
"
```

### 手动查询 USDT 余额

```bash
php artisan tinker --execute="
\$contract = app(\App\Services\Tron\TronUsdtContract::class);
\$address = '你的地址';
\$balance = \$contract->getBalance(\$address);
echo \"Balance: {\$balance} USDT\n\";
"
```

## 📝 下一步

测试通过后，可以：

1. ✅ 设置定时任务自动扫描充值
2. ✅ 配置生产环境的节点和合约地址
3. ✅ 实现归集功能（将用户地址的 USDT 归集到热钱包）
4. ✅ 实现提现功能

---

**祝测试顺利！** 🚀

