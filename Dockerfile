# Imagem da BioFrequenciaApi para hospedagem (Render, Fly, qualquer
# plataforma que rode container).
#
# Apache + mod_php e não php-fpm+nginx: a API é um conjunto de arquivos
# .php servidos por caminho (user/marcarFalta.php), sem front-controller
# nem roteador, exatamente como roda no XAMPP da escola. Trocar o
# servidor mudaria o comportamento de .htaccess e de getallheaders(),
# que a autenticação usa.
FROM php:8.3-apache

# pdo_pgsql é a única extensão que falta na imagem base; libpq-dev é
# necessária para compilá-la. unzip é o que o Composer usa para extrair
# os pacotes: a imagem base não tem unzip nem a extensão zip, e sem
# nenhum dos dois o "composer install" abaixo falhava (era o motivo do
# build da imagem quebrar no CI). O apt é limpo na mesma camada para a
# imagem não carregar o cache.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev unzip \
 && docker-php-ext-install pdo_pgsql \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/*

# mod_headers e mod_rewrite são exigidos pelo .htaccess (CORS nas
# respostas de erro e o atalho do preflight). AllowOverride All porque,
# por padrão, o Apache ignora .htaccess.
RUN a2enmod headers rewrite \
 && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
    > /etc/apache2/conf-available/biofrequencia.conf \
 && a2enconf biofrequencia

# Configuração de produção do PHP: não mostrar erro na resposta (o
# detalhe vai para o log do container), mas registrar tudo.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'display_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\nexpose_php=Off\n' \
    > "$PHP_INI_DIR/conf.d/biofrequencia.ini"

WORKDIR /var/www/html

# As dependências são instaladas antes do código para o cache do Docker
# não ser invalidado a cada alteração de .php.
COPY composer.json composer.lock ./
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY . .

# config/config.php NUNCA entra na imagem: ele tem as credenciais e está
# no .gitignore. Em tempo de execução, o entrypoint gera o arquivo a
# partir das variáveis de ambiente da plataforma.
RUN rm -f config/config.php .env \
 && chown -R www-data:www-data /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# O Render (e a maioria das plataformas) injeta a porta em $PORT; o
# Apache precisa ser configurado para ela em tempo de execução, não aqui.
ENV PORT=8080
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$c=@file_get_contents("http://127.0.0.1:".getenv("PORT")."/health.php"); exit($c && str_contains($c,"\"sucesso\":true") ? 0 : 1);'

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
