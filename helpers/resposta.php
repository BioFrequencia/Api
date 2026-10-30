<?php
/**
 * Padroniza as respostas JSON da API e evita expor detalhes internos
 * (mensagens de exceção, stack traces, erros de SQL) para o cliente final.
 * O detalhe completo do erro vai para o log do servidor; o cliente
 * recebe apenas uma mensagem compreensível.
 */

// Todo endpoint passa por aqui (direto ou via database/conexao.php): é o
// lugar que garante o relógio em UTC antes de qualquer date()/DateTime.
require_once __DIR__ . '/tempo.php';

function enviarSucesso($mensagem, $dados = null, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode(["sucesso" => true, "mensagem" => $mensagem, "dados" => $dados]);
    exit;
}

/**
 * $dados é opcional e só para o que ajuda o cliente a resolver o erro
 * (ex.: o id do registro que já existe num 409) - nunca detalhe interno.
 */
function enviarErro(string $mensagemUsuario, int $httpCode = 400, ?Throwable $erroInterno = null, $dados = null): void {
    if ($erroInterno !== null) {
        error_log('[BioFrequenciaApi] ' . $erroInterno->getMessage());
    }
    http_response_code($httpCode);
    echo json_encode(["sucesso" => false, "mensagem" => $mensagemUsuario, "dados" => $dados]);
    exit;
}
