<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/cancelamento.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão desfaz um cancelamento feito por engano.
 *
 *   { tipo: atraso | saida | presenca, id, motivo? }
 *
 * Recusado (409) quando o aluno já tem outro registro ativo do mesmo tipo
 * no mesmo dia. Fica no histórico de auditoria, como o cancelamento.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$tipo = $dados['tipo'] ?? null;
if (!is_string($tipo) || !isset(REGISTROS_CANCELAVEIS[$tipo]) || !inteiroValido($dados['id'] ?? null)) {
    enviarErro("Informe o tipo (atraso, saida ou presenca) e o registro.", 422);
}
[$motivo, $erroMotivo] = lerMotivoCancelamento($dados['motivo'] ?? null);
if ($erroMotivo !== null) {
    enviarErro($erroMotivo, 422);
}

try {
    $resultado = restaurarRegistro($conexao, $conta, $tipo, (int) $dados['id'], $motivo);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao restaurar o registro.", 500, $erro);
}
if (!$resultado['ok']) {
    enviarErro($resultado['erro'], $resultado['status']);
}
enviarSucesso("Registro restaurado: voltou a contar para {$resultado['nome_aluno']}.", ['tipo' => $tipo, 'id' => (int) $dados['id']]);
