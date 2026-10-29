<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Marca uma notificação como enviada MANUALMENTE - a coordenação
 * confirmando que avisou o responsável por fora do sistema (telefone,
 * WhatsApp, etc.). Vale para as pendentes e para as que falharam no
 * envio automático. Só notificações de alunos da própria escola.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idNotificacao = $dados["id_notificacao"] ?? null;

if (!inteiroValido($idNotificacao)) {
    enviarErro("Informe uma notificação válida.", 422);
}

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $update = $conexao->prepare("
        UPDATE tb_notificacao n
        SET status_notificacao = ?, data_envio_notificacao = NOW()
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE n.id_notificacao = ?
          AND a.id_aluno = n.id_aluno
          AND s.id_escola = ?
          AND n.status_notificacao <> ?
    ");
    $update->execute([NOTIFICACAO_ENVIADA, $idNotificacao, $idEscola, NOTIFICACAO_ENVIADA]);

    if ($update->rowCount() === 0) {
        enviarErro("Notificação não encontrada ou já enviada.", 404);
    }

    enviarSucesso("Notificação marcada como enviada.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao atualizar a notificação.", 500, $erro);
}
