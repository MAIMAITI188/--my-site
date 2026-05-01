FROM php:8.2-fpm-alpine

# 安装Nginx、图片处理扩展和必要工具
RUN apk add --no-cache nginx curl apache2-utils freetype libjpeg-turbo libpng libwebp && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install gd && \
    apk del .build-deps

# 复制应用文件
COPY . /app

WORKDIR /app

# 创建data目录
RUN mkdir -p /app/data /app/data/gallery-images /app/data/gallery-images/original /app/data/gallery-images/thumbs && chown -R www-data:www-data /app/data && chmod 775 /app/data /app/data/gallery-images /app/data/gallery-images/original /app/data/gallery-images/thumbs

# 初始化JSON文件
RUN echo '[]' > /app/data/gallery.json && \
    echo '{"current":null,"history":[],"countdown":null,"exportedAt":0}' > /app/data/lottery.json && \
    chown -R www-data:www-data /app/data && \
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
