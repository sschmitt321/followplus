# Laravel 定时任务未执行问题诊断指南

## 🔍 问题分析：`follow:settle-orders` 未自动执行

### 可能的原因

#### 1. ⚠️ **Crontab 未配置（最常见）**

Laravel 的调度器需要在系统的 crontab 中配置才能运行。

**检查方法**：
```bash
# 查看当前 crontab 配置
crontab -l
```

**应该看到类似这样的配置**：
```bash
* * * * * cd /path/to/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**如果没有配置，需要添加**：
```bash
# 编辑 crontab
crontab -e

# 添加以下行（替换为实际的项目路径和 PHP 路径）
* * * * * cd /path/to/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**查找 PHP 路径**：
```bash
which php
# 或
whereis php
```

#### 2. ⚠️ **withoutOverlapping() 没有超时时间**

当前配置：
```php
->withoutOverlapping()  // 没有超时时间！
```

如果任务卡住，会一直锁定，导致后续任务无法执行。

**解决方案**：添加超时时间
```php
->withoutOverlapping(5)  // 5 分钟后自动释放锁
```

#### 3. **PHP 路径错误**

Crontab 中的 PHP 路径可能不正确。

**检查方法**：
```bash
# 在服务器上执行
which php
/usr/bin/php -v
```

**修复**：在 crontab 中使用绝对路径：
```bash
* * * * * cd /path/to/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

#### 4. **项目路径错误**

Crontab 中的项目路径可能不正确。

**检查方法**：
```bash
# 确认项目路径
pwd
# 或
ls -la /path/to/followplus
```

#### 5. **权限问题**

Crontab 用户可能没有权限执行命令或写入日志。

**检查方法**：
```bash
# 检查目录权限
ls -la /path/to/followplus/storage/logs/

# 检查 PHP 可执行权限
ls -la /usr/bin/php
```

**修复**：
```bash
# 确保日志目录可写
chmod -R 775 /path/to/followplus/storage/logs/
chown -R www-data:www-data /path/to/followplus/storage/logs/
```

#### 6. **环境变量问题**

Crontab 执行时可能没有正确的环境变量。

**解决方案**：在 crontab 中设置 PATH
```bash
PATH=/usr/local/bin:/usr/bin:/bin
* * * * * cd /path/to/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

#### 7. **Laravel 调度器未正确加载**

虽然配置看起来正确，但可能 Laravel 没有正确加载调度器。

**检查方法**：
```bash
# 查看所有已注册的定时任务
php artisan schedule:list

# 应该能看到 follow:settle-orders
```

## 🔧 诊断步骤

### 步骤 1：检查 Crontab 配置

```bash
crontab -l
```

如果没有看到 `schedule:run`，需要添加。

### 步骤 2：手动测试调度器

```bash
cd /path/to/followplus
php artisan schedule:run
```

如果手动执行成功，说明命令本身没问题，问题在 crontab 配置。

### 步骤 3：查看调度器列表

```bash
php artisan schedule:list
```

确认 `follow:settle-orders` 是否在列表中。

### 步骤 4：检查日志

```bash
# 查看调度器日志
tail -f storage/logs/scheduler.log

# 查看 Laravel 主日志
tail -f storage/logs/laravel.log

# 如果有配置调度器日志
tail -f /tmp/laravel-scheduler.log
```

### 步骤 5：测试命令本身

```bash
php artisan follow:settle-orders
```

确认命令可以正常执行。

### 步骤 6：检查任务是否被锁定

```bash
# 查看缓存中的锁
php artisan cache:clear

# 或者检查 Redis/Database 中的锁
```

## ✅ 解决方案

### 方案 1：修复 withoutOverlapping() 超时问题

在 `routes/console.php` 中修改：

```php
Schedule::command('follow:settle-orders')
    ->everyMinute()
    ->withoutOverlapping(5) // 添加 5 分钟超时
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
```

### 方案 2：配置 Crontab

```bash
# 1. 编辑 crontab
crontab -e

# 2. 添加以下行（替换为实际路径）
* * * * * cd /path/to/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1

# 3. 保存并退出

# 4. 验证配置
crontab -l
```

### 方案 3：使用带日志的 Crontab（调试时）

```bash
* * * * * cd /path/to/followplus && /usr/bin/php artisan schedule:run >> /tmp/laravel-scheduler.log 2>&1
```

然后查看日志：
```bash
tail -f /tmp/laravel-scheduler.log
```

### 方案 4：添加错误处理

在 `routes/console.php` 中添加 `onFailure` 回调：

```php
Schedule::command('follow:settle-orders')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'))
    ->onFailure(function () {
        \Log::error('SettleFollowOrders: Scheduled task failed', [
            'message' => '定时任务执行失败，请检查日志',
        ]);
    });
```

## 🧪 验证修复

修复后，执行以下步骤验证：

```bash
# 1. 清除缓存
php artisan cache:clear

# 2. 查看任务列表
php artisan schedule:list

# 3. 手动运行一次调度器
php artisan schedule:run

# 4. 等待 1-2 分钟，检查日志
tail -f storage/logs/scheduler.log

# 5. 检查 crontab 是否在执行
# 查看系统日志（不同系统位置不同）
# Ubuntu/Debian: /var/log/syslog
# CentOS/RHEL: /var/log/cron
grep CRON /var/log/syslog | tail -20
```

## 📋 完整检查清单

- [ ] Crontab 已配置 `schedule:run`
- [ ] PHP 路径正确（使用绝对路径）
- [ ] 项目路径正确
- [ ] `withoutOverlapping()` 有超时时间
- [ ] 日志目录有写权限
- [ ] 命令可以手动执行：`php artisan follow:settle-orders`
- [ ] 调度器可以手动运行：`php artisan schedule:run`
- [ ] `php artisan schedule:list` 显示任务
- [ ] 日志文件可以正常写入

## 🚨 常见错误

### 错误 1：任务一直显示 "Running"

**原因**：`withoutOverlapping()` 没有超时时间，任务卡住后一直锁定。

**解决**：添加超时时间 `withoutOverlapping(5)`

### 错误 2：Crontab 配置了但任务不执行

**可能原因**：
- PHP 路径错误
- 项目路径错误
- 权限问题
- 环境变量缺失

**解决**：使用绝对路径，检查权限，设置 PATH

### 错误 3：手动执行成功，但定时不执行

**原因**：Crontab 未配置或配置错误。

**解决**：检查并修复 crontab 配置

## 📞 需要更多帮助？

如果以上步骤都无法解决问题，请提供以下信息：

1. `crontab -l` 的输出
2. `php artisan schedule:list` 的输出
3. `php artisan schedule:run` 的输出
4. `storage/logs/scheduler.log` 的内容
5. `storage/logs/laravel.log` 的相关错误信息
6. 系统日志中的 cron 相关错误

