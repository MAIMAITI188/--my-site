#!/bin/sh
set -e

# 确保data目录存在且有写入权限
mkdir -p /app/data /app/data/gallery-images /app/data/gallery-images/original /app/data/gallery-images/thumbs /var/log/nginx /var/run/nginx /run
chown -R nobody:nogroup /app/data
chmod 775 /app/data
mkdir -p /tmp/nginx-client-body
chown -R nobody:nogroup /tmp/nginx-client-body 2>/dev/null || true
chmod 777 /tmp/nginx-client-body

# Dokploy/容器环境下用环境变量生成后台 Basic Auth。
# 设置 ADMIN_AUTH_USER 和 ADMIN_AUTH_PASSWORD 后，/admin/ 和后台写入 API 都会被服务器级密码保护。
mkdir -p /app/.deploy
ADMIN_AUTH_CONF="/app/.deploy/nginx-admin-auth.conf"
ADMIN_AUTH_FILE="/app/.deploy/nginx-admin.htpasswd"
ADMIN_ROOT_CONF="/app/.deploy/nginx-admin-root.conf"
ADMIN_AUTH_USER="${ADMIN_AUTH_USER:-admin}"
ADMIN_AUTH_PASSWORD="${ADMIN_AUTH_PASSWORD:-Admin@2026!XG}"
ADMIN_DOMAIN="${ADMIN_DOMAIN:-ht.hkxc888.com}"
ADMIN_DOMAIN="${ADMIN_DOMAIN#http://}"
ADMIN_DOMAIN="${ADMIN_DOMAIN#https://}"
ADMIN_DOMAIN="${ADMIN_DOMAIN%%/*}"
ADMIN_DOMAIN="$(printf '%s' "$ADMIN_DOMAIN" | tr '[:upper:]' '[:lower:]')"

if [ -n "$ADMIN_AUTH_USER" ] && [ -n "$ADMIN_AUTH_PASSWORD" ]; then
    php -r '$u=$argv[1]; $p=$argv[2]; echo $u . ":{SHA}" . base64_encode(sha1($p, true)) . PHP_EOL;' "$ADMIN_AUTH_USER" "$ADMIN_AUTH_PASSWORD" > "$ADMIN_AUTH_FILE"
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

if [ ! -f /app/data/chat.json ]; then
    echo '[]' > /app/data/chat.json
fi

chown nobody:nogroup /app/data/*.json
chmod 664 /app/data/*.json
chown -R nobody:nogroup /app/data/gallery-images
chmod 775 /app/data/gallery-images
chmod 775 /app/data/gallery-images/original /app/data/gallery-images/thumbs

# 启动PHP-FPM
php-fpm -y /app/php-fpm.conf &
PHP_FPM_PID=$!

# 启动Nginx
nginx -c /app/nginx.conf &
NGINX_PID=$!

# 等待进程
wait $PHP_FPM_PID $NGINX_PID
