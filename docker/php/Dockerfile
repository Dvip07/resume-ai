FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        curl \
        ca-certificates \
        libzip-dev \
        libpng-dev \
        libicu-dev \
        libonig-dev \
        supervisor \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        zip \
        bcmath \
        gd \
        intl \
    && rm -rf /var/lib/apt/lists/*

# `supervisor` above is for the `queue-worker` service, which runs this same
# image with docker/supervisor/supervisord.conf instead of `artisan serve`
# (Requirement 11.4).

# LaTeX engine (Requirement 6.2). tectonic is a single self-contained binary, so
# it costs ~25 MB here instead of the multi-gigabyte layer a TeX Live install
# would add. Version is pinned so image builds stay reproducible.
#
# tectonic does not bundle the TeX packages themselves: on first run it fetches
# the TeX bundle it needs over the network and caches it, so the first PDF
# compile in a fresh container is noticeably slower than later ones. The cache
# lives under $XDG_CACHE_HOME/Tectonic — /root/.cache/Tectonic for the root user
# this image runs as — and can be relocated with TECTONIC_CACHE_DIR. Mounting a
# persistent volume at that path stops every container rebuild from
# re-downloading the bundle.
ARG TECTONIC_VERSION=0.17.0
RUN curl -fsSL -o /tmp/tectonic.tar.gz \
        "https://github.com/tectonic-typesetting/tectonic/releases/download/tectonic%40${TECTONIC_VERSION}/tectonic-${TECTONIC_VERSION}-x86_64-unknown-linux-gnu.tar.gz" \
    && tar -xzf /tmp/tectonic.tar.gz -C /usr/local/bin tectonic \
    && chmod +x /usr/local/bin/tectonic \
    && rm -f /tmp/tectonic.tar.gz

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
