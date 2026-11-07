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

# A biblioteca de JWT (firebase/php-jwt 7) recusa chave HS256 com menos de
# 32 bytes, ao criar e ao conferir o token: com chave curta, ninguém
# entraria. Melhor nem subir - no Render o deploy falha e a versão
# anterior continua no ar. O log diz só o tamanho mínimo, nunca a chave.
tamanhoDaChave=$(printf '%s' "$JWT_SECRET_KEY" | wc -c | tr -d ' ')
if [ "$tamanhoDaChave" -lt 32 ]; then
    echo "ERRO: JWT_SECRET_KEY precisa ter pelo menos 32 caracteres (bytes)." >&2
    echo "Gere uma com: php -r \"echo bin2hex(random_bytes(32));\" (trocar a chave encerra as sessões abertas)." >&2
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

# Catálogo de escolas (helpers/catalogoEscolas.php): só importa quando a
# versão do arquivo muda. Se falhar (banco fora do ar, migração 008 ainda
# não aplicada), a API sobe do mesmo jeito.
php /var/www/html/database/importarEscolas.php --se-necessario \
    || echo "aviso: catálogo de escolas não importado agora" >&2

# Porta dinâmica da plataforma.
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "BioFrequenciaApi iniciando na porta ${PORT}"
exec "$@"
