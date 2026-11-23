<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/testeEnvio.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Mensagem de teste do envio - só a equipe do sistema (404 para as demais
 * contas). Ver helpers/testeEnvio.php.
 *
 *   { canal: "email" | "whatsapp", destino: "voce@dominio" | "11 99999-0000" }
 *   -> { canal, resultado: "enviado" | "sem_provedor" | "falhou", motivo }
 *
 * A resposta é 200 mesmo quando o provedor recusa: o teste funcionou e o
 * resultado dele é a recusa, com o motivo que o provedor deu (sem o
 * destino). O destino não vai para o log.
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$canal = is_string($dados['canal'] ?? null) ? $dados['canal'] : '';
if (!in_array($canal, ['email', 'whatsapp'], true)) {
    enviarErro("Escolha o canal do teste: e-mail ou WhatsApp.", 400);
}
$destino = destinoDoTeste($canal, is_string($dados['destino'] ?? null) ? $dados['destino'] : '');
if ($destino === null) {
    enviarErro($canal === 'email' ? "Digite um e-mail válido para receber o teste." : "Digite um celular com DDD para receber o teste.", 400);
}

[$resultado, $motivo] = enviarMensagemDeTeste($canal, $destino);
error_log("[BioFrequenciaApi] Mensagem de teste ($canal): $resultado");
enviarSucesso(mensagemDoResultadoDoTeste($canal, $resultado, $motivo), [
    'canal'     => $canal,
    'resultado' => $resultado,
    'motivo'    => $resultado === ENVIO_FALHOU ? $motivo : null,
]);
