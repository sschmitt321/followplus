#!/bin/bash

# FollowPlus 测试运行脚本
# 用法: ./scripts/test.sh [test-file]

set -e

echo "🧪 FollowPlus 测试运行器"
echo "=========================="
echo ""

# 检查是否在项目根目录
if [ ! -f "artisan" ]; then
    echo "❌ 错误: 请在项目根目录运行此脚本"
    exit 1
fi

# 检查依赖是否安装
if [ ! -d "vendor" ]; then
    echo "📦 安装依赖..."
    composer install
fi

# 运行测试
if [ -z "$1" ]; then
    echo "🚀 运行所有测试..."
    php artisan test
else
    echo "🚀 运行测试: $1"
    php artisan test "$1"
fi

echo ""
echo "✅ 测试完成！"

