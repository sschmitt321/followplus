# Artisan 命令使用说明

本文档列出了项目中所有自定义的 Artisan 命令及其功能和使用方法。

---

## 目录

- [用户管理](#用户管理)
- [资金管理](#资金管理)
- [KYC 审核](#kyc-审核)
- [跟单系统](#跟单系统)
- [市场数据](#市场数据)
- [奖励系统](#奖励系统)

---

## 用户管理

### `user:reset-password`

**功能**：重置指定用户的密码

**用法**：
```bash
php artisan user:reset-password {email} {password} [--force]
```

**参数**：
- `email`：用户邮箱地址（必填）
- `password`：新密码（必填，至少8个字符）

**选项**：
- `--force`：跳过确认提示

**示例**：
```bash
# 交互式重置密码
php artisan user:reset-password user@example.com newpassword123

# 跳过确认提示
php artisan user:reset-password user@example.com newpassword123 --force
```

**说明**：
- 会显示用户信息并要求确认
- 密码长度至少需要8个字符
- 使用 `AuthService` 进行密码重置

---

## 资金管理

### `deposit:for-user`

**功能**：为用户手动充值（用于测试或管理）

**用法**：
```bash
php artisan deposit:for-user {user} {amount} [currency=USDT] [--force]
```

**参数**：
- `user`：用户标识（邮箱或用户ID）（必填）
- `amount`：充值金额（必填）
- `currency`：币种代码（可选，默认：USDT）

**选项**：
- `--force`：跳过确认提示

**示例**：
```bash
# 使用邮箱充值
php artisan deposit:for-user user@example.com 1000 USDT

# 使用用户ID充值
php artisan deposit:for-user 123 500 BTC

# 跳过确认提示
php artisan deposit:for-user user@example.com 1000 --force
```

**说明**：
- 自动创建 contract 账户（如果不存在）
- 充值会立即确认并到账
- 如果是首次充值，会自动触发邀请奖励
- 显示充值前后的账户余额信息

---

## KYC 审核

### `kyc:review`

**功能**：批量查询和审核KYC身份认证数据

**用法**：
```bash
php artisan kyc:review [--id=] [--action=] [--reason=] [--all] [--status=]
```

**选项**：
- `--id`：KYC记录ID（用于审核指定记录）
- `--action`：审核操作（approve/reject）
- `--reason`：审核原因（拒绝时必填）
- `--all`：列出所有KYC记录（包括已审核的）
- `--status`：按状态筛选（pending/approved/rejected）

**示例**：

**列出待审核的KYC记录**：
```bash
php artisan kyc:review
```

**列出所有KYC记录**：
```bash
php artisan kyc:review --all
```

**按状态筛选**：
```bash
php artisan kyc:review --status=approved
php artisan kyc:review --status=rejected
```

**审核通过（交互式）**：
```bash
php artisan kyc:review --id=1
# 然后选择"通过"，输入审核备注（可选）
```

**审核通过（非交互式）**：
```bash
php artisan kyc:review --id=1 --action=approve --reason="审核通过"
```

**审核拒绝（非交互式）**：
```bash
php artisan kyc:review --id=1 --action=reject --reason="身份证照片不清晰"
```

**说明**：
- 默认列出所有 `pending` 状态的KYC记录
- 审核操作会记录审计日志
- 拒绝操作必须提供原因
- 通过操作的原因为可选

---

## 跟单系统

### `follow:create-window-with-token`

**功能**：创建跟单窗口并自动生成邀请码

**用法**：
```bash
php artisan follow:create-window-with-token {symbol_id} {window_type} {start_time} {duration_hours} [--token=] [--reward-min=0.5] [--reward-max=0.6] [--auto-token]
```

**参数**：
- `symbol_id`：交易对ID（1=BTC/USDT, 2=ETH/USDT, 3=BNB/USDT, 4=SOL/USDT）（必填）
- `window_type`：窗口类型（fixed_daily/newbie_bonus/inviter_bonus）（必填）
- `start_time`：开始时间（格式: YYYY-MM-DD HH:MM:SS 或 YYYY-MM-DD）（必填）
- `duration_hours`：持续时间（小时）（必填）

**选项**：
- `--token`：自定义邀请码（可选，不提供则自动生成）
- `--reward-min`：最小奖励率（0-1，默认：0.5）
- `--reward-max`：最大奖励率（0-1，默认：0.6）
- `--auto-token`：自动生成邀请码（默认启用）

**示例**：
```bash
# 创建固定日窗口
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-20 13:00:00" 1

# 创建新手奖励窗口（使用日期格式）
php artisan follow:create-window-with-token 2 newbie_bonus "2025-11-20" 1

# 自定义邀请码
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-20 13:00:00" 1 --token=MYTOKEN123

# 自定义奖励率
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-20 13:00:00" 1 --reward-min=0.4 --reward-max=0.7
```

**说明**：
- 输入时间按 UTC+8（中国时区）处理，然后转换为 UTC 存储
- 如果只提供日期，默认使用当前 UTC+8 时间
- 自动创建关联的邀请码
- 会记录审计日志

---

### `follow:generate-windows`

**功能**：为指定日期生成跟单窗口（批量生成）

**用法**：
```bash
php artisan follow:generate-windows [date]
```

**参数**：
- `date`：日期（可选，默认：今天，格式：YYYY-MM-DD）

**示例**：
```bash
# 生成今天的窗口
php artisan follow:generate-windows

# 生成指定日期的窗口
php artisan follow:generate-windows 2025-11-20
```

**说明**：
- 为所有启用的交易对生成窗口
- 固定窗口：13:00 和 20:00（fixed_daily）
- 奖励窗口：12:00, 14:00, 19:00, 21:00（newbie_bonus）
- 每个窗口持续1小时
- 自动为每个窗口生成邀请码

---

### `follow:settle-orders`

**功能**：结算过期的跟单窗口并计算收益

**用法**：
```bash
php artisan follow:settle-orders
```

**示例**：
```bash
php artisan follow:settle-orders
```

**说明**：
- 自动结算所有过期的跟单窗口
- 计算用户的收益并更新账户余额
- 通常通过定时任务每分钟执行一次
- 返回结算的窗口数量

---

## 市场数据

### `market:generate-ticks`

**功能**：为所有启用的交易对生成模拟市场行情数据

**用法**：
```bash
php artisan market:generate-ticks
```

**示例**：
```bash
php artisan market:generate-ticks
```

**说明**：
- 为所有启用的交易对生成模拟的行情数据
- 用于测试和开发环境
- 通常通过定时任务每分钟执行一次
- 返回生成的行情数据数量

---

## 奖励系统

### `rewards:grant-newbie-next-day`

**功能**：为昨天首次充值的用户发放 T+1 次日奖励

**用法**：
```bash
php artisan rewards:grant-newbie-next-day
```

**示例**：
```bash
php artisan rewards:grant-newbie-next-day
```

**说明**：
- 查找昨天注册并完成首次充值的用户
- 为这些用户发放 T+1 次日奖励
- 通常通过定时任务每天 00:10 执行
- 显示发放奖励的用户数量

---

### `rewards:dispatch-dividends`

**功能**：向大使发放周期分红

**用法**：
```bash
php artisan rewards:dispatch-dividends [cycle_date]
```

**参数**：
- `cycle_date`：周期日期（可选，默认：今天，格式：YYYY-MM-DD）

**示例**：
```bash
# 发放今天的周期分红
php artisan rewards:dispatch-dividends

# 发放指定日期的周期分红
php artisan rewards:dispatch-dividends 2025-11-20
```

**说明**：
- 根据周期日期计算并发放大使分红
- 通常通过定时任务每周一 00:00 执行
- 显示发放的周期信息

---

## 定时任务配置

以下命令已配置为定时任务（在 `routes/console.php` 中）：

- `follow:settle-orders`：每分钟执行一次
- `rewards:grant-newbie-next-day`：每天 00:10 执行（已注释）
- `rewards:dispatch-dividends`：每周一 00:00 执行（已注释）
- `follow:generate-windows`：每天 00:05 执行（已注释）
- `market:generate-ticks`：每分钟执行一次（已注释）

---

## 快速参考

| 命令 | 功能 | 使用频率 |
|------|------|----------|
| `user:reset-password` | 重置用户密码 | 按需 |
| `deposit:for-user` | 手动充值 | 按需 |
| `kyc:review` | KYC审核 | 按需 |
| `follow:create-window-with-token` | 创建跟单窗口 | 按需 |
| `follow:generate-windows` | 批量生成窗口 | 定时/按需 |
| `follow:settle-orders` | 结算订单 | 定时（每分钟） |
| `market:generate-ticks` | 生成行情数据 | 定时（每分钟） |
| `rewards:grant-newbie-next-day` | 发放新手奖励 | 定时（每天） |
| `rewards:dispatch-dividends` | 发放分红 | 定时（每周） |

---

## 注意事项

1. **时区处理**：大部分命令使用 UTC 时间存储，但 `follow:create-window-with-token` 命令的输入时间按 UTC+8 处理
2. **确认提示**：大部分命令都有确认提示，可以使用 `--force` 选项跳过
3. **审计日志**：重要操作（如充值、审核）会记录审计日志
4. **错误处理**：所有命令都有完善的错误处理和提示信息

---

最后更新：2025-11-20

