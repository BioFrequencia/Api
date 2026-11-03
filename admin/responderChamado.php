<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A equipe do sistema responde um chamado ou muda o status dele.
 *
 *   { id_chamado, resposta?: texto, status: em_andamento | respondido | fechado }
 *
 * Com resposta, o status padrão é "respondido" e a data da resposta é
 * gravada; quem abriu vê em Suporte > Meus chamados.
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$resposta = is_string($dados['resposta'] ?? null) ? trim($dados['resposta']) : null;
$status = $dados['status'] ?? ($resposta !== null && $resposta !== '' ? 'respondido' : null);

if (!inteiroValido($dados['id_chamado'] ?? null)) {
    enviarErro("Informe o chamado.", 422);
}
if (!in_array($status, ['em_andamento', 'respondido', 'fechado'], true)) {
    enviarErro("Status inválido: use em_andamento, respondido ou fechado.", 422);
}
if ($status === 'respondido' && ($resposta === null || $resposta === '')) {
    enviarErro("Escreva a resposta para marcar como respondido.", 422);
}
if ($resposta !== null && mb_strlen($resposta) > 4000) {
    enviarErro("A resposta deve ter no máximo 4000 caracteres.", 422);
}

try {
    $temResposta = $resposta !== null && $resposta !== '';
    $stmt = $conexao->prepare("
        UPDATE tb_chamado_suporte
        SET status = ?,
            resposta = CASE WHEN ? THEN ? ELSE resposta END,
            respondido_em = CASE WHEN ? THEN NOW() ELSE respondido_em END,
            atualizado_em = NOW()
        WHERE id_chamado = ?
    ");
    $stmt->execute([$status, $temResposta ? 'true' : 'false', $resposta, $temResposta ? 'true' : 'false', (int) $dados['id_chamado']]);
    if ($stmt->rowCount() === 0) {
        enviarErro("Chamado não encontrado.", 404);
    }
    enviarSucesso($temResposta ? "Resposta enviada." : "Chamado atualizado.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao responder o chamado.", 500, $erro);
}
