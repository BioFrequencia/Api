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
 * Cancela uma entrada atrasada, saída antecipada ou presença registrada
 * por engano (aluno errado, horário da turma mal configurado quando a
 * presença virou atraso sozinha, reconhecimento facial que apontou outro
 * aluno).
 *
 *   { tipo: atraso | saida | presenca, id, motivo? }
 *   -> { cancelados: [{tipo, id}] }
 *
 * Desde a migração 012 o registro não é apagado: fica cancelado, com quem,
 * quando e o motivo, e o histórico de auditoria guarda como ele estava
 * (helpers/cancelamento.php). O nome do endpoint continua o mesmo para o
 * desktop, que já o chamava para "Apagar".
 *
 * Quem pode: a gestão da escola, ou quem fez o registro nos primeiros
 * minutos. Avisos que já foram para a família não voltam.
 */
$idCoordenacao = autenticar();
$conta = contaDaCoordenacao($conexao, $idCoordenacao);
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
    $resultado = cancelarRegistro($conexao, $conta, $tipo, (int) $dados['id'], $motivo);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao cancelar o registro.", 500, $erro);
}
if (!$resultado['ok']) {
    enviarErro($resultado['erro'], $resultado['status']);
}

$nomes = ['atraso' => 'Entrada atrasada cancelada', 'saida' => 'Saída antecipada cancelada', 'presenca' => 'Presença cancelada'];
$mensagem = $nomes[$tipo] . '.';
if ($tipo === 'presenca' && count($resultado['cancelados']) > 1) {
    $mensagem = 'Presença cancelada, junto com a entrada atrasada que ela gerou.';
}
enviarSucesso($mensagem, ['cancelados' => $resultado['cancelados']]);
