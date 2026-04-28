# ============================================================
# POSLA — PHP 8.2 + Apache (ローカル開発専用)
# ============================================================
# 本番 Sakura は mod_php (PHP 8.x) なので、なるべく近い構成を採用する。
# ============================================================
FROM php:8.2-apache

# OS パッケージ + 必要拡張のビルド依存
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        zip \
        unzip \
        git \
        curl \
        default-mysql-client \
        ca-certificates \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

# タイムゾーン
ENV TZ=Asia/Tokyo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# PHP 拡張（POSLA で実際に使われているもののみ）
#   pdo_mysql … api/lib/db.php
#   mbstring  … 多言語処理
#   intl      … タイムゾーン・ロケール
#   zip       … 帳票出力
#   bcmath    … 金額計算
#   opcache   … パフォーマンス
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        intl \
        zip \
        bcmath \
        opcache

# Redis-backed PHP sessions (keeps login sessions across app container rebuilds).
RUN printf "\n" | pecl install redis \
    && docker-php-ext-enable redis

# Apache: mod_rewrite + AllowOverride All
RUN a2enmod rewrite headers

# php.ini（開発用: エラー全表示・logging on）
RUN { \
        echo "display_errors = On"; \
        echo "display_startup_errors = On"; \
        echo "error_reporting = E_ALL"; \
        echo "log_errors = On"; \
        echo "error_log = /var/log/apache2/php_error.log"; \
        echo "date.timezone = Asia/Tokyo"; \
        echo "post_max_size = 32M"; \
        echo "upload_max_filesize = 32M"; \
        echo "memory_limit = 256M"; \
        echo "default_charset = UTF-8"; \
    } > /usr/local/etc/php/conf.d/zz-posla-local.ini

# DocumentRoot は apache-vhost.conf でマウント上書きする（compose の volumes 参照）
WORKDIR /var/www/html

COPY cron-loop.sh /usr/local/bin/posla-cron-loop.sh
COPY startup.sh /usr/local/bin/posla-startup.sh
RUN chmod +x /usr/local/bin/posla-cron-loop.sh /usr/local/bin/posla-startup.sh

EXPOSE 80

CMD ["/usr/local/bin/posla-startup.sh"]
