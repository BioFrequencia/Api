<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Histórico de faltas e atrasos de UM aluno num período - usado pelo
 * relatório "Frequência individual por período" no desktop.
 */
autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
$dataInicio = $dados["data_inicio"] ?? date('Y-m-01');
$dataFim = $dados["data_fim"] ?? date('Y-m-d');

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

try {
    $stmtAluno = $conexao->prepare("
        SELECT al.id_aluno, al.nome_aluno, s.nome_sala
        FROM tb_aluno al
        LEFT JOIN tb_sala s ON s.id_sala = al.id_sala
        WHERE al.id_aluno = ?
    ");
    $stmtAluno->bindValue(1, $idAluno);
    $stmtAluno->execute();
    $aluno = $stmtAluno->fetch(PDO::FETCH_ASSOC);

    if (!$aluno) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $stmtFaltas = $conexao->prepare("
        SELECT id_falta AS id, dta_falta AS data, motivo_falta AS motivo, justificado_falta AS justificado, observacao_falta AS observacao
        FROM tb_falta WHERE id_aluno = ? AND dta_falta >= ? AND dta_falta <= ?
        ORDER BY dta_falta DESC
    ");
    $stmtFaltas->bindValue(1, $idAluno);
    $stmtFaltas->bindValue(2, $dataInicio);
    $stmtFaltas->bindValue(3, $dataFim . ' 23:59:59');
    $stmtFaltas->execute();
    $faltas = $stmtFaltas->fetchAll(PDO::FETCH_ASSOC);

    $stmtAtrasos = $conexao->prepare("
        SELECT id_atraso AS id, dta_atraso AS data, motivo_atraso AS motivo, justificado_atraso AS justificado, observacao_atraso AS observacao
        FROM tb_atraso WHERE id_aluno = ? AND dta_atraso >= ? AND dta_atraso <= ?
        ORDER BY dta_atraso DESC
    ");
    $stmtAtrasos->bindValue(1, $idAluno);
    $stmtAtrasos->bindValue(2, $dataInicio);
    $stmtAtrasos->bindValue(3, $dataFim . ' 23:59:59');
    $stmtAtrasos->execute();
    $atrasos = $stmtAtrasos->fetchAll(PDO::FETCH_ASSOC);

    enviarSucesso("Histórico carregado com sucesso.", [
        "aluno"   => $aluno,
        "periodo" => ["inicio" => $dataInicio, "fim" => $dataFim],
        "faltas"  => $faltas,
        "atrasos" => $atrasos,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar o histórico do aluno.", 500, $erro);
}
