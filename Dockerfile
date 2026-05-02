FROM php:8.2-fpm-alpine

# 安装Nginx、图片处理扩展和必要工具
RUN apk add --no-cache nginx curl apache2-utils freetype libjpeg-turbo libpng libwebp libavif && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev libavif-dev && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif && \
    docker-php-ext-install gd && \
    apk del .build-deps

# 复制应用文件
COPY . /app

WORKDIR /app

# 创建data目录
RUN mkdir -p /app/data /app/data/gallery-images /app/data/gallery-images/original /app/data/gallery-images/thumbs /app/images/chat && chown -R www-data:www-data /app/data /app/images/chat && chmod 775 /app/data /app/data/gallery-images /app/data/gallery-images/original /app/data/gallery-images/thumbs /app/images/chat

# 初始化JSON文件
RUN if [ ! -f /app/data/gallery.json ]; then echo '[]' > /app/data/gallery.json; fi && \
    if [ ! -f /app/data/lottery.json ]; then echo '{"current":null,"history":[],"countdown":null,"exportedAt":0}' > /app/data/lottery.json; fi && \
    if [ ! -f /app/data/chat.json ]; then echo '[]' > /app/data/chat.json; fi && \
    if [ ! -f /app/data/chat-users.json ]; then echo '[]' > /app/data/chat-users.json; fi && \
    if [ ! -f /app/data/chat-bans.json ]; then echo '[]' > /app/data/chat-bans.json; fi && \
    if [ ! -f /app/data/chat-settings.json ]; then echo '{"pins":[{"name":"系统","avatar":"📢","text":"欢迎来到聊天室模板。"}],"updatedAt":0}' > /app/data/chat-settings.json; fi && \
    chown -R www-data:www-data /app/data /app/images/chat && \
    chmod 664 /app/data/*.json

# 修复配置文件路径
RUN sed -i 's|listen = 127.0.0.1:9000|listen = 9000|g' /app/php-fpm.conf

# 创建运行日志目录
RUN mkdir -p /var/log/nginx && \
    mkdir -p /var/run/nginx

# 暴露端口
EXPOSE 3000

# 启动脚本
ENTRYPOINT ["/bin/sh", "/app/start.sh"]
