FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    ca-certificates \
    git \
    curl \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libgomp1 \
    python3 \
    python3-venv \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip opcache sockets \
    && php -r 'foreach (["imagecreatefromjpeg", "imagecreatefrompng", "imagecreatefromwebp", "imagecreatetruecolor"] as $function) { if (!function_exists($function)) { fwrite(STDERR, "Required GD function missing: {$function}" . PHP_EOL); exit(1); } }'

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Configure Apache DocumentRoot to Laravel /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Set working directory
WORKDIR /var/www/html

# Keep PHP upload limits above Laravel's 10 MB image validation limit so
# gallery photos reach the scanner instead of failing at the web server layer.
COPY php-scanner.ini /usr/local/etc/php/conf.d/zz-oryzatix-scanner.ini

# Copy application files
COPY . /var/www/html

# Install the lightweight LiteRT runtime used by the web upload scanner.
ENV ORYZATIX_PYTHON=/opt/oryzatix-ml/bin/python
RUN python3 -m venv /opt/oryzatix-ml \
    && /opt/oryzatix-ml/bin/python -m pip install --no-cache-dir -r /var/www/html/training/requirements-inference.txt

# Exclude dev files, ensure storage directories exist and have proper permissions
RUN mkdir -p /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Install composer production dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# Copy and setup entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Default port
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
