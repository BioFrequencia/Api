<?php
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/config.php";

use Firebase\JWT\JWT;

/** Sessão do site: 2 horas. */
const DURACAO_SESSAO_SITE = 60 * 120;
/** Sessão do app de biometria, num tablet da escola durante o turno: 12 horas. */
const DURACAO_SESSAO_APP = 60 * 60 * 12;

function criarToken($idUsuario, int $duracaoSegundos = DURACAO_SESSAO_SITE){
    $payload = [
        "iss" => "biofrequencia-api",
        "iat" => time(),
        "exp" => time() + $duracaoSegundos,
        "sub" => $idUsuario
    ];

    return JWT::encode($payload, JWT_SECRET_KEY, 'HS256');
}
