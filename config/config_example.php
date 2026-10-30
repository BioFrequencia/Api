<?php

define("DB_HOST","NOME DO HOST");
define("DB_NAME","NOME DO BANCO DE DADOS");
define("DB_USER","NOME DO USUARIO");
define("DB_PASSWORD","SENHA DO BANCO DE DADOS");
define("DB_PORT","PORTA");

// Usada para assinar/validar os tokens de login (Authentication/tokenLogin.php
// e Authentication/Authentication.php). Gere uma string longa e aleatória
// própria para cada ambiente (nunca reaproveite a mesma chave em produção
// e desenvolvimento). Nunca faça commit do valor real usado em produção —
// este arquivo é só um exemplo; o real deve ficar em config/config.php,
// que já está no .gitignore.
define("JWT_SECRET_KEY","SUBSTITUA_POR_UMA_CHAVE_ALEATORIA_LONGA");

// Código que quem cria uma conta de coordenação precisa informar no
// cadastro (user/addCoord.php). Sem ele, o cadastro fica desligado. Em
// produção (Render/Docker) use a variável de ambiente de mesmo nome;
// esta constante é a alternativa para quem roda no XAMPP.
// define("CADASTRO_CODIGO_ACESSO","TROQUE_POR_UM_CODIGO_DA_ESCOLA");

?>
