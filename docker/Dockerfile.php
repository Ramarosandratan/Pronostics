FROM php:8.2-cli

# Install system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    wget \
    unzip \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    zlib1g-dev \
    && docker-php-ext-install \
    pdo_pgsql \
    intl \
    zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Create vendor directory for volume mount
RUN mkdir -p /app/vendor

ENTRYPOINT []
CMD []
