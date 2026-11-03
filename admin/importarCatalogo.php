<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/catalogoEscolas.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A equipe do sistema reimporta o catálogo de escolas (o mesmo que o
 * container faz ao iniciar, ver database/importarEscolas.php) - útil se o
 * banco estava fora do ar quando a API subiu. Nunca apaga nada.
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);

try {
    $resumo = importarCatalogoEscolas($conexao);
    enviarSucesso(sprintf(
        "Catálogo importado: %d escolas (%d novas, %d atualizadas).",
        $resumo['total'], $resumo['inseridas'], $resumo['atualizadas']
    ), $resumo);
} catch (Throwable $erro) {
    enviarErro("Erro no servidor ao importar o catálogo de escolas.", 500, $erro);
}
