<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/resposta.php';

try {

    $dsn = "pgsql:host=" . DB_HOST .
       ";port=" . DB_PORT .
       ";dbname=" . DB_NAME;

    $conexao = new PDO($dsn, DB_USER, DB_PASSWORD);

    $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $erro) {
    // Detalhe completo (host, motivo) só vai para o log do servidor.
    enviarErro("Não foi possível conectar ao banco de dados. Tente novamente em instantes.", 503, $erro);
}
