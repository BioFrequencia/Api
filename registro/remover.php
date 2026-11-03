<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão apaga uma entrada atrasada ou saída antecipada registrada por
 * engano (aluno errado, horário da turma mal configurado quando a
 * presença virou atraso sozinha).
 *
 *   { tipo: atraso | saida, id }
 *
 * Só da própria escola, e só a gestão: antes, um registro errado só
 * saía abrindo chamado. Avisos que já foram para a família não voltam.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$tipo = $dados['tipo'] ?? null;
if (!in_array($tipo, ['atraso', 'saida'], true) || !inteiroValido($dados['id'] ?? null)) {
    enviarErro("Informe o tipo (atraso ou saida) e o registro.", 422);
}
[$tabela, $coluna] = $tipo === 'atraso' ? ['tb_atraso', 'id_atraso'] : ['tb_saida_antecipada', 'id_saida'];
$id = (int) $dados['id'];

try {
    $conexao->beginTransaction();
    $existe = $conexao->prepare("
        SELECT 1 FROM $tabela r
        JOIN tb_aluno a ON a.id_aluno = r.id_aluno
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE r.$coluna = ? AND s.id_escola = ?
    ");
    $existe->execute([$id, $conta['id_escola']]);
    if (!$existe->fetchColumn()) {
        $conexao->rollBack();
        enviarErro("Registro não encontrado.", 404);
    }
    if ($tipo === 'atraso') {
        // Ocorrência do celular do aluno (fluxo anterior) aprovada e que
        // virou este atraso: continua no histórico, sem o vínculo.
        $conexao->prepare("UPDATE tb_ocorrencia_pendente SET id_atraso_criado = NULL WHERE id_atraso_criado = ?")->execute([$id]);
    }
    $conexao->prepare("DELETE FROM $tabela WHERE $coluna = ?")->execute([$id]);
    $conexao->commit();
    enviarSucesso($tipo === 'atraso' ? "Entrada atrasada removida." : "Saída antecipada removida.");
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao remover o registro.", 500, $erro);
}
