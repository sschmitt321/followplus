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
- [x] 中间件：Idempotency、RateLimit、Auth
- [x] 数据库表：users, user_profiles, user_kyc, system_configs, audit_logs
- [x] 控制器：AuthController, KycController, SystemConfigController, MeController
- [x] 管理员种子数据
- [ ] 单元测试（进行中）

### 🔄 模块 2: Accounts & Ledger（资产账户与账本）

待实现

### 🔄 模块 3: Referral & Rewards Engine（邀请关系、等级与奖励）

待实现

### 🔄 模块 4: Follow Core（跟单核心功能）

待实现

### 🔄 模块 5: Market, System, Admin & Observability（行情/系统/后台）

待实现

## API 端点

### 认证相关

- `POST /api/v1/auth/register` - 注册
- `POST /api/v1/auth/login` - 登录
- `POST /api/v1/auth/refresh` - 刷新令牌
- `GET /api/v1/me` - 获取当前用户信息

### KYC 相关

- `GET /api/v1/kyc/status` - 获取 KYC 状态
- `POST /api/v1/kyc/basic` - 提交基础 KYC
- `POST /api/v1/kyc/advanced` - 提交高级 KYC

### 系统配置

- `GET /api/v1/system/configs` - 获取系统配置（只读）

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

## 默认管理员账号

- **邮箱**: admin@followplus.com
- **密码**: admin123456

## 许可证

MIT License
