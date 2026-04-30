#!/bin/sh
set -e

# 确保data目录存在且有写入权限
mkdir -p /app/data
chmod 755 /app/data

# 检查gallery.json和lottery.json
if [ ! -f /app/data/gallery.json ]; then
    echo '[]' > /app/data/gallery.json
fi

if [ ! -f /app/data/lottery.json ]; then
    echo '{"current":null,"history":[],"countdown":null,"exportedAt":0}' > /app/data/lottery.json
fi

chmod 644 /app/data/*.json

# 启动PHP-FPM
php-fpm -y /app/php-fpm.conf &
PHP_FPM_PID=$!

# 启动Nginx
nginx -c /app/nginx.conf &
NGINX_PID=$!

# 等待进程
wait $PHP_FPM_PID $NGINX_PID
