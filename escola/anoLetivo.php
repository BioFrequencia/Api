<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/anoLetivo.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Tela "Encerrar ano letivo" (só a gestão): as turmas da escola com os
 * alunos ativos de cada uma e quantos já saíram.
 *
 *   {} -> { turmas: [{ id_sala, nome_sala, periodo_sala, alunos: [{ id_aluno, nome_aluno }] }], ex_alunos }
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

try {
    $turmas = array_values(turmasComAlunosAtivos($conexao, $conta['id_escola']));
    $stmt = $conexao->prepare("
        SELECT COUNT(*) FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE s.id_escola = ? AND a.situacao_aluno <> 'ativo'
    ");
    $stmt->execute([$conta['id_escola']]);
    $exAlunos = (int) $stmt->fetchColumn();
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as turmas.", 500, $erro);
}
enviarSucesso("Turmas carregadas.", ["turmas" => $turmas, "ex_alunos" => $exAlunos]);
