# Scramble API 文档编写指南

## 📝 如何在 Scramble 中添加接口示例和参数说明

Scramble 会自动从控制器代码生成 API 文档。以下是添加详细文档的最佳实践：

## 1. 方法注释结构

在控制器方法上添加详细的 PHPDoc 注释：

```php
/**
 * 方法简要描述。
 * 
 * 详细描述方法的用途、行为、注意事项等。
 * 可以多行描述，支持 Markdown 格式。
 * 
 * @param Request $request 请求对象
 * @param string $request->param_name 参数说明（格式：类型 参数名 说明）
 * @param int|null $request->optional_param 可选参数说明
 * 
 * @return JsonResponse 返回值的详细说明
 * 
 * Request example:
 * {
 *   "param1": "value1",
 *   "param2": "value2"
 * }
 */
public function methodName(Request $request): JsonResponse
{
    // ...
}
```

## 2. 验证规则注释

在验证规则后添加注释，说明参数的作用和要求：

```php
$validated = $request->validate([
    'email' => 'required|email|unique:users,email', // User email address (must be unique)
    'password' => 'required|string|min:8', // Password (minimum 8 characters)
    'amount' => 'required|string|min:0', // Amount as string (e.g., "100.50", must be >= 0)
]);
```

## 3. 路径参数说明

对于路径参数，在方法注释中说明：

```php
/**
 * Get latest tick for a symbol.
 * 
 * @param int $id Symbol ID (path parameter)
 * 
 * Path example: /api/v1/symbols/1/tick
 */
public function tick(int $id): JsonResponse
{
    // ...
}
```

## 4. 查询参数说明

对于查询参数，在方法注释中说明：

```php
/**
 * Get available windows.
 * 
 * @param Request $request Query parameters
 * @param string|null $request->date Optional. Date in YYYY-MM-DD format (default: today)
 * 
 * Query example: ?date=2025-11-06
 */
public function availableWindows(Request $request): JsonResponse
{
    // ...
}
```

## 5. 参数说明格式

### 必填参数
```
@param string $request->param_name Required. 参数说明。详细要求和限制。
```

### 可选参数
```
@param string|null $request->param_name Optional. 参数说明。默认值和可选值的说明。
```

### 参数值要求
在注释中说明：
- 格式要求（如：YYYY-MM-DD 格式）
- 取值范围（如：必须是 "spot" 或 "contract"）
- 长度限制（如：max 255 characters）
- 验证规则（如：must be unique, must exist in system）

## 6. 示例格式

### 请求体示例
```php
/**
 * Request example:
 * {
 *   "email": "user@example.com",
 *   "password": "password123",
 *   "invite_code": "ABC12345"
 * }
 */
```

### 查询参数示例
```php
/**
 * Query example: ?date=2025-11-06&status=pending
 */
```

### 路径参数示例
```php
/**
 * Path example: /api/v1/symbols/1/tick
 */
```

## 7. 返回值说明

在 `@return` 标签中详细说明返回的数据结构：

```php
/**
 * @return JsonResponse Returns paginated deposit list with metadata:
 * - deposits: Array of deposit records
 * - pagination: Pagination metadata (current_page, total_pages, total)
 */
```

## 8. 完整示例

```php
/**
 * Register a new user.
 * 
 * This endpoint allows users to create a new account. After successful registration,
 * the user will receive JWT access and refresh tokens.
 * 
 * @param Request $request
 * @param string $request->email Required. User email address. Must be valid email format and unique in the system.
 * @param string $request->password Required. User password. Must be at least 8 characters long.
 * @param string|null $request->invite_code Optional. Invite code from an existing user. If provided, establishes referral relationship.
 * 
 * @return JsonResponse Returns access and refresh tokens on success.
 * 
 * Request example:
 * {
 *   "email": "user@example.com",
 *   "password": "password123",
 *   "invite_code": "ABC12345"
 * }
 */
public function register(Request $request): JsonResponse
{
    $validated = $request->validate([
        'email' => 'required|email|unique:users,email', // User email address (must be unique)
        'password' => 'required|string|min:8', // Password (minimum 8 characters)
        'invite_code' => 'nullable|string|exists:users,invite_code', // Optional invite code from existing user
    ]);
    
    // ...
}
```

## 9. Scramble 自动识别的内容

Scramble 会自动识别以下内容：

1. **验证规则** → 转换为参数类型、必填性、格式要求
2. **方法注释** → 显示为端点描述
3. **返回类型** → 推断响应结构
4. **路由参数** → 自动识别路径参数
5. **中间件** → 显示认证要求

## 10. 查看文档效果

访问文档页面查看效果：
```
http://localhost:8000/docs/api
```

文档会自动更新，无需重启服务器。

## 11. 参数说明的最佳实践

### ✅ 好的参数说明
```php
@param string $request->email Required. User email address. Must be valid email format and unique in the system.
@param string $request->amount Required. Deposit amount as string (e.g., "100.50"). Must be >= 0.
@param string|null $request->currency Optional. Currency code (default: "USDT"). Must exist in currencies table.
```

### ❌ 不好的参数说明
```php
@param string $request->email Email
@param string $request->amount Amount
```

## 12. 枚举值说明

对于有固定值的参数，明确说明可选值：

```php
@param string $request->from Required. Source account type. Must be either "spot" or "contract".
@param string $request->status Optional. Filter by status. Allowed values: "pending", "approved", "rejected", "paid".
```

## 注意事项

1. **注释中的示例**：Scramble 会读取注释中的示例，但不会自动格式化为代码块
2. **验证规则优先**：验证规则会自动转换为参数文档，注释是补充说明
3. **类型推断**：Scramble 会从验证规则推断类型（string, integer, boolean 等）
4. **必填性**：`required` 规则会自动标记为必填参数
5. **格式验证**：`email`, `date`, `url` 等规则会自动添加格式说明

---

**提示**：在代码中保持注释和验证规则的一致性能获得最好的文档效果。

