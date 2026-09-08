<?php

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

/**
 * Extrai o cabeçalho Authorization funcionando tanto em SAPIs que
 * possuem getallheaders() (Apache/mod_php) quanto em outras (php-fpm/nginx),
 * onde a função pode não existir.
 */
function obterCabecalhoAuthorization(): string {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $nome => $valor) {
            if (strtolower($nome) === 'authorization') {
                return $valor;
            }
        }
    }
    return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

/**
 * Valida o token Bearer enviado pelo desktop e devolve o id do
 * coordenador autenticado (subject do token). Encerra a requisição
 * com 401 caso o token não venha, esteja expirado ou seja inválido.
 */
function autenticar(){
    $authHeader = obterCabecalhoAuthorization();

    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(["sucesso" => false, "mensagem" => "Token não enviado", "dados" => null]);
        exit;
    }

    try {
        $decoded = JWT::decode($matches[1], new Key(JWT_SECRET_KEY, 'HS256'));
        return $decoded->sub;
    } catch (ExpiredException $ex) {
        http_response_code(401);
        echo json_encode(["sucesso" => false, "mensagem" => "Sessão expirada. Faça login novamente.", "dados" => null]);
        exit;
    } catch (Exception $ex) {
        http_response_code(401);
        echo json_encode(["sucesso" => false, "mensagem" => "Token inválido ou expirado", "dados" => null]);
        exit;
    }
}
