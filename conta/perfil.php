<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/perfil.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/** Dados da conta: nome da coordenação, e-mail (o login) e nome da escola. */
$idCoordenacao = (int) autenticar();

try {
    $perfil = perfilDaCoordenacao($conexao, $idCoordenacao);
    if ($perfil === null) {
        enviarErro("Conta de coordenação não encontrada.", 404);
    }
    enviarSucesso("Dados da conta.", $perfil);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar a conta.", 500, $erro);
}
