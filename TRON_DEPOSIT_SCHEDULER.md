# Tron 充值扫描和确认定时任务配置

## 📋 命令说明

### 1. `tron:scan-deposits`
**功能**：扫描 Tron 链上的新 USDT 充值

**作用**：
- 从 TronGrid API 获取最近的 Transfer 事件（默认最近 1 小时）
- 检查这些事件是否发送到我们的用户充值地址
- 如果发现新的充值，创建 `pending` 状态的 `TronDeposit` 记录

**执行频率建议**：每 1-2 分钟

### 2. `tron:update-confirms`
**功能**：更新充值确认数并自动入账

**作用**：
- 查询所有 `pending` 或 `confirmed` 状态的充值记录
- 更新每条记录的当前确认数
- 当确认数达到要求（默认 20，测试网可设置为 1）时：
  - 标记为 `confirmed`
  - 自动入账到用户账户
  - 标记为 `credited`

**执行频率建议**：每 2-5 分钟

### 3. `tron:process-deposits`（推荐）
**功能**：综合命令，依次执行扫描和更新

**作用**：
- 先执行 `scanNewDeposits()` 扫描新充值
- 再执行 `updateConfirmationsAndCredit()` 更新确认数并入账

**执行频率建议**：每 2 分钟

## 🔄 执行顺序

### 推荐方式：使用综合命令
```bash
php artisan tron:process-deposits
```

这个命令会：
1. 先扫描新充值
2. 再更新确认数并入账

### 分离方式：分别执行
```bash
# 1. 先扫描新充值
php artisan tron:scan-deposits

# 2. 再更新确认数（可以多次执行，直到确认数足够）
php artisan tron:update-confirms
```

**注意**：
- `scan-deposits` 和 `update-confirms` 可以独立运行
- `scan-deposits` 只处理**新**的充值（不会重复创建）
- `update-confirms` 只处理**已存在**的记录（不会创建新记录）
- 两个命令可以同时运行，没有冲突

## ⏰ 定时任务配置

### 方式 1：使用综合命令（推荐）

在 `routes/console.php` 中已配置：

```php
// 每 2 分钟执行一次（扫描 + 更新）
Schedule::command('tron:process-deposits')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/tron-deposits.log'));
```

### 方式 2：分离执行（更灵活）

```php
// 每 1 分钟扫描新充值
Schedule::command('tron:scan-deposits')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/tron-scan.log'));

// 每 2 分钟更新确认数
Schedule::command('tron:update-confirms')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/tron-confirms.log'));
```

## 🚀 启动定时任务

### 1. 确保 Laravel Scheduler 正在运行

在服务器上添加 cron 任务：

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### 2. 测试定时任务

```bash
# 手动执行一次
php artisan tron:process-deposits

# 查看调度列表
php artisan schedule:list

# 测试调度（不实际执行）
php artisan schedule:test
```

## 📊 充值状态流程

```
链上转账
    ↓
scan-deposits 发现 → status: pending, confirmations: 0
    ↓
update-confirms 更新确认数 → confirmations: 1, 2, 3...
    ↓
确认数达到要求 → status: confirmed
    ↓
自动入账 → status: credited
```

## ⚙️ 配置参数

在 `.env` 文件中配置：

```env
# Tron 节点 URL（测试网）
TRON_NODE_URL=https://api.shasta.trongrid.io

# USDT 合约地址（测试网）
TRON_USDT_CONTRACT=TG3XXyExBkPp9nzdajDZsozEu4BkaSJozs

# 所需确认数（测试网可以设置更少，比如 1-3）
TRON_REQUIRED_CONFIRMATIONS=1

# TronGrid API Key（可选，但建议配置以提高速率限制）
TRON_API_KEY=your_api_key_here
```

## 🔍 监控和日志

### 查看日志

```bash
# 查看调度日志
tail -f storage/logs/tron-deposits.log

# 查看 Laravel 日志
tail -f storage/logs/laravel.log | grep TronDepositService
```

### 检查充值记录

```bash
php artisan tinker
>>> \App\Models\TronDeposit::orderBy('created_at', 'desc')->limit(10)->get(['id', 'txid', 'amount', 'status', 'confirmations', 'created_at']);
```

## 📝 最佳实践

1. **测试网环境**：
   - 设置 `TRON_REQUIRED_CONFIRMATIONS=1`（快速确认）
   - 每 1-2 分钟执行一次扫描

2. **生产环境**：
   - 设置 `TRON_REQUIRED_CONFIRMATIONS=20`（安全确认）
   - 每 2-5 分钟执行一次扫描
   - 配置 API Key 以提高速率限制

3. **监控**：
   - 定期检查日志文件
   - 监控充值记录的 `status` 字段
   - 设置告警（如果长时间没有新充值或确认数不更新）

4. **性能优化**：
   - 如果用户很多，考虑分批扫描
   - 使用队列处理入账操作（避免阻塞）
   - 缓存用户地址列表

## ⚠️ 注意事项

1. **API 速率限制**：
   - TronGrid 免费版有速率限制
   - 建议配置 API Key
   - 如果频繁调用，考虑增加执行间隔

2. **确认数设置**：
   - 测试网可以设置为 1（快速测试）
   - 生产环境建议 20 或更多（安全性）

3. **时间范围**：
   - `scanNewDeposits()` 默认扫描最近 1 小时
   - 如果执行频率较低，可能需要调整时间范围

4. **重复执行安全性**：
   - `scan-deposits` 会检查 `txid` 是否已存在，不会重复创建
   - `update-confirms` 可以安全地多次执行

---

**配置完成后，系统会自动扫描充值并处理入账！** 🎉

