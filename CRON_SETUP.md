# Laravel 定时任务 Crontab 配置指南

## 📋 概述

Laravel 使用任务调度器（Scheduler）来管理定时任务。只需要在 crontab 中添加**一条**命令，Laravel 会自动根据 `routes/console.php` 中的配置执行相应的任务。

## 🔧 Crontab 配置

### 1. 编辑 Crontab

```bash
crontab -e
```

### 2. 添加 Laravel Scheduler 任务

在 crontab 文件末尾添加以下行：

```bash
* * * * * cd /path/to/followplus && php artisan schedule:run >> /dev/null 2>&1
```

**重要**：将 `/path/to/followplus` 替换为你的项目实际路径。

### 3. 使用绝对路径（推荐）

为了确保命令能正确执行，建议使用绝对路径：

```bash
* * * * * cd /Users/joezhu/Sites/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

或者：

```bash
* * * * * cd /Users/joezhu/Sites/followplus && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 4. 带日志输出的配置（调试时使用）

如果需要查看调度器的执行日志：

```bash
* * * * * cd /Users/joezhu/Sites/followplus && /usr/bin/php artisan schedule:run >> /tmp/laravel-scheduler.log 2>&1
```

## 🔍 查找 PHP 路径

如果不确定 PHP 的路径，可以使用以下命令：

```bash
# 查找 PHP 可执行文件路径
which php

# 或者
whereis php

# 查看 PHP 版本
php -v
```

## ✅ 验证配置

### 1. 查看当前 Crontab 配置

```bash
crontab -l
```

应该看到类似这样的输出：
```
* * * * * cd /Users/joezhu/Sites/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 2. 测试 Laravel Scheduler

```bash
# 进入项目目录
cd /Users/joezhu/Sites/followplus

# 查看所有已配置的定时任务
php artisan schedule:list

# 手动运行一次调度器（测试模式，不会实际执行任务）
php artisan schedule:test

# 手动运行一次调度器（实际执行任务）
php artisan schedule:run
```

### 3. 检查任务是否正在运行

```bash
# 查看调度器日志（如果配置了日志输出）
tail -f /tmp/laravel-scheduler.log

# 或者查看 Laravel 日志
tail -f storage/logs/laravel.log

# 查看特定任务的日志
tail -f storage/logs/tron-scan.log
tail -f storage/logs/tron-confirms.log
tail -f storage/logs/scheduler.log
```

## 📝 当前配置的定时任务

根据 `routes/console.php` 的配置，以下任务会自动执行：

### 活跃的定时任务

1. **`follow:settle-orders`**
   - 频率：每分钟
   - 功能：结算跟单订单
   - 日志：`storage/logs/scheduler.log`

2. **`tron:scan-deposits`**
   - 频率：每分钟
   - 功能：扫描 Tron 链上的新 USDT 充值
   - 日志：`storage/logs/tron-scan.log`
   - 锁超时：5 分钟

3. **`tron:update-confirms`**
   - 频率：每分钟
   - 功能：更新充值确认数并自动入账
   - 日志：`storage/logs/tron-confirms.log`
   - 锁超时：3 分钟

### 已注释的定时任务（可启用）

以下任务在 `routes/console.php` 中已被注释，如需启用可以取消注释：

- `rewards:grant-newbie-next-day`：每天 00:10 执行
- `rewards:dispatch-dividends`：每周一 00:00 执行
- `follow:generate-windows`：每天 00:05 执行
- `market:generate-ticks`：每分钟执行一次

## 🛠️ 常见问题

### 1. 任务没有执行

**检查步骤**：

```bash
# 1. 确认 crontab 已正确配置
crontab -l

# 2. 检查 PHP 路径是否正确
which php

# 3. 检查项目路径是否正确
ls -la /path/to/followplus

# 4. 手动测试调度器
cd /path/to/followplus
php artisan schedule:run

# 5. 检查 Laravel 日志
tail -f storage/logs/laravel.log
```

### 2. 权限问题

确保 crontab 用户有权限访问项目目录和执行 PHP：

```bash
# 检查目录权限
ls -la /path/to/followplus

# 检查 PHP 可执行权限
ls -la /usr/bin/php
```

### 3. 环境变量问题

如果任务执行时找不到环境变量，可以在 crontab 中设置：

```bash
* * * * * cd /path/to/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

或者使用完整的环境变量：

```bash
PATH=/usr/local/bin:/usr/bin:/bin
* * * * * cd /path/to/followplus && php artisan schedule:run >> /dev/null 2>&1
```

### 4. 时区问题

确保服务器时区设置正确：

```bash
# 查看当前时区
date

# 设置时区（如果需要）
sudo timedatectl set-timezone Asia/Shanghai
```

Laravel 的时区配置在 `.env` 文件中：

```env
APP_TIMEZONE=UTC
```

## 📊 监控定时任务

### 查看任务执行状态

```bash
# 查看所有已配置的任务
php artisan schedule:list

# 查看任务详情（包括下次执行时间）
php artisan schedule:list -v
```

### 查看任务日志

```bash
# 调度器日志
tail -f storage/logs/scheduler.log

# Tron 扫描日志
tail -f storage/logs/tron-scan.log

# Tron 确认日志
tail -f storage/logs/tron-confirms.log

# Laravel 主日志
tail -f storage/logs/laravel.log
```

## 🔐 安全建议

1. **使用专用用户**：建议使用非 root 用户运行 crontab
2. **限制日志大小**：定期清理日志文件，避免磁盘空间不足
3. **监控任务执行**：设置监控告警，及时发现任务失败
4. **备份配置**：定期备份 crontab 配置

## 📝 完整示例

### 生产环境推荐配置

```bash
# Laravel Scheduler - 每分钟执行一次
* * * * * cd /var/www/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 开发环境配置（带日志）

```bash
# Laravel Scheduler - 每分钟执行一次，输出日志
* * * * * cd /Users/joezhu/Sites/followplus && /usr/local/bin/php artisan schedule:run >> /tmp/laravel-scheduler.log 2>&1
```

## 🚀 快速设置脚本

可以创建一个脚本来快速设置：

```bash
#!/bin/bash
# setup-cron.sh

PROJECT_PATH="/Users/joezhu/Sites/followplus"
PHP_PATH=$(which php)

# 添加 crontab 任务
(crontab -l 2>/dev/null; echo "* * * * * cd $PROJECT_PATH && $PHP_PATH artisan schedule:run >> /dev/null 2>&1") | crontab -

echo "Crontab 配置完成！"
echo "项目路径: $PROJECT_PATH"
echo "PHP 路径: $PHP_PATH"
echo ""
echo "查看配置: crontab -l"
echo "测试调度器: cd $PROJECT_PATH && php artisan schedule:run"
```

使用方法：

```bash
chmod +x setup-cron.sh
./setup-cron.sh
```

---

**注意**：Laravel Scheduler 只需要在 crontab 中添加**一条**命令即可。所有具体的任务配置都在 `routes/console.php` 中管理，不需要在 crontab 中单独配置每个任务。

