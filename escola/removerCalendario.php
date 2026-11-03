<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão tira um período do calendário da escola (cadastrado errado).
 *
 *   { id_calendario }
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
if (!inteiroValido($dados['id_calendario'] ?? null)) {
    enviarErro("Informe o período do calendário.", 422);
}

try {
    $stmt = $conexao->prepare("DELETE FROM tb_calendario_escola WHERE id_calendario = ? AND id_escola = ?");
    $stmt->execute([(int) $dados['id_calendario'], $conta['id_escola']]);
    if ($stmt->rowCount() === 0) {
        enviarErro("Período não encontrado.", 404);
    }
    enviarSucesso("Período removido do calendário.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao remover o período.", 500, $erro);
}
