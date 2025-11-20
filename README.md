# FollowPlus - 跟单交易平台

## 项目概述

FollowPlus 是一个基于 Laravel 12 的跟单交易平台，采用 DDD（领域驱动设计）架构。

## 技术栈

- **框架**: Laravel 12
- **PHP**: 8.2+
- **认证**: JWT (php-open-source-saver/jwt-auth)
- **测试**: Pest PHP
- **数据库**: MySQL/PostgreSQL/SQLite

## 项目结构

```
app/
├── Domain/              # 领域模型（DDD）
│   ├── Auth/
│   ├── User/
│   ├── Kyc/
│   ├── System/
│   └── Audit/
├── Services/            # 业务服务层
│   ├── Auth/
│   ├── Kyc/
│   ├── System/
│   └── Audit/
├── Http/
│   ├── Controllers/
│   │   └── Api/V1/     # API 控制器
│   ├── Middleware/     # 中间件
│   ├── Requests/        # 请求验证
│   └── Resources/       # API 资源
└── Models/              # Eloquent 模型

database/
├── migrations/          # 数据库迁移
└── seeders/             # 数据填充

routes/
└── api.php              # API 路由
```

## 安装与配置

### 1. 安装依赖

```bash
composer install
```

### 2. 环境配置

复制 `.env.example` 到 `.env` 并配置：

```bash
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

### 3. 数据库迁移

```bash
php artisan migrate
php artisan db:seed
```

## 模块进度

### ✅ 模块 1: Foundation & Identity（基础平台与身份认证）

- [x] Laravel 项目初始化（DDD 分层）
- [x] JWT 认证（注册/登录/刷新）
- [x] 密码重置功能（用户自助重置、管理员重置）
- [x] 中间件：Idempotency、RateLimit、Auth
- [x] 数据库表：users, user_profiles, user_kyc, system_configs, audit_logs, password_reset_tokens
- [x] 控制器：AuthController, KycController, SystemConfigController, MeController, AdminUserController
- [x] 管理员种子数据
- [ ] 单元测试（进行中）

### 🔄 模块 2: Accounts & Ledger（资产账户与账本）

待实现

### 🔄 模块 3: Referral & Rewards Engine（邀请关系、等级与奖励）

待实现

### ✅ 模块 4: Follow Core（跟单核心功能）

- [x] 跟单窗口管理（固定窗、加餐窗）
- [x] 邀请码管理
- [x] 配额管理（基础配额、加餐配额）
- [x] 跟单下单流程
- [x] 订单结算逻辑
- [x] 定时任务（窗口生成、订单结算）

### ✅ 模块 5: Market, System, Admin & Observability（行情/系统/后台）

- [x] 行情数据生成（伪数据）
- [x] 市场API端点（交易对列表、tick数据）
- [x] 系统静态页接口（公告、帮助、版本）
- [x] 管理员后台接口（入金确认、提现审核、窗口管理、公告发布）
- [x] 定时任务（行情数据生成）

## API 端点

### 认证相关

- `POST /api/v1/auth/register` - 注册
- `POST /api/v1/auth/login` - 登录
- `POST /api/v1/auth/refresh` - 刷新令牌
- `POST /api/v1/auth/password/request-reset` - 请求密码重置（发送重置邮件）
- `POST /api/v1/auth/password/reset` - 使用令牌重置密码
- `GET /api/v1/me` - 获取当前用户信息

### KYC 相关

- `GET /api/v1/kyc/status` - 获取 KYC 状态
- `POST /api/v1/kyc/basic` - 提交基础 KYC
- `POST /api/v1/kyc/advanced` - 提交高级 KYC

### 市场数据（CoinGecko）

- `GET /api/v1/tokens/price` - 查询代币价格和涨跌幅
  - 查询参数：
    - `tokens` (可选): 逗号分隔的代币符号，如 `BTC,ETH,BNB`。如果不提供，返回默认代币（BTC, ETH, BNB, SOL, XRP）
    - `vs_currency` (可选): 目标货币，默认为 `usdt`
  - 示例：
    - `/api/v1/tokens/price` - 查询默认代币
    - `/api/v1/tokens/price?tokens=BTC` - 查询单个代币
    - `/api/v1/tokens/price?tokens=BTC,ETH,BNB` - 批量查询
    - `/api/v1/tokens/price?tokens=BTC&vs_currency=usd` - 查询相对于 USD 的价格

- `GET /api/v1/tokens/ohlc` - 查询代币 K 线图数据（OHLC）
  - 查询参数：
    - `token` (必需): 代币符号，如 `BTC`, `ETH`
    - `vs_currency` (可选): 目标货币，默认为 `usd`
    - `days` (可选): 数据天数，默认为 `1`，最大 `365`（可以是小数）
    - `interval` (可选): 期望的时间间隔，可选值：`5m`, `1h`, `1d`
      - `5m` - 5 分钟间隔（最多 1 天数据）
      - `1h` - 每小时间隔（1-90 天数据）
      - `1d` - 每日间隔（90+ 天数据）
  - 数据粒度（CoinGecko 自动根据 days 参数确定）：
    - `days = 1`: 5 分钟间隔数据（约 288 根 K 线/天）
    - `days = 1-90`: 每小时数据（24 根 K 线/天）
    - `days > 90`: 每日数据（1 根 K 线/天，00:00 UTC）
  - 示例：
    - `/api/v1/tokens/ohlc?token=BTC` - 获取 BTC 1 天的 5 分钟 K 线数据
    - `/api/v1/tokens/ohlc?token=BTC&interval=5m` - 明确指定 5 分钟间隔
    - `/api/v1/tokens/ohlc?token=BTC&interval=1h&days=7` - 明确指定每小时间隔，7 天数据
    - `/api/v1/tokens/ohlc?token=BTC&days=7` - 获取 BTC 7 天的每小时 K 线数据
    - `/api/v1/tokens/ohlc?token=ETH&days=90&vs_currency=usdt` - 获取 ETH 90 天的每日 K 线数据
    - `/api/v1/tokens/ohlc?token=ETH&interval=1d&days=365` - 明确指定每日间隔，365 天数据

### 系统配置

- `GET /api/v1/system/configs` - 获取系统配置（只读）

### 管理员接口（需要管理员权限）

- `POST /api/v1/admin/users/reset-password` - 管理员直接重置用户密码

## 开发指南

### 运行测试

```bash
php artisan test
# 或使用 Pest
./vendor/bin/pest
```

### 代码格式化

```bash
./vendor/bin/pint
```

### 命令行工具

#### 重置用户密码

通过命令行直接重置用户密码：

```bash
php artisan user:reset-password {email} {password}
```

**参数说明**：
- `email`: 用户邮箱地址（必需）
- `password`: 新密码（必需，至少8个字符）

**选项**：
- `--force`: 跳过确认提示，直接执行

**示例**：

```bash
# 交互式重置（会显示用户信息并要求确认）
php artisan user:reset-password user@example.com newpassword123

# 强制重置（跳过确认）
php artisan user:reset-password user@example.com newpassword123 --force
```

#### 创建跟单窗口和邀请码

快速创建跟单窗口并自动生成邀请码：

```bash
php artisan follow:create-window-with-token {symbol_id} {window_type} {start_time} {duration_hours}
```

**参数说明**：
- `symbol_id`: 交易对ID（必需）
  - `1` = BTC/USDT
  - `2` = ETH/USDT
  - `3` = BNB/USDT
  - `4` = SOL/USDT
- `window_type`: 窗口类型（必需）
  - `fixed_daily` = 固定每日窗口
  - `newbie_bonus` = 新手奖励窗口
  - `inviter_bonus` = 邀请者奖励窗口
- `start_time`: 开始时间（必需）
  - 格式：`YYYY-MM-DD HH:MM:SS` 或 `YYYY-MM-DD`
  - 如果只提供日期，默认使用当前时间
- `duration_hours`: 持续时间（小时，必需）

**选项**：
- `--token=`: 自定义邀请码（可选，不提供则自动生成8位大写字母）
- `--reward-min=0.5`: 最小奖励率（0-1，默认0.5）
- `--reward-max=0.6`: 最大奖励率（0-1，默认0.6）

**示例**：

```bash
# 创建BTC/USDT的固定窗口，今天14:00开始，持续1小时
php artisan follow:create-window-with-token 1 fixed_daily "2025-11-18 14:00:00" 1

# 创建ETH/USDT的新手奖励窗口，使用自定义邀请码
php artisan follow:create-window-with-token 2 newbie_bonus "2025-11-18 15:00:00" 2 --token="MYCODE123"

# 创建BNB/USDT窗口，自定义奖励率
php artisan follow:create-window-with-token 3 fixed_daily "2025-11-18 16:00:00" 1.5 --reward-min=0.6 --reward-max=0.8
```

## 默认管理员账号

- **邮箱**: admin@followplus.com
- **密码**: admin123456

## 许可证

MIT License
