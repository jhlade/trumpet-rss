FROM php:8.4-cli-alpine

# OS packages: dcron + supervisor; sqlite-libs is needed by pdo_sqlite at runtime.
RUN apk add --no-cache \
        dcron \
        supervisor \
        sqlite-libs \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        sqlite-dev \
    && docker-php-ext-install -j$(nproc) pdo_sqlite pcntl \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

# Tweak PHP for CLI/server use: keep timezone via env, disable opcache for CLI
RUN { \
        echo 'date.timezone = ${TZ}'; \
        echo 'expose_php = Off'; \
    } > "$PHP_INI_DIR/conf.d/trumpet.ini"

WORKDIR /app

# Copy application
COPY src/        /app/src/
COPY public/     /app/public/
COPY bin/        /app/bin/
COPY vendor/     /app/vendor/
COPY config/config.example.yaml /app/config/config.example.yaml
COPY docker/supervisord.conf    /etc/supervisord.conf
COPY docker/crontab             /etc/crontabs/root
COPY docker/entrypoint.sh       /usr/local/bin/entrypoint.sh

RUN chmod +x /app/bin/trumpet /usr/local/bin/entrypoint.sh \
    && mkdir -p /app/storage \
    && touch /app/storage/trumpet.log

# Volumes for user config (mount read-only if you like) and persistent state.
VOLUME ["/app/config", "/app/storage"]

EXPOSE 8080

ENV TRUMPET_LOG_LEVEL=info \
    TZ=Europe/Prague

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
