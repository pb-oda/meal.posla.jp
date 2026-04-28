# ============================================================
# POSLA — provisioner runner
# ============================================================
# This image runs scripts/cell/provisioner-trigger-server.php on a dedicated VM.
# It uses the host Docker daemon through /var/run/docker.sock, so run it only
# on an isolated provisioner host.
# ============================================================

ARG POSLA_PROVISIONER_PLATFORM=linux/amd64
FROM --platform=${POSLA_PROVISIONER_PLATFORM} docker:27-cli AS docker-cli

FROM --platform=${POSLA_PROVISIONER_PLATFORM} php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        default-mysql-client \
        git \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        openssl \
        tar \
        unzip \
        zip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        intl \
        zip \
        bcmath

COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

ENV TZ=Asia/Tokyo
WORKDIR /srv/posla

CMD ["php", "/srv/posla/scripts/cell/provisioner-trigger-server.php"]
