<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/avisos.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Escola › Envio automático: os canais próprios da escola (e-mail e
 * WhatsApp) e, sem eles, por onde os avisos saem hoje. Nenhum segredo: da
 * chave só o final.
 *
 *   {} -> { canais: { email: ?{...}, whatsapp: ?{...} },
 *           sistema: { email: bool, whatsapp: bool },
 *           chave_servidor: bool, pode_editar: bool }
 *
 * Toda conta da escola vê; só a gestão altera.
 */
$idCoordenacao = autenticar();
$conta = contaDaCoordenacao($conexao, $idCoordenacao);

try {
    $canais = canaisDaEscolaParaTela($conexao, $conta['id_escola']);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar o envio automático.", 500, $erro);
}
enviarSucesso("Envio automático carregado.", [
    'canais' => $canais,
    'sistema' => provedoresConfigurados(),
    // Sem a chave do servidor, a credencial não pode ser guardada cifrada.
    'chave_servidor' => chaveDosCanaisDeEnvio() !== null,
    'pode_editar' => $conta['papel'] === 'gestor',
]);
