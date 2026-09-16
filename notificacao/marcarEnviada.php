<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Marca uma notificação como enviada MANUALMENTE - não existe provedor
 * de email/SMS integrado (ver helpers/notificacao.php), então isto
 * representa a coordenação confirmando que avisou o responsável por
 * fora do sistema (telefone, WhatsApp, etc.).
 */
autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idNotificacao = $dados["id_notificacao"] ?? null;

if (!inteiroValido($idNotificacao)) {
    enviarErro("Informe uma notificação válida.", 422);
}

try {
    $update = $conexao->prepare("
        UPDATE tb_notificacao
        SET status_notificacao = 'enviada', data_envio_notificacao = NOW()
        WHERE id_notificacao = ? AND status_notificacao != 'enviada'
    ");
    $update->bindParam(1, $idNotificacao);
    $update->execute();

    if ($update->rowCount() === 0) {
        enviarErro("Notificação não encontrada ou já enviada.", 404);
    }

    enviarSucesso("Notificação marcada como enviada.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao atualizar a notificação.", 500, $erro);
}
