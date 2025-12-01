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
- [Tron 钱包管理](#tron-钱包管理)
- [Tron 充值管理](#tron-充值管理)
- [Tron 调试工具](#tron-调试工具)

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

### `withdraw:review`

**功能**：查看、审核和处理提现申请

管理员使用此命令来管理用户的提现申请，包括查看所有申请、审核通过/拒绝、以及处理转账。

**用法**：
```bash
php artisan withdraw:review [--list] [--id=] [--action=] [--note=] [--txid=] [--verify-amount]
```

**选项**：
- `--list`：列出所有提现申请（包括所有状态）
- `--id`：提现申请ID（用于审核指定申请，必填）
- `--action`：操作类型（必填，值：`approve`/`reject`/`process`）
  - `approve`：审核通过（将状态改为 `approved`，余额保持冻结）
  - `reject`：审核拒绝（将状态改为 `rejected`，自动解冻余额）
  - `process`：处理转账（将状态改为 `paid`，执行转账并记录 TXID）
- `--note`：审核备注/说明（可选，拒绝时建议填写原因）
- `--txid`：交易哈希（用于 `process` 操作，非 TRC20 USDT 提现时必填）
- `--verify-amount`：在处理转账前验证用户余额是否足够（可选）

**示例**：

**1. 列出所有提现申请**：
```bash
php artisan withdraw:review --list
```
输出示例：
```
📋 All Withdrawal Requests:

+----+---------+------------------+--------+----------+---------+----------------------+------------------+---------------------+
| ID | User ID | Email            | Amount | Currency | Status  | To Address           | TXID             | Created             |
+----+---------+------------------+--------+----------+---------+----------------------+------------------+---------------------+
| 1  | 25      | user@example.com | 100.00 | USDT     | pending | Txxxxxxxxxxxxx...    | -                | 2025-11-21 10:30:00 |
| 2  | 26      | user2@example.com | 50.00  | USDT     | paid    | Tyyyyyyyyyyyy...    | 0x1234567890...   | 2025-11-21 09:15:00 |
+----+---------+------------------+--------+----------+---------+----------------------+------------------+---------------------+

Status Legend:
  pending   - Waiting for review
  approved  - Approved, waiting for transfer
  rejected  - Rejected by admin
  paid      - Transfer completed
  failed    - Transfer failed
```

**2. 查看提现详情并审核通过（交互式）**：
```bash
php artisan withdraw:review --id=1 --action=approve
```
命令会先显示提现详情表格，然后提示输入审核备注（可选）。

**3. 审核通过（非交互式，带备注）**：
```bash
php artisan withdraw:review --id=1 --action=approve --note="审核通过，金额正确，地址已验证"
```

**4. 审核拒绝（必须提供拒绝原因）**：
```bash
php artisan withdraw:review --id=1 --action=reject --note="地址格式不正确，请检查后重新申请"
```
注意：拒绝申请会自动解冻用户的冻结余额，余额会返回到可用余额。

**5. 处理转账（自动发送 TRC20 USDT，带金额验证）**：
```bash
php artisan withdraw:review --id=1 --action=process --verify-amount
```
此命令会：
- 验证用户余额是否足够
- 显示转账详情并要求确认
- 自动从热钱包发送 USDT 到用户指定地址
- 记录交易 TXID
- 将状态改为 `paid` 并扣除用户余额

**6. 处理转账（手动提供 TXID，适用于非 TRC20 或其他情况）**：
```bash
php artisan withdraw:review --id=1 --action=process --txid="0xabcdef1234567890abcdef1234567890abcdef12" --note="转账完成，已通过其他方式发送"
```

**完整工作流程示例**：

```bash
# 步骤1：查看所有待审核的提现申请
php artisan withdraw:review --list

# 步骤2：审核通过（假设 ID 为 1）
php artisan withdraw:review --id=1 --action=approve --note="审核通过"

# 步骤3：处理转账（自动发送 TRC20 USDT）
php artisan withdraw:review --id=1 --action=process --verify-amount
```

**说明**：

**状态流转**：
- 正常流程：`pending` → `approved` → `paid`
- 拒绝流程：`pending` → `rejected`
- 失败流程：`approved` → `failed`（转账失败时）

**余额处理**：
- **申请时（pending）**：余额从 `available` 转移到 `frozen`（冻结）
- **审核通过（approved）**：余额保持冻结状态
- **审核拒绝（rejected）**：余额从 `frozen` 返回到 `available`（解冻）
- **处理转账（paid）**：余额从 `frozen` 扣除，实际转出
- **转账失败（failed）**：余额保持冻结，需要手动处理

**重要提示**：
- 只有 `pending` 状态的申请可以审核（`approve`/`reject`）
- 只有 `approved` 状态的申请可以处理转账（`process`）
- TRC20 USDT 提现会自动从热钱包发送，无需手动提供 TXID
- 非 TRC20 或其他链的提现需要手动提供 `--txid` 参数
- 审核和处理操作会记录审核时间、审核人（系统用户ID）和审核备注
- 使用 `--verify-amount` 可以在转账前验证用户余额，避免余额不足的情况
- 如果自动转账失败，状态会变为 `failed`，并记录失败原因到 `review_note` 字段

**状态说明**：
- `pending`：待审核（余额已冻结）
- `approved`：已审核通过，等待转账（余额仍冻结）
- `rejected`：已拒绝（余额已解冻）
- `paid`：转账完成（余额已扣除）
- `failed`：转账失败（余额仍冻结，需要重新处理）

**输出信息**：
- 列出申请时会显示：ID、用户ID、邮箱、金额、币种、状态、提现地址、TXID、创建时间
- 审核/处理时会显示：完整的提现详情表格，包括申请金额、手续费、实际到账金额、审核备注等

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
php artisan follow:create-window-with-token {symbol_id} {window_type} {start_time} {duration_minutes} [--token=] [--reward-min=0.5] [--reward-max=0.6] [--auto-token]
```

**参数**：
- `symbol_id`：交易对ID（1=BTC/USDT, 2=ETH/USDT, 3=BNB/USDT, 4=SOL/USDT）（必填）
- `window_type`：窗口类型（fixed_daily/newbie_bonus/inviter_bonus）（必填）
- `start_time`：开始时间（格式: YYYY-MM-DD HH:MM:SS 或 YYYY-MM-DD）（必填）
- `duration_minutes`：持续时间（分钟）（必填）

**选项**：
- `--token`：自定义邀请码（可选，不提供则自动生成）
- `--reward-min`：最小奖励率（0-1，默认：0.5）
- `--reward-max`：最大奖励率（0-1，默认：0.6）
- `--auto-token`：自动生成邀请码（默认启用）

**示例**：
```bash
# 创建固定日窗口（60分钟）
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-20 13:00:00" 60

# 创建5分钟有效期的跟单码
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-20 13:00:00" 5

# 创建新手奖励窗口（使用日期格式，60分钟）
php artisan follow:create-window-with-token 2 newbie_bonus "2025-11-20" 60

# 自定义邀请码
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-20 13:00:00" 60 --token=MYTOKEN123

# 自定义奖励率
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-20 13:00:00" 60 --reward-min=0.4 --reward-max=0.7
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
| `withdraw:review` | 提现审核 | 按需 |
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

---

## Tron 钱包管理

### `tron:init-hd-wallet`

**功能**：初始化 HD 钱包，设置主种子（助记词或十六进制种子）

**用法**：
```bash
php artisan tron:init-hd-wallet [--seed=] [--force]
```

**选项**：
- `--seed`：主种子（助记词或十六进制种子）。如果不提供，将生成新的
- `--force`：强制重新初始化，即使已经初始化过

**示例**：
```bash
# 使用助记词初始化
php artisan tron:init-hd-wallet --seed="word1 word2 ... word12"

# 使用十六进制种子初始化
php artisan tron:init-hd-wallet --seed="0x1234abcd..."

# 生成新的主种子
php artisan tron:init-hd-wallet

# 强制重新初始化（仅在非生产环境可用）
php artisan tron:init-hd-wallet --force
```

**说明**：
- 首次使用前必须初始化 HD 钱包
- 主种子用于派生所有用户地址
- 请妥善保管主种子，丢失将无法恢复地址
- **安全限制**：`--force` 选项在生产环境（`APP_ENV=production`）下已被禁用，以防止意外覆盖已初始化的钱包
  - 在生产环境使用 `--force` 会显示错误信息并拒绝执行
  - 如需在生产环境重新初始化，请手动操作数据库或联系系统管理员

---

### `tron:export-master-seed`

**功能**：导出 HD 钱包主种子（敏感数据，请谨慎使用）

**用法**：
```bash
php artisan tron:export-master-seed [--format=hex] [--output=] [--verify]
```

**选项**：
- `--format`：输出格式（hex 或 mnemonic），默认：hex
- `--output`：保存到文件而不是显示
- `--verify`：通过转换回十六进制验证助记词

**示例**：
```bash
# 导出为十六进制格式
php artisan tron:export-master-seed --format=hex

# 导出为助记词格式
php artisan tron:export-master-seed --format=mnemonic

# 保存到文件
php artisan tron:export-master-seed --output=backup.txt

# 验证助记词
php artisan tron:export-master-seed --format=mnemonic --verify
```

**警告**：
- ⚠️ 此命令会显示敏感的主种子信息
- 任何人拥有此种子都可以控制所有地址
- 请确保在安全环境中使用

---

### `tron:list-wallets`

**功能**：列出所有 Tron 钱包（HD 派生或随机地址）

**用法**：
```bash
php artisan tron:list-wallets [--user-id=] [--show-private-key]
```

**选项**：
- `--user-id`：按特定用户 ID 筛选
- `--show-private-key`：显示解密后的私钥（谨慎使用）

**示例**：
```bash
# 列出所有钱包
php artisan tron:list-wallets

# 列出特定用户的钱包
php artisan tron:list-wallets --user-id=2

# 显示私钥（仅用于调试）
php artisan tron:list-wallets --show-private-key
```

**说明**：
- 显示用户 ID、地址、派生索引等信息
- 默认不显示私钥，除非使用 `--show-private-key` 选项

---

### `tron:recover-hd-wallets`

**功能**：从主种子恢复所有 HD 钱包地址

**用法**：
```bash
php artisan tron:recover-hd-wallets [--index=] [--max=] [--verify] [--export]
```

**选项**：
- `--index`：恢复特定索引的地址
- `--max`：最大索引（默认：next_derivation_index）
- `--verify`：与数据库中的地址进行验证
- `--export`：导出主种子（敏感数据）

**示例**：
```bash
# 恢复所有地址
php artisan tron:recover-hd-wallets

# 恢复特定索引
php artisan tron:recover-hd-wallets --index=5

# 恢复并验证
php artisan tron:recover-hd-wallets --verify
```

**说明**：
- 用于从主种子恢复所有派生地址
- 可以验证恢复的地址是否与数据库一致

---

### `tron:fix-addresses`

**功能**：使用正确的地址生成算法修复用户的 Tron 地址

**用法**：
```bash
php artisan tron:fix-addresses [--user=] [--dry-run]
```

**选项**：
- `--user`：修复特定用户 ID 的地址
- `--dry-run`：显示将要更改的内容，但不实际更新

**示例**：
```bash
# 预览所有需要修复的地址
php artisan tron:fix-addresses --dry-run

# 修复所有地址
php artisan tron:fix-addresses

# 修复特定用户的地址
php artisan tron:fix-addresses --user=2
```

**说明**：
- 用于修复之前错误生成的地址
- 建议先使用 `--dry-run` 预览更改

---

### `tron:fix-derivation-index`

**功能**：修复现有钱包的派生索引

**用法**：
```bash
php artisan tron:fix-derivation-index [--reassign] [--dry-run]
```

**选项**：
- `--reassign`：重新分配派生索引并从 HD 钱包重新生成地址（警告：会更改地址）
- `--dry-run`：显示将要更改的内容，但不实际更新

**示例**：
```bash
# 预览修复
php artisan tron:fix-derivation-index --dry-run

# 修复派生索引
php artisan tron:fix-derivation-index

# 重新分配并重新生成地址（危险操作）
php artisan tron:fix-derivation-index --reassign
```

**警告**：
- ⚠️ `--reassign` 选项会重新生成地址，现有地址会改变
- 请确保在安全环境中使用，并备份数据

---

## Tron 充值管理

### `tron:scan-deposits`

**功能**：扫描 Tron 链上的新 USDT 充值

**用法**：
```bash
php artisan tron:scan-deposits
```

**示例**：
```bash
php artisan tron:scan-deposits
```

**说明**：
- 从 TronGrid API 获取最近的 Transfer 事件（默认最近 24 小时）
- 检查这些事件是否发送到我们的用户充值地址
- 如果发现新的充值，创建 `pending` 状态的 `TronDeposit` 记录
- 日志文件：`storage/logs/tron-scan.log`
- 充值日志：`storage/logs/tron-deposits.log`

**定时任务**：已配置为每分钟执行一次

---

### `tron:update-confirms`

**功能**：更新充值确认数并自动入账

**用法**：
```bash
php artisan tron:update-confirms
```

**示例**：
```bash
php artisan tron:update-confirms
```

**说明**：
- 查询所有 `pending` 或 `confirmed` 状态的充值记录
- 更新每条记录的当前确认数
- 当确认数达到要求（默认 20，测试网可设置为 1）时：
  - 标记为 `confirmed`
  - 自动入账到用户账户
  - 标记为 `credited`
- 日志文件：`storage/logs/tron-confirms.log`
- 充值日志：`storage/logs/tron-deposits.log`

**定时任务**：已配置为每分钟执行一次

---

### `tron:process-deposits`

**功能**：综合命令，依次执行扫描和更新（推荐使用）

**用法**：
```bash
php artisan tron:process-deposits
```

**示例**：
```bash
php artisan tron:process-deposits
```

**说明**：
- 先执行 `scanNewDeposits()` 扫描新充值
- 再执行 `updateConfirmationsAndCredit()` 更新确认数并入账
- 这是推荐的执行方式，确保扫描和更新顺序执行

**定时任务**：已配置为每 2 分钟执行一次（可选）

---

### `tron:reconciliation`

**功能**：生成充值对账报告

**用法**：
```bash
php artisan tron:reconciliation [--start=] [--end=] [--user=] [--status=] [--format=table]
```

**选项**：
- `--start`：开始日期（Y-m-d H:i:s 或 Y-m-d）
- `--end`：结束日期（Y-m-d H:i:s 或 Y-m-d）
- `--user`：按用户 ID 筛选
- `--status`：按状态筛选（pending, confirmed, credited, failed）
- `--format`：输出格式（table, json, csv），默认：table

**示例**：
```bash
# 查看今天的充值记录
php artisan tron:reconciliation --start="2025-11-21" --end="2025-11-21"

# 查看指定日期范围
php artisan tron:reconciliation --start="2025-11-01" --end="2025-11-30"

# 查看指定用户的充值
php artisan tron:reconciliation --user=2 --start="2025-11-21"

# 只查看已入账的充值
php artisan tron:reconciliation --status=credited --start="2025-11-21"

# 导出为 CSV 格式（方便 Excel 分析）
php artisan tron:reconciliation --start="2025-11-21" --format=csv > reconciliation.csv

# 导出为 JSON 格式（方便程序处理）
php artisan tron:reconciliation --start="2025-11-21" --format=json > reconciliation.json
```

**输出内容**：
- 总览统计：总充值数、总金额、按状态分类统计
- 详细列表：每条充值的完整信息（TXID、地址、金额、状态等）
- 按用户汇总：每个用户的充值次数、总金额、已入账金额

**说明**：
- 用于定期对账和财务审计
- 支持多种输出格式，方便后续处理

---

### `tron:sweep`

**功能**：将用户充值地址的 USDT 归集到热钱包

**用法**：
```bash
php artisan tron:sweep
```

**示例**：
```bash
php artisan tron:sweep
```

**说明**：
- 扫描所有用户充值地址
- 将余额超过阈值的地址中的 USDT 转账到平台热钱包
- 需要确保热钱包有足够的 TRX 作为 gas 费

**注意**：
- 此操作会消耗 TRX 作为 gas 费
- 建议定期执行，避免用户地址积累过多 USDT

---

## Tron 调试工具

### `tron:debug-deposit`

**功能**：调试为什么某个充值没有被检测到

**用法**：
```bash
php artisan tron:debug-deposit {address} [--hours=24]
```

**参数**：
- `address`：要调试的 Tron 地址（必填）

**选项**：
- `--hours`：向前查找的小时数，默认：24

**示例**：
```bash
# 调试特定地址
php artisan tron:debug-deposit TLYGdhcxhmTneDcC97wLDY4istM7jpqdCA

# 查找最近 48 小时的交易
php artisan tron:debug-deposit TLYGdhcxhmTneDcC97wLDY4istM7jpqdCA --hours=48
```

**输出内容**：
1. 检查地址是否在系统中
2. 检查 USDT 余额
3. 获取 Transfer 事件
4. 过滤匹配的事件
5. 检查数据库中是否存在
6. 测试 `scanNewDeposits()` 方法

**说明**：
- 用于排查充值检测问题
- 显示详细的调试信息

---

### `tron:test-deposit`

**功能**：在 Shasta 测试网上测试充值扫描和验证

**用法**：
```bash
php artisan tron:test-deposit [--user-id=] [--address=]
```

**选项**：
- `--user-id`：要测试的用户 ID
- `--address`：要检查的 Tron 地址

**示例**：
```bash
# 测试特定用户
php artisan tron:test-deposit --user-id=2

# 测试特定地址
php artisan tron:test-deposit --address=TLYGdhcxhmTneDcC97wLDY4istM7jpqdCA
```

**说明**：
- 用于测试充值功能是否正常工作
- 检查节点连接、合约配置、余额查询等

---

### `tron:verify-mnemonic`

**功能**：验证助记词并与 TokenPocket/TronLink 结果比较

**用法**：
```bash
php artisan tron:verify-mnemonic {mnemonic} [--master-key=] [--path=] [--expected-private=]
```

**参数**：
- `mnemonic`：要验证的助记词（必填）

**选项**：
- `--master-key`：预期的主私钥（十六进制格式）
- `--path`：要测试的派生路径（默认：m/44'/195'/0'/0/15）
- `--expected-private`：派生路径的预期私钥

**示例**：
```bash
# 验证助记词
php artisan tron:verify-mnemonic "word1 word2 ... word12"

# 验证并比较主密钥
php artisan tron:verify-mnemonic "word1 word2 ... word12" --master-key="0x1234..."

# 验证特定派生路径
php artisan tron:verify-mnemonic "word1 word2 ... word12" --path="m/44'/195'/0'/0/0"
```

**说明**：
- 用于验证助记词是否正确
- 可以与 TokenPocket/TronLink 的结果进行比较

---

### `tron:debug-mnemonic`

**功能**：调试助记词和种子生成，查找差异

**用法**：
```bash
php artisan tron:debug-mnemonic {mnemonic} [--expected-master=] [--expected-seed=]
```

**参数**：
- `mnemonic`：要调试的助记词（必填）

**选项**：
- `--expected-master`：预期的主私钥
- `--expected-seed`：预期的种子（十六进制格式）

**示例**：
```bash
# 调试助记词
php artisan tron:debug-mnemonic "word1 word2 ... word12"

# 与预期值比较
php artisan tron:debug-mnemonic "word1 word2 ... word12" --expected-master="0x1234..." --expected-seed="0xabcd..."
```

**说明**：
- 显示详细的种子生成和主密钥派生过程
- 用于排查助记词相关问题

---

## 定时任务配置

以下命令已配置为定时任务（在 `routes/console.php` 中）：

### 活跃的定时任务

- `follow:settle-orders`：每分钟执行一次
- `tron:scan-deposits`：每分钟执行一次
- `tron:update-confirms`：每分钟执行一次

### 已注释的定时任务

- `rewards:grant-newbie-next-day`：每天 00:10 执行
- `rewards:dispatch-dividends`：每周一 00:00 执行
- `follow:generate-windows`：每天 00:05 执行
- `market:generate-ticks`：每分钟执行一次

### 定时任务配置说明

**Tron 充值扫描和确认**：
- 扫描任务：每分钟执行，查询最近 24 小时的交易
- 更新任务：每分钟执行，检查确认数并自动入账
- 锁超时：扫描任务 5 分钟，更新任务 3 分钟
- 日志文件：
  - `storage/logs/tron-scan.log` - 扫描日志
  - `storage/logs/tron-confirms.log` - 确认日志
  - `storage/logs/tron-deposits.log` - 充值日志（按天分割，保留30天）

---

## 快速参考

| 命令 | 功能 | 使用频率 |
|------|------|----------|
| `user:reset-password` | 重置用户密码 | 按需 |
| `deposit:for-user` | 手动充值 | 按需 |
| `withdraw:review` | 提现审核 | 按需 |
| `kyc:review` | KYC审核 | 按需 |
| `follow:create-window-with-token` | 创建跟单窗口 | 按需 |
| `follow:generate-windows` | 批量生成窗口 | 定时/按需 |
| `follow:settle-orders` | 结算订单 | 定时（每分钟） |
| `market:generate-ticks` | 生成行情数据 | 定时（每分钟，已注释） |
| `rewards:grant-newbie-next-day` | 发放新手奖励 | 定时（每天，已注释） |
| `rewards:dispatch-dividends` | 发放分红 | 定时（每周，已注释） |
| `tron:init-hd-wallet` | 初始化HD钱包 | 首次设置 |
| `tron:export-master-seed` | 导出主种子 | 备份时 |
| `tron:list-wallets` | 列出钱包 | 按需 |
| `tron:recover-hd-wallets` | 恢复钱包地址 | 按需 |
| `tron:fix-addresses` | 修复地址 | 按需 |
| `tron:fix-derivation-index` | 修复派生索引 | 按需 |
| `tron:scan-deposits` | 扫描充值 | 定时（每分钟） |
| `tron:update-confirms` | 更新确认数 | 定时（每分钟） |
| `tron:process-deposits` | 处理充值（综合） | 定时（每2分钟，可选） |
| `tron:reconciliation` | 对账报告 | 按需 |
| `tron:sweep` | 归集USDT | 按需 |
| `tron:debug-deposit` | 调试充值 | 排查问题时 |
| `tron:test-deposit` | 测试充值 | 测试时 |
| `tron:verify-mnemonic` | 验证助记词 | 按需 |
| `tron:debug-mnemonic` | 调试助记词 | 排查问题时 |

---

## 注意事项

1. **时区处理**：大部分命令使用 UTC 时间存储，但 `follow:create-window-with-token` 命令的输入时间按 UTC+8 处理
2. **确认提示**：大部分命令都有确认提示，可以使用 `--force` 选项跳过
3. **审计日志**：重要操作（如充值、审核）会记录审计日志
4. **错误处理**：所有命令都有完善的错误处理和提示信息
5. **敏感数据**：涉及私钥和种子的命令会显示警告，请谨慎使用
6. **日志文件**：
   - Tron 充值相关日志：`storage/logs/tron-deposits.log`（按天分割，保留30天）
   - 扫描日志：`storage/logs/tron-scan.log`
   - 确认日志：`storage/logs/tron-confirms.log`
   - 调度日志：`storage/logs/scheduler.log`

---

最后更新：2025-11-21

