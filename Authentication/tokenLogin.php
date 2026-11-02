<?php
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/config.php";

// Durações das sessões (site, "Lembrar de mim" e app).
require_once __DIR__ . "/../helpers/conta.php";

use Firebase\JWT\JWT;

/**
 * $idSessao: a sessão de tb_sessao (migração 007) que este token
 * representa. Vai no token como "sid", e a API recusa o token quando a
 * sessão é encerrada - por "Dispositivos conectados" ou pela troca de
 * senha. Sem ela (banco sem a 007), o token só vale até vencer.
 */
function criarToken($idUsuario, int $duracaoSegundos = DURACAO_SESSAO_SITE, ?string $idSessao = null){
    $payload = [
        "iss" => "biofrequencia-api",
        "iat" => time(),
        "exp" => time() + $duracaoSegundos,
        "sub" => $idUsuario
    ];
    if ($idSessao !== null) {
        $payload["sid"] = $idSessao;
    }

    return JWT::encode($payload, JWT_SECRET_KEY, 'HS256');
}
