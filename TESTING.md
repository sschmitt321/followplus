# 测试用例文档

## 模块 1: Foundation & Identity 测试用例

### ✅ 已完成的测试文件

#### 1. AuthTest.php - 认证流程测试
- ✅ `user can register successfully` - 用户注册成功
- ✅ `user can register with invite code` - 使用邀请码注册
- ✅ `user cannot register with duplicate email` - 重复邮箱注册失败
- ✅ `user can login successfully` - 用户登录成功
- ✅ `user cannot login with invalid credentials` - 无效凭证登录失败
- ✅ `user can refresh access token` - 刷新访问令牌
- ✅ `user cannot refresh with invalid token` - 无效刷新令牌失败
- ✅ `user can get own information when authenticated` - 获取当前用户信息

#### 2. IdempotencyTest.php - 幂等性测试
- ✅ `idempotency middleware returns cached response for duplicate requests` - 重复请求返回缓存响应
- ✅ `idempotency middleware requires idempotency key for POST requests` - POST 请求需要幂等性密钥
- ✅ `idempotency middleware allows GET requests without key` - GET 请求不需要密钥
- ✅ `idempotency middleware caches response for 24 hours` - 缓存响应 24 小时

#### 3. RateLimitTest.php - 限流测试
- ✅ `rate limit middleware allows requests within limit` - 在限制内允许请求
- ✅ `rate limit middleware returns 429 when limit exceeded` - 超出限制返回 429
- ✅ `rate limit middleware includes rate limit headers` - 包含限流头信息
- ✅ `rate limit is per user when authenticated` - 认证用户独立限流

#### 4. AuditLogTest.php - 审计日志测试
- ✅ `audit service can log events` - 审计服务可以记录事件
- ✅ `audit log can be created without user` - 可以创建无用户的审计日志
- ✅ `audit log stores before and after json correctly` - 正确存储前后 JSON
- ✅ `audit log can be queried by user` - 可按用户查询审计日志
- ✅ `audit log can be queried by action and resource` - 可按操作和资源查询

#### 5. KycTest.php - KYC 测试
- ✅ `user can submit basic KYC` - 用户可以提交基础 KYC
- ✅ `user can submit advanced KYC` - 用户可以提交高级 KYC
- ✅ `admin can review and approve KYC` - 管理员可以审核并批准 KYC
- ✅ `admin can review and reject KYC` - 管理员可以审核并拒绝 KYC
- ✅ `KYC review throws exception for invalid status` - 无效状态抛出异常

## 运行测试

```bash
# 运行所有测试
php artisan test

# 运行特定测试文件
php artisan test tests/Feature/AuthTest.php

# 运行特定测试套件
php artisan test --testsuite=Feature
```

## 测试覆盖率

当前测试覆盖了模块 1 的核心功能：
- ✅ 认证流程（注册、登录、刷新）
- ✅ 幂等性中间件
- ✅ 限流中间件
- ✅ 审计日志服务
- ✅ KYC 服务

## 注意事项

1. **JWT Secret**: 测试环境会自动设置 JWT_SECRET，确保 JWT 功能正常工作
2. **数据库**: 测试使用 SQLite 内存数据库，每次测试后自动清理
3. **缓存**: 部分测试涉及缓存，测试间可能需要清理缓存
4. **Rate Limit**: 限流测试可能需要根据实际运行环境调整请求次数

## 后续改进

- [ ] 添加更多边界情况测试
- [ ] 添加并发测试
- [ ] 提高测试覆盖率到 80%+
- [ ] 添加性能测试

