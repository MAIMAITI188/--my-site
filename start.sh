#!/bin/sh
set -e

# 确保data目录存在且有写入权限
mkdir -p /app/data
chown -R www-data:www-data /app/data
chmod 775 /app/data

# Dokploy/容器环境下用环境变量生成后台 Basic Auth。
# 设置 ADMIN_AUTH_USER 和 ADMIN_AUTH_PASSWORD 后，/admin/ 和后台写入 API 都会被服务器级密码保护。
mkdir -p /app/.deploy
ADMIN_AUTH_CONF="/app/.deploy/nginx-admin-auth.conf"
ADMIN_AUTH_FILE="/app/.deploy/nginx-admin.htpasswd"
ADMIN_ROOT_CONF="/app/.deploy/nginx-admin-root.conf"
ADMIN_AUTH_USER="${ADMIN_AUTH_USER:-admin}"
ADMIN_AUTH_PASSWORD="${ADMIN_AUTH_PASSWORD:-Admin@2026!XG}"
ADMIN_DOMAIN="${ADMIN_DOMAIN:-}"
ADMIN_DOMAIN="${ADMIN_DOMAIN#http://}"
ADMIN_DOMAIN="${ADMIN_DOMAIN#https://}"
ADMIN_DOMAIN="${ADMIN_DOMAIN%%/*}"

if [ -n "$ADMIN_AUTH_USER" ] && [ -n "$ADMIN_AUTH_PASSWORD" ]; then
    htpasswd -bcB "$ADMIN_AUTH_FILE" "$ADMIN_AUTH_USER" "$ADMIN_AUTH_PASSWORD" >/dev/null
    chown nobody:nogroup "$ADMIN_AUTH_FILE" 2>/dev/null || true
    chmod 640 "$ADMIN_AUTH_FILE"
    cat > "$ADMIN_AUTH_CONF" <<EOF
auth_basic "Admin";
auth_basic_user_file $ADMIN_AUTH_FILE;
EOF
else
    cat > "$ADMIN_AUTH_CONF" <<EOF
return 503 "Admin Basic Auth is not configured. Set ADMIN_AUTH_USER and ADMIN_AUTH_PASSWORD in Dokploy.\n";
EOF
fi
chmod 644 "$ADMIN_AUTH_CONF"

if [ -n "$ADMIN_DOMAIN" ]; then
    cat > "$ADMIN_ROOT_CONF" <<EOF
if (\$host = "$ADMIN_DOMAIN") {
    return 302 /admin/HTadmin.html;
}
EOF
else
    : > "$ADMIN_ROOT_CONF"
fi
chmod 644 "$ADMIN_ROOT_CONF"

# 检查gallery.json和lottery.json
if [ ! -f /app/data/gallery.json ]; then
    echo '[]' > /app/data/gallery.json
fi

if [ ! -f /app/data/lottery.json ]; then
    echo '{"current":null,"history":[],"countdown":null,"exportedAt":0}' > /app/data/lottery.json
fi

chown www-data:www-data /app/data/*.json
chmod 664 /app/data/*.json

# 启动PHP-FPM
php-fpm -y /app/php-fpm.conf &
PHP_FPM_PID=$!

# 启动Nginx
nginx -c /app/nginx.conf &
NGINX_PID=$!

# 等待进程
wait $PHP_FPM_PID $NGINX_PID
