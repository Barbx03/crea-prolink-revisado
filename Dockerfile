# =============================================================================
# CREA Pro-Link | Imagem da aplicação
#
# PHP 8.3 com Apache, atendendo ao item 8.1.1 do Termo de Referência
# (PHP 8.2 ou superior). A raiz de documentos aponta para public/, mantendo
# código-fonte, dependências e configuração fora do alcance do navegador.
# =============================================================================

FROM php:8.3-apache

LABEL org.opencontainers.image.title="CREA Pro-Link" \
      org.opencontainers.image.description="Plataforma de aproximação entre profissionais e demandas técnicas" \
      org.opencontainers.image.vendor="Desafio CREA-AM"

# -----------------------------------------------------------------------------
# Extensões PHP necessárias
#   pdo_mysql : acesso ao MariaDB
#   mbstring  : tratamento de texto em UTF-8 (exige libonig-dev)
#   intl      : comparação e formatação sensíveis a idioma
#   gd        : validação e manipulação das imagens de perfil
#   zip       : usada pelo Composer
# -----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        unzip \
        git \
        default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl gd zip opcache \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# Apache: reescrita de URL e cabeçalhos
# -----------------------------------------------------------------------------
RUN a2enmod rewrite headers expires deflate

COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-prolink.ini

# -----------------------------------------------------------------------------
# Composer
# -----------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependências primeiro, para aproveitar o cache de camadas do Docker
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --optimize-autoloader --no-progress

# Código-fonte
COPY . .

# Recompõe o autoload agora que app/ existe
RUN composer dump-autoload --optimize --no-dev

# -----------------------------------------------------------------------------
# Permissões: apenas os diretórios de escrita pertencem ao usuário do Apache
# (princípio do menor privilégio, item 5 do Termo de Referência)
# -----------------------------------------------------------------------------
RUN mkdir -p storage/logs storage/cache storage/uploads public/uploads \
    && chown -R www-data:www-data storage public/uploads \
    && chmod -R 775 storage public/uploads \
    && find /var/www/html -type f -name "*.php" -exec chmod 644 {} \;

COPY docker/php/entrada.sh /usr/local/bin/entrada.sh
RUN chmod +x /usr/local/bin/entrada.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/entrada.sh"]
CMD ["apache2-foreground"]
