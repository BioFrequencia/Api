<?php

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../helpers/sessao.php";

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

/** Sessão (tb_sessao) do token desta requisição; null em token sem sessão. */
function sessaoAtual(): ?string {
    return $GLOBALS['idSessaoDaRequisicao'] ?? null;
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
    } catch (ExpiredException $ex) {
        http_response_code(401);
        echo json_encode(["sucesso" => false, "mensagem" => "Sessão expirada. Faça login novamente.", "dados" => null]);
        exit;
    } catch (Exception $ex) {
        http_response_code(401);
        echo json_encode(["sucesso" => false, "mensagem" => "Token inválido ou expirado", "dados" => null]);
        exit;
    }

    // Token de sessão (logins depois da migração 007): a sessão pode ter
    // sido encerrada em "Dispositivos conectados" ou pela troca de senha.
    // Token antigo, sem "sid", continua valendo até vencer.
    if (isset($decoded->sid) && is_string($decoded->sid)) {
        global $conexao;
        try {
            $ativa = sessaoAtiva($conexao, $decoded->sid, (int) $decoded->sub);
        } catch (PDOException $erro) {
            enviarErro("Erro no servidor ao conferir a sessão.", 500, $erro);
        }
        if (!$ativa) {
            http_response_code(401);
            echo json_encode(["sucesso" => false, "mensagem" => "Sessão encerrada. Faça login novamente.", "dados" => null]);
            exit;
        }
        $GLOBALS['idSessaoDaRequisicao'] = $decoded->sid;
    }
    return $decoded->sub;
}
