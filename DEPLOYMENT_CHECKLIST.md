# FollowPlus 部署流程清单

## 📋 部署步骤

### 1. 服务器环境准备

```bash
# 安装必需软件
sudo apt update
sudo apt install -y php8.2 php8.2-cli php8.2-fpm php8.2-mysql php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-bcmath php8.2-gmp php8.2-zip \
    mysql-server nginx git curl unzip

# 安装 Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2. 部署代码

```bash
# 克隆代码或上传到服务器
cd /var/www
git clone your-repo-url followplus
# 或上传代码包并解压

# 设置目录所有者
sudo chown -R www-data:www-data /var/www/followplus
```

### 3. 安装依赖

```bash
cd /var/www/followplus
composer install --no-dev --optimize-autoloader
```

### 4. 配置 .env 文件

```bash
# 复制环境配置文件
cp .env.example .env

# 编辑配置文件
nano .env
```

**必需配置项：**

```env
APP_NAME="FollowPlus"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=followplus
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

JWT_SECRET=                    # 下一步生成
JWT_TTL=60
JWT_REFRESH_TTL=20160

TRON_NODE_URL=https://api.trongrid.io
TRON_API_KEY=your_tron_api_key
TRON_USDT_CONTRACT=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t
TRON_PK_ENC_KEY=your_32_byte_encryption_key
TRON_HOT_WALLET_ADDRESS=your_hot_wallet_address
TRON_HOT_WALLET_PRIVATE_KEY=your_hot_wallet_private_key
TRON_GAS_BANK_PRIVATE_KEY=your_gas_bank_private_key
TRON_REQUIRED_CONFIRMATIONS=20
TRON_MIN_SWEEP_AMOUNT=50.0
TRON_MIN_TRX_BALANCE=1.0

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@your-domain.com"
```

### 5. 生成密钥

```bash
# 生成应用密钥
php artisan key:generate

# 生成 JWT 密钥
php artisan jwt:secret
```

### 6. 准备数据库

```bash
# 登录 MySQL
sudo mysql -u root -p

# 创建数据库和用户
CREATE DATABASE followplus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'followplus_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON followplus.* TO 'followplus_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 7. 运行数据库迁移

```bash
php artisan migrate --force

# 运行数据填充（可选）
php artisan db:seed --class=CurrencySeeder
php artisan db:seed --class=SymbolSeeder
php artisan db:seed --class=AdminSeeder
```

### 8. 初始化 HD 钱包（重要！）

```bash
php artisan tron:init-hd-wallet

# 注意：请妥善保管生成的主密钥！
```

### 9. 设置文件权限

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 755 public
sudo chmod 600 .env
```

### 10. 缓存配置

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer dump-autoload --optimize
```

### 11. 配置 Crontab

```bash
# 编辑 crontab
crontab -e

# 添加以下行（替换路径为实际项目路径）
* * * * * cd /var/www/followplus && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**验证定时任务：**

```bash
# 查看已配置的任务
php artisan schedule:list

# 手动测试
php artisan schedule:run
```

### 12. 配置 Web 服务器

#### Nginx 配置示例

创建 `/etc/nginx/sites-available/followplus`:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/followplus/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

启用配置：

```bash
sudo ln -s /etc/nginx/sites-available/followplus /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

### 13. 配置 SSL 证书（Let's Encrypt）

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

### 14. 验证部署

```bash
# 检查配置
php artisan config:show

# 检查路由
php artisan route:list

# 检查定时任务
php artisan schedule:list

# 测试 API
curl https://your-domain.com/api/v1/auth/register \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password123"}'
```

### 15. 监控日志

```bash
# Laravel 日志
tail -f storage/logs/laravel.log

# 定时任务日志
tail -f storage/logs/scheduler.log
tail -f storage/logs/tron-scan.log
tail -f storage/logs/tron-confirms.log

# Nginx 日志
tail -f /var/log/nginx/followplus-access.log
tail -f /var/log/nginx/followplus-error.log
```

---

## ✅ 部署检查清单

- [ ] 服务器环境已准备（PHP 8.2+, MySQL, Nginx）
- [ ] 代码已部署到服务器
- [ ] Composer 依赖已安装
- [ ] `.env` 文件已配置
- [ ] 应用密钥已生成（`php artisan key:generate`）
- [ ] JWT 密钥已生成（`php artisan jwt:secret`）
- [ ] 数据库已创建
- [ ] 数据库迁移已运行（`php artisan migrate`）
- [ ] HD 钱包已初始化（`php artisan tron:init-hd-wallet`）
- [ ] 文件权限已设置
- [ ] 配置已缓存
- [ ] Crontab 已配置
- [ ] Web 服务器已配置
- [ ] SSL 证书已安装
- [ ] API 测试通过

---

## 🔄 更新部署流程

当需要更新代码时：

```bash
cd /var/www/followplus

# 1. 拉取最新代码
git pull origin main

# 2. 更新依赖
composer install --no-dev --optimize-autoloader

# 3. 运行迁移（如果有新的迁移）
php artisan migrate --force

# 4. 清理并重新缓存
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

# 5. 重启 PHP-FPM
sudo systemctl restart php8.2-fpm
```

---

## 🚨 常见问题

### 500 错误
- 检查 `.env` 配置
- 检查文件权限
- 查看 `storage/logs/laravel.log`

### 数据库连接失败
- 检查 MySQL 服务：`sudo systemctl status mysql`
- 检查 `.env` 中的数据库配置
- 检查数据库用户权限

### 定时任务不执行
- 检查 crontab：`crontab -l`
- 检查 PHP 路径：`which php`
- 查看日志：`storage/logs/scheduler.log`

### 权限错误
- 检查存储目录权限：`ls -la storage`
- 确保用户和组正确：`sudo chown -R www-data:www-data storage`

### CORS 跨域错误
- CORS 中间件已自动配置，允许 `app.sgex.info` 访问 `api.sgex.info`
- 如果前端域名变更，需要修改 `app/Http/Middleware/CorsMiddleware.php` 中的 `$allowedOrigins` 数组
- 确保前端请求包含正确的 `Origin` 头
- 清除配置缓存：`php artisan config:clear`

---

**完成以上步骤后，系统即可正常运行！**

### 部署流程（15步）
- 核心步骤
- 服务器环境准备 - 安装 PHP 8.2+、MySQL、Nginx、Composer
- 部署代码 - 克隆或上传代码到服务器
- 安装依赖 - composer install
- 配置 .env 文件 - 复制 .env.example 并配置数据库、JWT、Tron 等
- 生成密钥 - php artisan key:generate 和 php artisan jwt:secret
- 准备数据库 - 创建数据库和用户
- 运行数据库迁移 - php artisan migrate && php artisan db:seed
- 初始化 HD 钱包 - php artisan tron:init-hd-wallet（重要）
- 设置文件权限 - storage 和 bootstrap/cache 目录权限
- 缓存配置 - php artisan config:cache 等
- 配置 Crontab - 添加 * * * * * cd //www/wwwroot/api.sgex.info/followplus-main && /usr/local/php artisan schedule:run
- 配置 Web 服务器 - Nginx/Apache 指向 public 目录
- 配置 SSL 证书 - Let's Encrypt
- 验证部署 - 测试 API 和定时任务
- 监控日志 - 查看各日志文件
- 宝塔系统，需要删除putenv，proc_open禁用，PHP需要安装gmt和fileinfo，mbstring模块。