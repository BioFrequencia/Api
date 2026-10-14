#!/bin/sh
# Monta config/config.php a partir das variáveis de ambiente e ajusta a
# porta do Apache antes de subir o servidor.
#
# Por que gerar o arquivo em vez de ler getenv() dentro do código: todo
# o projeto (e o XAMPP da escola) usa as constantes DB_HOST/DB_NAME/...
# definidas em config/config.php. Gerar o arquivo aqui mantém um único
# jeito de configurar e não exige alterar nenhum dos 35 endpoints.
set -e

faltando=""
for variavel in DB_HOST DB_NAME DB_USER DB_PASSWORD DB_PORT JWT_SECRET_KEY; do
    eval "valor=\$$variavel"
    [ -z "$valor" ] && faltando="$faltando $variavel"
done
if [ -n "$faltando" ]; then
    echo "ERRO: variáveis de ambiente obrigatórias não definidas:$faltando" >&2
    echo "Defina-as no painel da plataforma (Render: Environment)." >&2
    exit 1
fi

# printf com %s e não interpolação direta: uma senha com aspas ou
# barra invertida quebraria o arquivo PHP gerado.
{
    printf '<?php\n'
    printf 'define("DB_HOST", %s);\n'        "'$(printf '%s' "$DB_HOST"     | sed "s/'/\\\\'/g")'"
    printf 'define("DB_NAME", %s);\n'        "'$(printf '%s' "$DB_NAME"     | sed "s/'/\\\\'/g")'"
    printf 'define("DB_USER", %s);\n'        "'$(printf '%s' "$DB_USER"     | sed "s/'/\\\\'/g")'"
    printf 'define("DB_PASSWORD", %s);\n'    "'$(printf '%s' "$DB_PASSWORD" | sed "s/'/\\\\'/g")'"
    printf 'define("DB_PORT", %s);\n'        "'$(printf '%s' "$DB_PORT"     | sed "s/'/\\\\'/g")'"
    printf 'define("JWT_SECRET_KEY", %s);\n' "'$(printf '%s' "$JWT_SECRET_KEY" | sed "s/'/\\\\'/g")'"
} > /var/www/html/config/config.php

chown www-data:www-data /var/www/html/config/config.php
chmod 640 /var/www/html/config/config.php

# Porta dinâmica da plataforma.
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "BioFrequenciaApi iniciando na porta ${PORT}"
exec "$@"
