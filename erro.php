<?php
/**
 * Resposta dos erros gerados pelo próprio Apache (404 de URL errada,
 * 403 de arquivo protegido, 500 antes do PHP rodar). Ver .htaccess.
 *
 * Existe para esses erros carregarem os mesmos cabeçalhos de CORS do
 * resto da API e responderem no mesmo formato JSON - assim o navegador
 * consegue ler o status de verdade, e o cliente mostra "endereço da API
 * não encontrado" em vez de um erro de CORS enganoso.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/helpers/cors.php';

$status = (int) ($_SERVER['REDIRECT_STATUS'] ?? 404);
if ($status < 400 || $status > 599) {
    $status = 404;
}

$mensagens = [
    403 => 'Acesso negado a este recurso.',
    404 => 'Endereço não encontrado nesta API. Confira a URL base configurada no site ou no aplicativo.',
    500 => 'Erro interno do servidor.',
];

http_response_code($status);
echo json_encode([
    'sucesso'  => false,
    'mensagem' => $mensagens[$status] ?? 'Erro ao processar a requisição.',
    'dados'    => null,
]);
