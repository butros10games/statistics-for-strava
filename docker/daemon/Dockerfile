FROM dunglas/frankenphp:1-php8.5

WORKDIR /var/www

RUN apt-get update && apt-get install -y --no-install-recommends \
  chromium \
  curl \
  geos-bin \
  python3 \
  python3-pip \
  tzdata \
  xvfb \
  xauth \
  x11-utils \
  && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' '#!/bin/sh' 'exec /usr/bin/chromium --no-sandbox --disable-dev-shm-usage "$@"' > /usr/local/bin/google-chrome \
 && chmod +x /usr/local/bin/google-chrome \
 && ln -sf /usr/local/bin/google-chrome /usr/local/bin/chromium \
 && ln -sf /usr/local/bin/google-chrome /usr/local/bin/chromium-browser

RUN python3 -m pip install --break-system-packages --no-cache-dir uv

RUN set -eux; \
	install-php-extensions \
        bcmath \
        ctype \
        curl \
        dom \
        fileinfo \
        gd \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_sqlite \
        phar \
        session \
        simplexml \
        tokenizer \
        xml \
        xmlreader \
        xmlwriter \
        pcntl \
        zstd;

COPY docker/app/config/php.ini ${PHP_INI_DIR}/php.ini
COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY . /var/www/
RUN touch /var/www/.env \
 && rm -Rf docker \
 && mkdir -p /var/www/var/cache/dev /var/www/var/log

ARG TARGETARCH
ENV TARGETARCH=${TARGETARCH}
ARG SHOUTRRR_VERSION="0.14.3"

RUN if [ "${TARGETARCH}" = "arm64" ]; then \
      curl -L -o shoutrrr.tar.gz "https://github.com/nicholas-fedor/shoutrrr/releases/download/v$SHOUTRRR_VERSION/shoutrrr_linux_arm64v8_${SHOUTRRR_VERSION}.tar.gz"; \
    elif [ "${TARGETARCH}" = "amd64" ]; then \
      curl -L -o shoutrrr.tar.gz "https://github.com/nicholas-fedor/shoutrrr/releases/download/v$SHOUTRRR_VERSION/shoutrrr_linux_amd64_${SHOUTRRR_VERSION}.tar.gz"; \
    fi
RUN tar -xzf shoutrrr.tar.gz \
    && chmod +x shoutrrr \
    && mv shoutrrr /usr/bin/shoutrrr && rm shoutrrr.tar.gz

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["bin/console", "app:daemon:run"]