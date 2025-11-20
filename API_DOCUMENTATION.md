# FollowPlus API 文档

## 📚 访问 API 文档

Scramble 已配置并安装，API 文档可以通过以下方式访问：

### Web 界面
访问浏览器打开：`http://localhost:8000/docs/api`

### OpenAPI JSON
获取 OpenAPI 规范 JSON：`http://localhost:8000/docs/api.json`

## 🔧 配置说明

Scramble 配置文件位于：`config/scramble.php`

主要配置项：
- **API 路径**: `api` - 所有以 `/api` 开头的路由都会被包含在文档中
- **文档路径**: `/docs/api` - Web 界面访问路径
- **API 版本**: `1.0.0`
- **标题**: `FollowPlus API`
- **描述**: `FollowPlus API Documentation - A copy trading platform built with Laravel.`

## 📝 文档生成说明

Scramble 会自动从以下内容生成文档：

1. **路由定义** (`routes/api.php`)
   - 自动识别所有 API 路由
   - 支持路由分组和中间件

2. **控制器方法**
   - 方法注释会被用作端点描述
   - 请求验证规则会被转换为参数文档
   - 返回类型和响应结构会被自动分析

3. **请求验证**
   - Laravel 的验证规则会被转换为参数说明
   - 支持类型、格式、必填等验证规则

4. **响应类型**
   - `JsonResponse` 返回类型会被分析
   - 返回的数据结构会被自动推断

## 🎨 文档特性

- ✅ 自动生成 OpenAPI 3.0 规范
- ✅ 交互式 API 测试（Try It 功能）
- ✅ 自动识别认证机制（JWT Bearer Token）
- ✅ 请求/响应示例
- ✅ 参数验证说明
- ✅ 错误响应文档

## 🔐 认证说明

文档中已配置 JWT Bearer Token 认证：

1. 点击文档右上角的 "Authorize" 按钮
2. 输入您的 JWT Token（格式：`Bearer {token}` 或直接输入 token）
3. 所有需要认证的端点都会自动使用该 token

## 📋 API 端点覆盖

文档包含以下模块的所有端点：

### 重要更新说明

#### 用户状态查询 (`GET /api/v1/me`)
响应中新增 `status` 对象，包含以下字段：
- `is_newbie` (boolean): 是否是"新用户"（注册7天内）
- `kyc_verified` (boolean): 身份是否已验证（KYC状态为approved）
- `has_withdraw_password` (boolean): 是否已设置提现密码
- `has_withdraw_address` (boolean): 是否已设置提现地址

#### 提现申请 (`POST /api/v1/withdrawals/apply`)
- 新增必填参数：`withdraw_password` - 提现密码（用于安全验证）
- 系统会验证用户是否已设置提现密码
- 系统会验证提供的提现密码是否正确

#### 新增提现设置接口
- **设置提现密码** (`POST /api/v1/withdraw/password`)
  - 首次设置：只需提供 `password`（6-20个字符）
  - 修改密码：需要提供 `old_password` 和 `password`
  
- **验证提现密码** (`POST /api/v1/withdraw/password/verify`)
  - 用于在敏感操作前验证密码
  - 返回 `verified` (boolean) 和 `message`
  
- **设置提现地址** (`POST /api/v1/withdraw/address`)
  - 提供 `address` 参数即可设置或更新提现地址
  - 地址最大长度为255个字符

### 认证模块
- `POST /api/v1/auth/register` - 用户注册
- `POST /api/v1/auth/login` - 用户登录
- `POST /api/v1/auth/refresh` - 刷新令牌
- `POST /api/v1/auth/password/request-reset` - 请求密码重置（发送重置邮件）
- `POST /api/v1/auth/password/reset` - 通过邮件token重置密码（忘记密码时使用）
- `POST /api/v1/auth/password/change` - 修改登录密码（需要提供旧密码验证）
- `GET /api/v1/me` - 获取当前用户信息（包含用户状态：新用户、KYC验证状态、提现密码/地址设置状态）

### KYC 模块
- `GET /api/v1/kyc/status` - 获取 KYC 状态
- `POST /api/v1/kyc/basic` - 提交基础 KYC
- `POST /api/v1/kyc/advanced` - 提交高级 KYC

### 钱包模块
- `GET /api/v1/wallets` - 获取钱包信息

### 入金模块
- `GET /api/v1/deposits` - 获取入金历史
- `POST /api/v1/deposits/manual-apply` - 手动申请入金

### 提现模块
- `GET /api/v1/withdrawals` - 获取提现历史
- `GET /api/v1/withdrawals/calc-withdrawable` - 计算可提现金额
- `POST /api/v1/withdrawals/apply` - 申请提现（需要提供提现密码进行验证）

### 提现设置模块
- `POST /api/v1/withdraw/password` - 设置/修改提现密码
  - 首次设置：只需提供 `password`（6-20个字符）
  - 修改密码：需要提供 `old_password` 和 `password`
- `POST /api/v1/withdraw/password/verify` - 验证提现密码
  - 用于在敏感操作前验证密码
  - 返回验证结果和消息
- `POST /api/v1/withdraw/address` - 设置/修改提现地址
  - 提供 `address` 参数（最大255个字符）

### 划转模块
- `POST /api/v1/transfer` - 账户间划转

### 闪兑模块
- `POST /api/v1/swap/quote` - 获取闪兑报价
- `POST /api/v1/swap/confirm` - 确认闪兑

### 邀请模块
- `GET /api/v1/ref/summary` - 获取邀请统计
- `GET /api/v1/ref/rewards` - 获取奖励记录

### 跟单模块
- `GET /api/v1/follow/windows/available` - 获取可用窗口
- `POST /api/v1/follow/order` - 下单
- `GET /api/v1/follow/orders` - 获取订单列表
- `GET /api/v1/follow/summary` - 获取跟单统计

### 市场模块
- `GET /api/v1/symbols` - 获取交易对列表
- `GET /api/v1/symbols/{id}/tick` - 获取最新 tick
- `GET /api/v1/symbols/{id}/tick-history` - 获取历史 tick

### 系统模块
- `GET /api/v1/system/configs` - 获取系统配置
- `GET /api/v1/system/announcements` - 获取系统公告
- `GET /api/v1/system/help` - 获取帮助内容
- `GET /api/v1/system/version` - 获取版本信息
- `GET /api/v1/system/download` - 获取下载链接

### 管理员模块
- `GET /api/v1/admin/deposits` - 获取所有入金记录
- `POST /api/v1/admin/deposits/{id}/confirm` - 确认入金
- `GET /api/v1/admin/withdrawals` - 获取所有提现记录
- `POST /api/v1/admin/withdrawals/{id}/approve` - 审核通过提现
- `POST /api/v1/admin/withdrawals/{id}/reject` - 拒绝提现
- `POST /api/v1/admin/withdrawals/{id}/mark-paid` - 标记提现已支付
- `POST /api/v1/admin/follow-window` - 创建跟单窗口
- `POST /api/v1/admin/invite-token` - 创建邀请码
- `POST /api/v1/admin/system/announcement` - 发布系统公告
- `POST /api/v1/admin/ref/level-recalc` - 重新计算等级
- `POST /api/v1/admin/ref/reward-reverse` - 撤销奖励

## 🚀 导出 OpenAPI 规范

### 导出 JSON 文件
```bash
# 访问文档页面后，Scramble 会自动生成 OpenAPI JSON
# 或使用 curl 下载
curl http://localhost:8000/docs/api.json > api.json
```

### 在其他工具中使用
导出的 OpenAPI JSON 可以在以下工具中使用：
- Postman（导入 OpenAPI）
- Insomnia（导入 OpenAPI）
- Swagger UI
- Redoc
- Stoplight Elements

## 🔄 更新文档

文档会根据代码自动更新，无需手动操作：

1. 修改控制器方法注释 → 文档描述更新
2. 修改验证规则 → 参数文档更新
3. 修改返回结构 → 响应文档更新
4. 添加新路由 → 自动出现在文档中

## 📖 最佳实践

### 添加端点描述
在控制器方法上添加注释：
```php
/**
 * Register a new user.
 * 
 * This endpoint allows users to create a new account.
 */
public function register(Request $request): JsonResponse
{
    // ...
}
```

### 添加参数说明
使用验证规则和注释：
```php
$validated = $request->validate([
    'email' => 'required|email|unique:users,email', // User email address
    'password' => 'required|string|min:8', // Password must be at least 8 characters
]);
```

### 使用 FormRequest 类
对于复杂的验证，使用 FormRequest 类可以获得更好的文档：
```php
class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ];
    }
    
    public function body(): array
    {
        return [
            'email' => 'string',
            'password' => 'string',
        ];
    }
}
```

## 📝 新增接口详细说明

### 修改登录密码

**接口**: `POST /api/v1/auth/password/change`

**说明**: 允许已登录用户修改自己的登录密码，需要提供旧密码进行验证。

**请求**:
```json
{
  "old_password": "oldpassword123",
  "password": "newpassword123"
}
```

**响应**:
```json
{
  "message": "Password has been changed successfully."
}
```

**验证规则**:
- `old_password`: 必填，字符串，当前密码
- `password`: 必填，字符串，新密码（至少8个字符）

**错误响应**:
- 旧密码错误: 422 状态码，返回验证错误信息
- 新密码不符合要求: 422 状态码，返回验证错误信息

### 提现设置接口

#### 1. 设置/修改提现密码

**接口**: `POST /api/v1/withdraw/password`

**首次设置**（用户未设置过提现密码）:
```json
{
  "password": "123456"
}
```

**修改密码**（用户已设置过提现密码）:
```json
{
  "old_password": "123456",
  "password": "654321"
}
```

**响应**:
```json
{
  "message": "提现密码已设置" // 或 "提现密码已更新"
}
```

**验证规则**:
- `password`: 必填，字符串，6-20个字符
- `old_password`: 如果已设置过密码则必填

#### 2. 验证提现密码

**接口**: `POST /api/v1/withdraw/password/verify`

**请求**:
```json
{
  "password": "123456"
}
```

**响应（成功）**:
```json
{
  "verified": true,
  "message": "密码验证成功"
}
```

**响应（失败）**:
```json
{
  "verified": false,
  "message": "密码错误"
}
```

**响应（未设置）**:
```json
{
  "verified": false,
  "error": "提现密码未设置"
}
```

#### 3. 设置/修改提现地址

**接口**: `POST /api/v1/withdraw/address`

**请求**:
```json
{
  "address": "Txxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
}
```

**响应**:
```json
{
  "message": "提现地址已设置"
}
```

**验证规则**:
- `address`: 必填，字符串，最大255个字符

### 用户状态查询

#### GET /api/v1/me 响应示例

```json
{
  "user": {
    "id": 1,
    "email": "user@example.com",
    "invite_code": "ABC123",
    "role": "user",
    "status": "active",
    "first_joined_at": "2025-11-20T00:00:00Z"
  },
  "profile": {
    "name": "张三",
    "city": "北京"
  },
  "kyc": {
    "level": "advanced",
    "status": "approved"
  },
  "role": "user",
  "assets": {
    "total_balance": "1000.000000",
    "principal_balance": "800.000000",
    "profit_balance": "150.000000",
    "bonus_balance": "50.000000",
    "accounts": {
      "USDT": {
        "spot": {
          "available": "500.000000",
          "frozen": "100.000000"
        },
        "contract": {
          "available": "400.000000",
          "frozen": "0.000000"
        }
      }
    }
  },
  "status": {
    "is_newbie": false,
    "kyc_verified": true,
    "has_withdraw_password": true,
    "has_withdraw_address": true
  }
}
```

**status 字段说明**:
- `is_newbie`: 布尔值，表示用户是否是"新用户"（注册7天内）
- `kyc_verified`: 布尔值，表示用户身份是否已验证（KYC状态为approved）
- `has_withdraw_password`: 布尔值，表示是否已设置提现密码
- `has_withdraw_address`: 布尔值，表示是否已设置提现地址

### 提现申请更新

#### POST /api/v1/withdrawals/apply

**请求示例**（新增 `withdraw_password` 字段）:
```json
{
  "amount": "1000.00",
  "to_address": "Txxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "currency": "USDT",
  "chain": "TRC20",
  "withdraw_password": "123456"
}
```

**验证规则**:
- `withdraw_password`: 必填，字符串，用于安全验证
- 系统会检查用户是否已设置提现密码
- 系统会验证提供的提现密码是否正确

**错误响应**:
- 如果未设置提现密码: `"Withdrawal password not set"`
- 如果密码错误: `"Invalid withdrawal password"`

## 🔗 相关链接

- [Scramble 文档](https://scramble.dedoc.co/)
- [OpenAPI 规范](https://swagger.io/specification/)
- [Laravel 文档](https://laravel.com/docs)

---

**提示**: 文档会在开发环境中自动生成和更新，确保代码注释和类型提示完整可以获得更好的文档效果。

