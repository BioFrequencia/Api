<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Sair: encerra a sessão deste token - o aparelho some de "Dispositivos
 * conectados" e o token deixa de valer na hora. Token antigo, sem
 * sessão, só deixa de valer quando vence.
 */
$idCoordenacao = (int) autenticar();
$idSessao = sessaoAtual();

try {
    if ($idSessao !== null) {
        encerrarSessoes($conexao, $idCoordenacao, $idSessao);
    }
    enviarSucesso("Sessão encerrada.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao encerrar a sessão.", 500, $erro);
}
