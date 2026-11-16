FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql curl mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app
COPY . /var/www/app

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public

RUN sed -ri 's!/var/www/html!/var/www/app/public!g' /etc/apache2/sites-available/*.conf \
    && printf '%s\n' \
       '<Directory /var/www/app/public>' \
       '    Options -Indexes +FollowSymLinks' \
       '    AllowOverride All' \
       '    Require all granted' \
       '</Directory>' \
       > /etc/apache2/conf-available/app-public-root.conf \
    && a2enconf app-public-root \
    && printf '%s\n' \
       'file_uploads=On' \
       'upload_max_filesize=64M' \
       'post_max_size=64M' \
       'max_file_uploads=20' \
       'memory_limit=256M' \
       'max_execution_time=120' \
       'max_input_time=120' \
       > /usr/local/etc/php/conf.d/app-uploads.ini \
    && chown -R www-data:www-data /var/www/app

EXPOSE 80
