FROM php:8.2-fpm-alpine

# 安装Nginx和必要工具
RUN apk add --no-cache nginx curl

# 复制应用文件
COPY . /app

WORKDIR /app

# 创建data目录
RUN mkdir -p /app/data && chmod 755 /app/data

# 初始化JSON文件
RUN echo '[]' > /app/data/gallery.json && \
    echo '{"current":null,"history":[],"countdown":null,"exportedAt":0}' > /app/data/lottery.json && \
    chmod 644 /app/data/*.json

# 修复配置文件路径
RUN sed -i 's|listen = 127.0.0.1:9000|listen = 9000|g' /app/php-fpm.conf

# 创建运行日志目录
RUN mkdir -p /var/log/nginx && \
    mkdir -p /var/run/nginx

# 暴露端口
EXPOSE 8080

# 启动脚本
ENTRYPOINT ["/bin/sh", "/app/start.sh"]
