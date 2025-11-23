# 故障排查指南

## 🔍 ERR_CONNECTION_CLOSED 错误排查

当访问 `https://api.sgex.info/` 出现 `ERR_CONNECTION_CLOSED` 错误时，按以下步骤排查：

---

## 0. 宝塔面板（LNMP）快速参考

### 0.1 宝塔面板常用路径

```bash
# 项目路径（通常在）
/www/wwwroot/api.sgex.info/
# 或
/www/wwwroot/followplus/

# Nginx 日志路径
/www/wwwlogs/api.sgex.info.log          # 访问日志
/www/wwwlogs/api.sgex.info.error.log    # 错误日志
/www/server/nginx/logs/error.log        # Nginx 全局错误日志

# Nginx 配置文件路径
/www/server/nginx/conf/vhost/api.sgex.info.conf

# PHP-FPM 配置路径
/www/server/php/8.2/etc/php-fpm.conf    # PHP 8.2
/www/server/php/8.1/etc/php-fpm.conf    # PHP 8.1

# PHP 错误日志
/www/server/php/8.2/var/log/php-fpm.log

# MySQL 日志
/www/server/mysql/data/mysql-error.log
```

### 0.2 宝塔面板常用命令

```bash
# 重启 Nginx
/etc/init.d/nginx restart
# 或
systemctl restart nginx

# 重启 PHP-FPM
/etc/init.d/php-fpm-82 restart  # PHP 8.2
/etc/init.d/php-fpm-81 restart  # PHP 8.1

# 测试 Nginx 配置
nginx -t

# 查看 Nginx 状态
/etc/init.d/nginx status

# 查看 PHP-FPM 状态
/etc/init.d/php-fpm-82 status
```

### 0.3 宝塔面板查看日志

```bash
# 查看 Nginx 错误日志（宝塔）
tail -50 /www/wwwlogs/api.sgex.info.error.log

# 查看 Nginx 访问日志（宝塔）
tail -50 /www/wwwlogs/api.sgex.info.log

# 查看 PHP-FPM 错误日志（宝塔）
tail -50 /www/server/php/8.2/var/log/php-fpm.log

# 查看 Laravel 日志
tail -50 /www/wwwroot/api.sgex.info/storage/logs/laravel.log
```

---

## 1. 基础连接检查

### 1.1 检查服务器是否在线

```bash
# 在本地终端执行
ping api.sgex.info

# 或使用 curl 测试
curl -I https://api.sgex.info/
```

**预期结果：** 应该能收到响应

**如果失败：** 检查 DNS 解析和服务器是否在线

---

### 1.2 检查端口是否开放

```bash
# 在服务器上检查端口监听
sudo netstat -tlnp | grep :443
# 或
sudo ss -tlnp | grep :443

# 检查防火墙状态
sudo ufw status
# 或
sudo iptables -L -n
```

**预期结果：** 443 端口应该被 nginx、apache2 或 httpd 监听

**如果失败：** 配置防火墙开放 443 端口

```bash
sudo ufw allow 443/tcp
sudo ufw reload
```

---

## 2. Nginx 服务检查（LNMP/宝塔）

### 2.1 检查 Nginx 是否运行

```bash
# 标准方式
sudo systemctl status nginx

# 宝塔面板方式
/etc/init.d/nginx status
```

**如果未运行：**
```bash
# 标准方式
sudo systemctl start nginx
sudo systemctl enable nginx

# 宝塔面板方式
/etc/init.d/nginx start
```

---

### 2.2 检查 Nginx 配置语法

```bash
# 标准方式
sudo nginx -t

# 宝塔面板方式
nginx -t
```

**如果配置有错误：** 修复配置文件后重新测试

---

### 2.3 检查 Nginx 错误日志

```bash
# 标准 Nginx 路径
sudo tail -f /var/log/nginx/error.log

# 宝塔面板路径
tail -f /www/wwwlogs/api.sgex.info.error.log
tail -f /www/server/nginx/logs/error.log

# 查看访问日志
tail -f /www/wwwlogs/api.sgex.info.log
```

**常见错误：**
- `SSL certificate not found` - SSL 证书路径错误
- `upstream timed out` - PHP-FPM 连接超时
- `permission denied` - 文件权限问题
- `connect() failed` - PHP-FPM socket 路径错误

---

### 2.4 检查 Nginx 虚拟主机配置

```bash
# 标准 Nginx 配置路径
sudo cat /etc/nginx/sites-available/api.sgex.info
sudo cat /etc/nginx/conf.d/api.sgex.info.conf

# 宝塔面板配置路径
cat /www/server/nginx/conf/vhost/api.sgex.info.conf
```

**检查要点：**
- `server_name` 是否正确设置为 `api.sgex.info`
- `root` 路径是否正确指向项目 `public` 目录
- SSL 证书路径是否正确
- PHP-FPM socket 路径是否正确（宝塔通常是 `/tmp/php-cgi-82.sock` 或 `/dev/shm/php-cgi-82.sock`）

**宝塔面板 Nginx 配置示例：**

```nginx
server {
    listen 443 ssl http2;
    server_name api.sgex.info;
    root /www/wwwroot/api.sgex.info/public;
    index index.php index.html;

    ssl_certificate /www/server/panel/vhost/cert/api.sgex.info/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/api.sgex.info/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-82.sock;  # 根据 PHP 版本调整
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

### 2.5 检查 PHP-FPM（宝塔面板）

```bash
# 检查 PHP-FPM 状态
/etc/init.d/php-fpm-82 status  # PHP 8.2
/etc/init.d/php-fpm-81 status  # PHP 8.1

# 查看 PHP-FPM 错误日志
tail -f /www/server/php/8.2/var/log/php-fpm.log

# 检查 PHP-FPM socket
ls -la /tmp/php-cgi-82.sock
ls -la /dev/shm/php-cgi-82.sock
```

---

## 2A. Apache 服务检查（LAMP）

### 2.1 检查 Apache 是否运行

```bash
# Ubuntu/Debian
sudo systemctl status apache2

# CentOS/RHEL
sudo systemctl status httpd
```

**如果未运行：**
```bash
# Ubuntu/Debian
sudo systemctl start apache2
sudo systemctl enable apache2

# CentOS/RHEL
sudo systemctl start httpd
sudo systemctl enable httpd
```

---

### 2.2 检查 Apache 配置语法

```bash
# Ubuntu/Debian
sudo apache2ctl configtest
# 或
sudo apache2 -t

# CentOS/RHEL
sudo httpd -t
# 或
sudo apachectl configtest
```

**如果配置有错误：** 修复配置文件后重新测试

---

### 2.3 检查 Apache 错误日志

```bash
# Ubuntu/Debian - 实时查看错误日志
sudo tail -f /var/log/apache2/error.log

# CentOS/RHEL - 实时查看错误日志
sudo tail -f /var/log/httpd/error_log

# 查看访问日志
sudo tail -f /var/log/apache2/access.log  # Ubuntu/Debian
sudo tail -f /var/log/httpd/access_log    # CentOS/RHEL
```

**常见错误：**
- `SSL certificate not found` - SSL 证书路径错误
- `AH00094: Command line: '/usr/sbin/apache2'` - 配置语法错误
- `Permission denied` - 文件权限问题
- `Could not reliably determine the server's fully qualified domain name` - 警告，不影响运行

---

### 2.4 检查 Apache 虚拟主机配置

```bash
# Ubuntu/Debian - 查看站点配置
sudo cat /etc/apache2/sites-available/api.sgex.info.conf
# 或
sudo cat /etc/apache2/sites-enabled/api.sgex.info.conf

# CentOS/RHEL - 查看站点配置
sudo cat /etc/httpd/conf.d/api.sgex.info.conf
# 或
sudo cat /etc/httpd/conf/httpd.conf
```

**检查要点：**
- `ServerName` 是否正确设置为 `api.sgex.info`
- `DocumentRoot` 路径是否正确指向 `/var/www/followplus/public`
- SSL 证书路径是否正确
- `mod_rewrite` 是否启用
- PHP 模块是否加载

**Ubuntu/Debian 示例配置：**

```apache
<VirtualHost *:443>
    ServerName api.sgex.info
    DocumentRoot /var/www/followplus/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/api.sgex.info/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/api.sgex.info/privkey.pem

    <Directory /var/www/followplus/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/api.sgex.info-error.log
    CustomLog ${APACHE_LOG_DIR}/api.sgex.info-access.log combined
</VirtualHost>
```

**CentOS/RHEL 示例配置：**

```apache
<VirtualHost *:443>
    ServerName api.sgex.info
    DocumentRoot /var/www/followplus/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/api.sgex.info/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/api.sgex.info/privkey.pem

    <Directory /var/www/followplus/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /var/log/httpd/api.sgex.info-error.log
    CustomLog /var/log/httpd/api.sgex.info-access.log combined
</VirtualHost>
```

---

### 2.5 检查 Apache 模块是否启用

```bash
# Ubuntu/Debian - 查看已启用的模块
sudo apache2ctl -M | grep -E "rewrite|ssl|php"

# CentOS/RHEL - 查看已启用的模块
sudo httpd -M | grep -E "rewrite|ssl|php"
```

**必需的模块：**
- `rewrite_module` - URL 重写
- `ssl_module` - SSL/TLS 支持
- `php_module` 或 `php8_module` - PHP 支持

**如果模块未启用：**

```bash
# Ubuntu/Debian
sudo a2enmod rewrite
sudo a2enmod ssl
sudo a2enmod php8.2  # 或 php8.1, php8.0 等
sudo systemctl restart apache2

# CentOS/RHEL - 编辑配置文件启用模块
sudo nano /etc/httpd/conf/httpd.conf
# 确保有以下行（取消注释）：
# LoadModule rewrite_module modules/mod_rewrite.so
# LoadModule ssl_module modules/mod_ssl.so
# LoadModule php_module modules/libphp.so
sudo systemctl restart httpd
```

---

## 3. PHP 检查

### 3.1 检查 PHP 版本和模块

```bash
# 检查 PHP 版本
php -v

# 检查已安装的 PHP 模块
php -m | grep -E "mysql|pdo|mbstring|openssl|curl|gmp|bcmath|fileinfo"

# 检查 PHP 配置文件位置
php --ini
```

**必需的 PHP 扩展：**
- `pdo_mysql` - 数据库连接
- `mbstring` - 字符串处理
- `openssl` - SSL 支持
- `curl` - HTTP 请求
- `gmp` - 大数运算
- `bcmath` - 数学运算
- `fileinfo` - 文件类型检测

**宝塔面板安装 PHP 扩展：**
1. 登录宝塔面板
2. 软件商店 → PHP → 设置 → 安装扩展
3. 确保安装：`gmp`、`fileinfo`、`mbstring`

---

### 3.2 检查 PHP-FPM（如果使用 FPM）

**标准 PHP-FPM：**
```bash
sudo systemctl status php8.2-fpm
sudo systemctl status php-fpm
```

**宝塔面板 PHP-FPM：**
```bash
/etc/init.d/php-fpm-82 status  # PHP 8.2
/etc/init.d/php-fpm-81 status  # PHP 8.1
```

**如果未运行：**
```bash
# 标准方式
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm

# 宝塔面板方式
/etc/init.d/php-fpm-82 start
```

---

### 3.3 检查 PHP-FPM Socket 文件

```bash
# 标准路径
ls -la /var/run/php/php8.2-fpm.sock

# 宝塔面板路径
ls -la /tmp/php-cgi-82.sock
ls -la /dev/shm/php-cgi-82.sock

# 如果不存在，检查 PHP-FPM 配置
sudo cat /etc/php/8.2/fpm/pool.d/www.conf | grep listen  # 标准
cat /www/server/php/8.2/etc/php-fpm.conf | grep listen  # 宝塔
```

---

### 3.4 检查 PHP 错误日志

```bash
# 标准路径
sudo tail -f /var/log/php8.2-fpm.log
sudo tail -f /var/log/php-fpm.log

# 宝塔面板路径
tail -f /www/server/php/8.2/var/log/php-fpm.log
```

---

### 3.5 测试 PHP 是否能正常执行

```bash
# 创建测试文件
echo "<?php phpinfo(); ?>" | sudo tee /www/wwwroot/api.sgex.info/public/test.php

# 访问测试
curl https://api.sgex.info/test.php

# 删除测试文件
sudo rm /www/wwwroot/api.sgex.info/public/test.php
```

### 3.1 检查 PHP 版本和模块

```bash
# 检查 PHP 版本
php -v

# 检查已安装的 PHP 模块
php -m | grep -E "mysql|pdo|mbstring|openssl|curl|gmp|bcmath|fileinfo"

# 检查 PHP 配置文件位置
php --ini
```

**必需的 PHP 扩展：**
- `pdo_mysql` - 数据库连接
- `mbstring` - 字符串处理
- `openssl` - SSL 支持
- `curl` - HTTP 请求
- `gmp` - 大数运算
- `bcmath` - 数学运算
- `fileinfo` - 文件类型检测

---

### 3.2 检查 PHP-FPM（如果使用 FPM）

**注意：** LAMP 通常使用 `mod_php`（Apache 模块），而不是 PHP-FPM。但如果配置了 PHP-FPM：

```bash
sudo systemctl status php8.2-fpm
# 或
sudo systemctl status php-fpm
```

**如果未运行：**
```bash
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm
```

---

### 3.3 检查 PHP 错误日志

```bash
# 查看 PHP 错误日志
sudo tail -f /var/log/php_errors.log
# 或
sudo tail -f /var/log/apache2/error.log  # Ubuntu/Debian
sudo tail -f /var/log/httpd/error_log    # CentOS/RHEL
```

---

### 3.3 检查 PHP-FPM 错误日志

```bash
sudo tail -f /var/log/php8.2-fpm.log
# 或
sudo tail -f /var/log/php-fpm.log
```

---

### 3.4 测试 PHP 是否能正常执行

```bash
# 创建测试文件
echo "<?php phpinfo(); ?>" | sudo tee /var/www/followplus/public/test.php

# 访问测试
curl https://api.sgex.info/test.php

# 删除测试文件
sudo rm /var/www/followplus/public/test.php
```

---

## 4. SSL 证书检查

### 4.1 检查证书是否存在

```bash
sudo ls -la /etc/letsencrypt/live/api.sgex.info/
```

**应该看到：**
- `fullchain.pem`
- `privkey.pem`

---

### 4.2 检查证书是否过期

```bash
sudo openssl x509 -in /etc/letsencrypt/live/api.sgex.info/fullchain.pem -noout -dates
```

**如果过期：** 更新证书

```bash
sudo certbot renew

# 重新加载 Apache
sudo systemctl reload apache2  # Ubuntu/Debian
sudo systemctl reload httpd     # CentOS/RHEL
```

---

### 4.3 测试 SSL 连接

```bash
openssl s_client -connect api.sgex.info:443 -servername api.sgex.info
```

**预期结果：** 应该能看到证书信息

---

## 5. Laravel 应用检查

### 5.1 检查应用日志

```bash
tail -f /var/www/followplus/storage/logs/laravel.log
```

**常见错误：**
- 数据库连接失败
- `.env` 配置错误
- 文件权限问题

---

### 5.2 检查文件权限

```bash
cd /var/www/followplus

# 检查目录权限
ls -la storage/
ls -la bootstrap/cache/

# 修复权限
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

### 5.3 检查 .env 配置

```bash
cd /var/www/followplus

# 检查关键配置
grep -E "APP_ENV|APP_DEBUG|APP_URL" .env

# 确保 APP_ENV=production
# 确保 APP_DEBUG=false
# 确保 APP_URL=https://api.sgex.info
```

---

### 5.4 测试 Laravel 路由

```bash
cd /var/www/followplus

# 清除缓存
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# 重新缓存
php artisan config:cache
php artisan route:cache
```

---

### 5.5 直接测试 PHP 文件

```bash
# 在服务器上直接执行
cd /var/www/followplus/public
php -r "echo 'PHP works';"
```

---

## 6. 系统资源检查

### 6.1 检查磁盘空间

```bash
df -h
```

**如果磁盘满了：** 清理日志和临时文件

```bash
# 清理 Laravel 日志（保留最近的文件）
sudo find /var/www/followplus/storage/logs -name "*.log" -mtime +7 -delete
```

---

### 6.2 检查内存使用

```bash
free -h
```

**如果内存不足：** 可能需要增加服务器资源或优化应用

---

### 6.3 检查 PHP 进程

```bash
ps aux | grep php-fpm
```

**如果进程过多或异常：** 检查 PHP-FPM 配置

---

## 7. 网络层面检查

### 7.1 检查服务器防火墙

```bash
# Ubuntu/Debian
sudo ufw status verbose

# CentOS/RHEL
sudo firewall-cmd --list-all
```

**确保开放：**
- 80/tcp (HTTP)
- 443/tcp (HTTPS)

---

### 7.2 检查云服务器安全组

如果使用云服务器（阿里云、腾讯云、AWS 等），检查安全组规则：

**需要开放：**
- 入站规则：80/tcp, 443/tcp
- 出站规则：允许所有（或至少允许 443/tcp）

---

### 7.3 检查 DNS 解析

```bash
# 检查域名解析
nslookup api.sgex.info
dig api.sgex.info

# 应该返回正确的 IP 地址
```

---

## 8. 快速诊断脚本

创建一个诊断脚本，一键检查所有关键项：

```bash
#!/bin/bash
echo "=== FollowPlus 部署诊断 ==="
echo ""

echo "1. 检查 Web 服务器状态..."
if systemctl is-active --quiet nginx 2>/dev/null; then
    sudo systemctl status nginx --no-pager -l | head -5
elif [ -f /etc/init.d/nginx ]; then
    /etc/init.d/nginx status | head -5
elif systemctl is-active --quiet apache2 2>/dev/null; then
    sudo systemctl status apache2 --no-pager -l | head -5
elif systemctl is-active --quiet httpd 2>/dev/null; then
    sudo systemctl status httpd --no-pager -l | head -5
else
    echo "❌ Web 服务器未运行"
fi
echo ""

echo "2. 检查 PHP 版本..."
php -v 2>/dev/null || echo "❌ PHP 未安装"
echo ""

echo "3. 检查端口监听..."
sudo netstat -tlnp | grep -E ":80|:443" || sudo ss -tlnp | grep -E ":80|:443"
echo ""

echo "4. 检查 SSL 证书..."
if [ -f /etc/letsencrypt/live/api.sgex.info/fullchain.pem ]; then
    echo "证书存在"
    sudo openssl x509 -in /etc/letsencrypt/live/api.sgex.info/fullchain.pem -noout -dates
else
    echo "❌ 证书不存在"
fi
echo ""

echo "5. 检查文件权限..."
ls -ld /var/www/followplus/storage /var/www/followplus/bootstrap/cache 2>/dev/null || echo "路径不存在"
echo ""

echo "6. 检查 Laravel 日志（最后 10 行）..."
tail -10 /var/www/followplus/storage/logs/laravel.log 2>/dev/null || echo "日志文件不存在"
echo ""

echo "7. 检查 Web 服务器错误日志（最后 10 行）..."
if [ -f /var/log/nginx/error.log ]; then
    sudo tail -10 /var/log/nginx/error.log
elif [ -f /www/wwwlogs/api.sgex.info.error.log ]; then
    tail -10 /www/wwwlogs/api.sgex.info.error.log
elif [ -f /var/log/apache2/error.log ]; then
    sudo tail -10 /var/log/apache2/error.log
elif [ -f /var/log/httpd/error_log ]; then
    sudo tail -10 /var/log/httpd/error_log
else
    echo "日志文件不存在"
fi
echo ""

echo "8. 检查 Web 服务器模块..."
if command -v nginx >/dev/null 2>&1; then
    echo "Nginx 模块检查（需要查看配置文件）"
elif command -v apache2ctl >/dev/null 2>&1; then
    sudo apache2ctl -M 2>/dev/null | grep -E "rewrite|ssl|php" || echo "关键模块未启用"
elif command -v httpd >/dev/null 2>&1; then
    sudo httpd -M 2>/dev/null | grep -E "rewrite|ssl|php" || echo "关键模块未启用"
fi
echo ""

echo "9. 测试本地连接..."
curl -I http://localhost 2>&1 | head -5
echo ""

echo "=== 诊断完成 ==="
```

保存为 `diagnose.sh`，然后执行：

```bash
chmod +x diagnose.sh
./diagnose.sh
```

---

## 9. 常见问题解决方案

### 问题 1: SSL 证书配置错误

**症状：** Apache 错误日志显示 `SSL certificate not found` 或 `SSLEngine on`

**解决：**
```bash
# 检查证书路径
sudo ls -la /etc/letsencrypt/live/api.sgex.info/

# 如果不存在，重新申请证书
sudo certbot --apache -d api.sgex.info

# 或手动配置后重新加载
sudo systemctl reload apache2  # Ubuntu/Debian
sudo systemctl reload httpd     # CentOS/RHEL
```

---

### 问题 2: mod_rewrite 未启用

**症状：** 404 错误，URL 重写不工作

**解决：**
```bash
# Ubuntu/Debian
sudo a2enmod rewrite
sudo systemctl restart apache2

# CentOS/RHEL
# 编辑 /etc/httpd/conf/httpd.conf，取消注释：
# LoadModule rewrite_module modules/mod_rewrite.so
sudo systemctl restart httpd
```

---

### 问题 3: PHP 模块未加载

**症状：** PHP 文件直接下载而不是执行

**解决：**
```bash
# Ubuntu/Debian
sudo a2enmod php8.2  # 根据实际 PHP 版本调整
sudo systemctl restart apache2

# CentOS/RHEL
# 确保安装了 php 和 php-mysql
sudo yum install php php-mysql
# 或
sudo dnf install php php-mysql
sudo systemctl restart httpd
```

---

### 问题 4: 文件权限问题

**症状：** 403 Forbidden 或 500 Internal Server Error

**解决：**
```bash
cd /var/www/followplus
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 storage bootstrap/cache
```

---

### 问题 5: Laravel 配置缓存问题

**症状：** 配置更改不生效

**解决：**
```bash
cd /var/www/followplus
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 重新缓存
php artisan config:cache
php artisan route:cache
```

---

### 问题 6: 数据库连接失败

**症状：** Laravel 日志显示数据库连接错误

**解决：**
```bash
# 检查 MySQL 服务
sudo systemctl status mysql

# 测试数据库连接
cd /var/www/followplus
php artisan tinker
# 在 tinker 中执行：DB::connection()->getPdo();

# 检查 .env 配置
grep -E "DB_" .env
```

---

## 10. 逐步排查流程

按以下顺序逐步排查：

1. ✅ **基础连接** - ping、DNS、端口
2. ✅ **Apache 服务** - 状态、配置、日志、模块
3. ✅ **PHP** - 版本、模块、日志
4. ✅ **SSL 证书** - 存在性、有效性
5. ✅ **Laravel 应用** - 日志、权限、配置
6. ✅ **系统资源** - 磁盘、内存
7. ✅ **网络层面** - 防火墙、安全组

---

## 11. 紧急恢复步骤

如果网站完全无法访问，按以下步骤快速恢复：

```bash
# 1. 重启所有服务
# Nginx (标准)
sudo systemctl restart nginx

# Nginx (宝塔面板)
/etc/init.d/nginx restart

# Apache (标准)
sudo systemctl restart apache2  # Ubuntu/Debian
sudo systemctl restart httpd    # CentOS/RHEL

# PHP-FPM
sudo systemctl restart php8.2-fpm 2>/dev/null || true
/etc/init.d/php-fpm-82 restart 2>/dev/null || true  # 宝塔面板

# MySQL
sudo systemctl restart mysql

# 2. 检查服务状态
sudo systemctl status nginx 2>/dev/null || /etc/init.d/nginx status
sudo systemctl status apache2 2>/dev/null || sudo systemctl status httpd 2>/dev/null
sudo systemctl status mysql

# 3. 检查端口
sudo netstat -tlnp | grep -E ":80|:443"

# 4. 查看最新错误
# Nginx (标准)
if [ -f /var/log/nginx/error.log ]; then
    sudo tail -50 /var/log/nginx/error.log
fi

# Nginx (宝塔面板)
if [ -f /www/wwwlogs/api.sgex.info.error.log ]; then
    tail -50 /www/wwwlogs/api.sgex.info.error.log
fi

# Apache
if [ -f /var/log/apache2/error.log ]; then
    sudo tail -50 /var/log/apache2/error.log
elif [ -f /var/log/httpd/error_log ]; then
    sudo tail -50 /var/log/httpd/error_log
fi

# Laravel 日志（根据实际项目路径调整）
tail -50 /www/wwwroot/api.sgex.info/storage/logs/laravel.log 2>/dev/null || \
tail -50 /var/www/followplus/storage/logs/laravel.log 2>/dev/null || \
tail -50 storage/logs/laravel.log

# 5. 测试本地访问
curl -I http://localhost

# 6. 测试配置
nginx -t 2>/dev/null || echo "Nginx 未安装"
sudo apache2ctl configtest 2>/dev/null || sudo httpd -t 2>/dev/null || echo "Apache 未安装"
```

---

## 12. 获取帮助

如果以上步骤都无法解决问题，收集以下信息：

```bash
# 收集诊断信息
{
    echo "=== Apache 状态 ==="
    if systemctl is-active --quiet apache2 2>/dev/null; then
        sudo systemctl status apache2 --no-pager
        APACHE_CMD="apache2ctl"
        APACHE_CONF="/etc/apache2/sites-available/api.sgex.info.conf"
        ERROR_LOG="/var/log/apache2/error.log"
    elif systemctl is-active --quiet httpd 2>/dev/null; then
        sudo systemctl status httpd --no-pager
        APACHE_CMD="httpd"
        APACHE_CONF="/etc/httpd/conf.d/api.sgex.info.conf"
        ERROR_LOG="/var/log/httpd/error_log"
    else
        echo "Apache 未运行"
    fi
    
    echo -e "\n=== PHP 版本 ==="
    php -v
    
    echo -e "\n=== PHP 模块 ==="
    php -m
    
    echo -e "\n=== Apache 配置 ==="
    if [ -f "$APACHE_CONF" ]; then
        sudo cat "$APACHE_CONF"
    else
        echo "配置文件不存在: $APACHE_CONF"
    fi
    
    echo -e "\n=== Apache 配置测试 ==="
    sudo $APACHE_CMD configtest 2>/dev/null || sudo $APACHE_CMD -t 2>/dev/null || echo "无法测试配置"
    
    echo -e "\n=== 最近错误日志 ==="
    if [ -f "$ERROR_LOG" ]; then
        sudo tail -50 "$ERROR_LOG"
    else
        echo "错误日志不存在: $ERROR_LOG"
    fi
    
    echo -e "\n=== Laravel 日志 ==="
    tail -50 /var/www/followplus/storage/logs/laravel.log
    
    echo -e "\n=== 端口监听 ==="
    sudo netstat -tlnp | grep -E ":80|:443" || sudo ss -tlnp | grep -E ":80|:443"
    
    echo -e "\n=== Apache 模块 ==="
    sudo $APACHE_CMD -M 2>/dev/null || sudo apache2ctl -M 2>/dev/null || echo "无法列出模块"
} > /tmp/diagnosis.txt

# 查看诊断信息
cat /tmp/diagnosis.txt
```

---

---

## 13. LAMP 特定问题排查

### 13.1 宝塔面板用户注意事项

如果使用宝塔面板：

1. **删除禁用函数：** `putenv`, `proc_open`
   - 宝塔面板 → 软件商店 → PHP → 设置 → 禁用函数 → 删除这两个函数

2. **安装 PHP 扩展：**
   - `gmp` - 大数运算
   - `fileinfo` - 文件类型检测
   - `mbstring` - 字符串处理
   - 宝塔面板 → 软件商店 → PHP → 设置 → 安装扩展

3. **检查 PHP 版本：** 确保使用 PHP 8.2+

4. **检查网站配置：**
   - 网站 → 设置 → 网站目录 → 运行目录选择 `public`
   - 网站 → 设置 → 伪静态 → 选择 Laravel 规则

---

### 13.2 Apache .htaccess 问题

**症状：** 403 Forbidden 或 500 Internal Server Error

**解决：**
```bash
# 确保 .htaccess 文件存在
ls -la /var/www/followplus/public/.htaccess

# 确保 Apache 配置允许 .htaccess
# 在虚拟主机配置中应该有：
# <Directory /var/www/followplus/public>
#     AllowOverride All
#     Require all granted
# </Directory>

# 重启 Apache
sudo systemctl restart apache2  # Ubuntu/Debian
sudo systemctl restart httpd     # CentOS/RHEL
```

---

### 13.3 SELinux 问题（CentOS/RHEL）

**症状：** 权限错误，即使文件权限正确

**解决：**
```bash
# 检查 SELinux 状态
getenforce

# 如果是 Enforcing，临时设置为 Permissive 测试
sudo setenforce 0

# 如果问题解决，永久设置（不推荐，建议配置 SELinux 规则）
sudo nano /etc/selinux/config
# 设置 SELINUX=permissive

# 或配置正确的 SELinux 上下文
sudo chcon -R -t httpd_sys_content_t /var/www/followplus/
sudo chcon -R -t httpd_sys_rw_content_t /var/www/followplus/storage
sudo chcon -R -t httpd_sys_rw_content_t /var/www/followplus/bootstrap/cache
```

---

**提示：** 大多数 `ERR_CONNECTION_CLOSED` 错误是由 Apache 配置错误、PHP 模块未加载、SSL 证书问题或文件权限问题引起的。优先检查这四个方面。

---

## 14. CORS 跨域错误排查

### 14.1 检查 CORS 中间件是否生效

**症状：** 前端从 `app.sgex.info` 调用 `api.sgex.info` 时出现 CORS 错误

**排查步骤：**

```bash
# 1. 检查中间件文件是否存在
ls -la /var/www/followplus/app/Http/Middleware/CorsMiddleware.php

# 2. 检查 bootstrap/app.php 中是否注册了中间件
grep -A 5 "CorsMiddleware" /var/www/followplus/bootstrap/app.php

# 3. 清除并重新缓存配置
cd /var/www/followplus
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# 4. 测试 OPTIONS 预检请求
curl -X OPTIONS https://api.sgex.info/api/v1/auth/login \
  -H "Origin: https://app.sgex.info" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type" \
  -v

# 应该看到 Access-Control-Allow-Origin 头
```

---

### 14.2 Nginx 配置检查

**确保 Nginx 不会拦截 OPTIONS 请求：**

```bash
# 标准 Nginx 配置路径
sudo cat /etc/nginx/sites-available/api.sgex.info
sudo cat /etc/nginx/conf.d/api.sgex.info.conf

# 宝塔面板 Nginx 配置路径
cat /www/server/nginx/conf/vhost/api.sgex.info.conf

# 确保配置中有正确的 location 块
# 参考 nginx-cors-config.conf 文件
```

**宝塔面板配置要点：**
1. 登录宝塔面板 → 网站 → 找到 `api.sgex.info` → 设置
2. 确保"运行目录"设置为 `public`
3. 确保"伪静态"规则正确（Laravel 规则）
4. 不要添加额外的 CORS 头（让 Laravel 中间件处理）
5. 确保 PHP 版本正确（PHP 8.2+）

**Nginx 配置要点：**
- 不要使用 `if` 语句拦截 OPTIONS 请求（让 Laravel 处理）
- 确保 `fastcgi_pass` 正确配置
- 确保 PHP-FPM 正常运行

---

### 14.3 检查允许的域名配置

```bash
# 检查 CORS 中间件中配置的允许域名
grep -A 10 "allowedOrigins" /var/www/followplus/app/Http/Middleware/CorsMiddleware.php
```

**如果需要添加新域名：**
1. 编辑 `app/Http/Middleware/CorsMiddleware.php`
2. 在 `$allowedOrigins` 数组中添加新域名
3. 清除缓存：`php artisan config:clear && php artisan config:cache`

---

### 14.4 浏览器端调试

在浏览器控制台检查：

1. **Network 标签页：**
   - 查看 OPTIONS 预检请求是否返回 200
   - 检查响应头中是否有 `Access-Control-Allow-Origin`
   - 检查实际请求的响应头

2. **Console 错误信息：**
   - `Access to fetch at '...' from origin '...' has been blocked by CORS policy`
   - 记录具体的错误信息

---

### 14.5 常见 CORS 问题

**问题 1: OPTIONS 请求返回 404**

**原因：** Nginx 或路由配置拦截了 OPTIONS 请求

**解决：**
```bash
# 确保 Laravel 路由能处理 OPTIONS 请求
# 检查 routes/api.php 是否有正确的路由配置
# CORS 中间件应该自动处理 OPTIONS 请求
```

**问题 2: Access-Control-Allow-Origin 头缺失**

**原因：** 中间件未正确执行或缓存问题

**解决：**
```bash
cd /var/www/followplus
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# 重启 PHP-FPM
sudo systemctl restart php8.2-fpm  # 或 php-fpm
```

**问题 3: 凭证（Credentials）问题**

**症状：** 使用 `withCredentials: true` 时 CORS 失败

**解决：** 确保：
- `Access-Control-Allow-Credentials: true` 已设置
- `Access-Control-Allow-Origin` 不能使用通配符 `*`，必须是具体域名
- 前端请求包含 `credentials: 'include'` 或 `withCredentials: true`

---

### 14.6 测试 CORS 配置

```bash
# 测试脚本
#!/bin/bash
echo "测试 CORS 配置..."
echo ""

echo "1. 测试 OPTIONS 预检请求："
curl -X OPTIONS https://api.sgex.info/api/v1/auth/login \
  -H "Origin: https://app.sgex.info" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization" \
  -i

echo ""
echo "2. 测试实际请求："
curl -X POST https://api.sgex.info/api/v1/auth/login \
  -H "Origin: https://app.sgex.info" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"test"}' \
  -i
```

**预期结果：**
- OPTIONS 请求返回 200，包含所有 CORS 头
- POST 请求返回响应，包含 `Access-Control-Allow-Origin: https://app.sgex.info`

---

**提示：** CORS 问题通常由中间件未正确注册、配置缓存未清除或 Nginx 配置拦截请求引起。优先检查这三个方面。

