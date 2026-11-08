<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/mensagemManual.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Para a tela "Enviar mensagem": os modelos prontos, as variáveis que dá
 * para usar no texto, o tamanho máximo e como cada canal sai hoje (com
 * envio automático ou pelo link/fila).
 */
$idCoordenacao = autenticar();
escolaDaCoordenacao($conexao, $idCoordenacao);

$variaveis = [];
foreach (VARIAVEIS_DA_MENSAGEM as $variavel => $descricao) {
    $variaveis[] = ['variavel' => '{' . $variavel . '}', 'descricao' => $descricao];
}
enviarSucesso("Modelos carregados.", [
    'modelos' => MODELOS_DE_MENSAGEM,
    'variaveis' => $variaveis,
    'maximo' => MENSAGEM_MANUAL_MAXIMO,
    'provedores' => provedoresConfigurados(),
]);
