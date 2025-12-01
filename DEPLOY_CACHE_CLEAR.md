# 服务器部署后清除缓存指南

## 🚀 快速清除所有缓存

```bash
cd /www/wwwroot/api.sgex.info/followplus-mh-token  # 或你的项目路径

# 清除所有缓存
php artisan optimize:clear
```

这个命令会一次性清除：
- 配置缓存
- 路由缓存
- 视图缓存
- 事件缓存
- 编译文件

---

## 📋 分步清除缓存（推荐）

### 1. 清除配置缓存

```bash
php artisan config:clear
```

**什么时候需要**：
- 修改了 `.env` 文件
- 修改了 `config/` 目录下的配置文件
- 添加了新的配置项

**注意**：生产环境通常使用 `php artisan config:cache` 缓存配置以提高性能，修改配置后需要先清除再重新缓存。

---

### 2. 清除路由缓存

```bash
php artisan route:clear
```

**什么时候需要**：
- 添加或修改了路由
- 修改了路由中间件
- 路由不生效

**注意**：生产环境通常使用 `php artisan route:cache` 缓存路由以提高性能。

---

### 3. 清除视图缓存

```bash
php artisan view:clear
```

**什么时候需要**：
- 修改了 Blade 模板文件
- 视图文件不更新

---

### 4. 清除应用缓存

```bash
php artisan cache:clear
```

**什么时候需要**：
- 数据缓存不更新
- 使用了 `Cache::remember()` 等缓存方法
- 修改了缓存相关的业务逻辑

**注意**：这会清除所有缓存驱动（database、file、redis 等）的缓存。

---

### 5. 清除事件缓存

```bash
php artisan event:clear
```

**什么时候需要**：
- 添加或修改了事件监听器
- 事件不触发

---

### 6. 清除编译文件

```bash
php artisan clear-compiled
```

**什么时候需要**：
- 类加载问题
- 自动加载异常

---

## 🔄 完整的部署后清理流程

```bash
# 进入项目目录
cd /www/wwwroot/api.sgex.info/followplus-mh-token

# 1. 清除所有缓存
php artisan optimize:clear

# 2. 清除编译文件
php artisan clear-compiled

# 3. 清除应用缓存（如果使用 Redis/Memcached）
php artisan cache:clear

# 4. 重新生成自动加载文件
composer dump-autoload

# 5. 重新缓存（生产环境推荐）
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. 重启 PHP-FPM（如果需要）
sudo systemctl restart php8.2-fpm
# 或
sudo service php-fpm restart
```

---

## 🎯 常见场景

### 场景 1：修改了 .env 文件

```bash
php artisan config:clear
php artisan config:cache  # 生产环境重新缓存
```

### 场景 2：添加了新的路由

```bash
php artisan route:clear
php artisan route:cache  # 生产环境重新缓存
```

### 场景 3：修改了 Blade 视图

```bash
php artisan view:clear
php artisan view:cache  # 生产环境重新缓存
```

### 场景 4：代码更新后（Git pull）

```bash
# 1. 拉取代码
git pull origin main

# 2. 更新依赖
composer install --no-dev --optimize-autoloader

# 3. 运行迁移（如果有）
php artisan migrate --force

# 4. 清除所有缓存
php artisan optimize:clear

# 5. 重新缓存（生产环境）
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. 重启 PHP-FPM
sudo systemctl restart php8.2-fpm
```

### 场景 5：修改了系统配置（SystemConfig）

```bash
# SystemConfig 使用缓存，需要清除应用缓存
php artisan cache:clear
```

---

## ⚠️ 注意事项

### 1. 生产环境 vs 开发环境

**开发环境**：
- 通常不需要缓存配置和路由
- 清除缓存即可，不需要重新缓存

**生产环境**：
- 建议缓存配置和路由以提高性能
- 清除后需要重新缓存

### 2. 配置缓存的影响

如果使用了 `php artisan config:cache`，Laravel 会：
- 将所有配置文件合并到一个文件
- 不再读取 `.env` 文件（除了 `APP_KEY` 等基础配置）
- 性能提升，但修改 `.env` 后必须清除并重新缓存

### 3. 路由缓存的影响

如果使用了 `php artisan route:cache`，Laravel 会：
- 预编译所有路由
- 不再动态加载路由文件
- 性能提升，但修改路由后必须清除并重新缓存

### 4. 检查缓存状态

```bash
# 检查配置是否已缓存
ls -la bootstrap/cache/config.php

# 检查路由是否已缓存
ls -la bootstrap/cache/routes-v7.php  # Laravel 12
```

---

## 🔍 排查问题

### 问题：配置修改不生效

```bash
# 1. 清除配置缓存
php artisan config:clear

# 2. 检查 .env 文件
cat .env | grep YOUR_CONFIG_KEY

# 3. 检查配置文件
cat config/your_config.php

# 4. 重新缓存（生产环境）
php artisan config:cache
```

### 问题：路由 404 错误

```bash
# 1. 清除路由缓存
php artisan route:clear

# 2. 检查路由文件
cat routes/api.php

# 3. 列出所有路由
php artisan route:list

# 4. 重新缓存（生产环境）
php artisan route:cache
```

### 问题：视图不更新

```bash
# 1. 清除视图缓存
php artisan view:clear

# 2. 检查视图文件权限
ls -la resources/views/

# 3. 重新缓存（生产环境）
php artisan view:cache
```

---

## 📝 一键清理脚本

创建一个清理脚本 `clear-cache.sh`：

```bash
#!/bin/bash

echo "=== 清除 Laravel 缓存 ==="

cd /www/wwwroot/api.sgex.info/followplus-mh-token

echo "1. 清除优化缓存..."
php artisan optimize:clear

echo "2. 清除编译文件..."
php artisan clear-compiled

echo "3. 清除应用缓存..."
php artisan cache:clear

echo "4. 重新生成自动加载..."
composer dump-autoload

echo "5. 重新缓存（生产环境）..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✓ 缓存清理完成！"
```

使用：

```bash
chmod +x clear-cache.sh
./clear-cache.sh
```

---

## 🎯 最佳实践

1. **每次代码更新后**：清除所有缓存并重新缓存
2. **修改配置后**：清除配置缓存并重新缓存
3. **添加路由后**：清除路由缓存并重新缓存
4. **生产环境**：始终使用缓存以提高性能
5. **开发环境**：可以不使用缓存，方便调试

---

最后更新：2025-11-20

