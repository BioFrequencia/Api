<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/perfil.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Preferências da conta (tema e sons). Ficam no banco para valer em
 * qualquer computador; quem nunca salvou recebe as padrão.
 */
$idCoordenacao = (int) autenticar();

try {
    enviarSucesso("Preferências da conta.", preferenciasDaCoordenacao($conexao, $idCoordenacao));
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as preferências.", 500, $erro);
}
