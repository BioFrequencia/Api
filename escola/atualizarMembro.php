<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/equipe.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão decide sobre uma conta da própria escola:
 *
 *   { id_coordenacao, acao: aprovar | recusar | desativar | reativar |
 *                           tornar_gestor | tornar_coordenador }
 *
 * Regras (helpers/equipe.php): a escola nunca fica sem gestão ativa, e
 * quem deixa de estar ativo perde as sessões abertas na hora.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$acao = $dados['acao'] ?? null;
if (!inteiroValido($dados['id_coordenacao'] ?? null)) {
    enviarErro("Informe a conta.", 422);
}
if (!in_array($acao, ACOES_EQUIPE, true)) {
    enviarErro("Ação inválida.", 422);
}

try {
    $membro = membroDaEquipe($conexao, (int) $dados['id_coordenacao']);
    // Conta de outra escola: 404, como se não existisse.
    if ($membro === null || $membro['id_escola'] !== $conta['id_escola']) {
        enviarErro("Conta não encontrada nesta escola.", 404);
    }
    $erro = aplicarAcaoNaEquipe($conexao, $membro, $acao, (int) $idCoordenacao);
    if ($erro !== null) {
        enviarErro($erro, 409);
    }
    enviarSucesso("Equipe atualizada.", membroDaEquipe($conexao, $membro['id_coordenacao']));
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao atualizar a equipe.", 500, $erro);
}
