<?php
/**
 * Padroniza as respostas JSON da API e evita expor detalhes internos
 * (mensagens de exceção, stack traces, erros de SQL) para o cliente final.
 * O detalhe completo do erro vai para o log do servidor; o cliente
 * recebe apenas uma mensagem compreensível.
 */

function enviarSucesso($mensagem, $dados = null, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode(["sucesso" => true, "mensagem" => $mensagem, "dados" => $dados]);
    exit;
}

function enviarErro(string $mensagemUsuario, int $httpCode = 400, ?Throwable $erroInterno = null): void {
    if ($erroInterno !== null) {
        error_log('[BioFrequenciaApi] ' . $erroInterno->getMessage());
    }
    http_response_code($httpCode);
    echo json_encode(["sucesso" => false, "mensagem" => $mensagemUsuario, "dados" => null]);
    exit;
}
