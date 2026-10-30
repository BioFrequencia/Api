<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Histórico de faltas e atrasos de UM aluno num período - usado pelo
 * relatório "Frequência individual por período" no desktop.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
// Datas do período no calendário da escola (padrão: mês corrente).
$dataInicio = $dados["data_inicio"] ?? inicioDoMesLocal();
$dataFim = $dados["data_fim"] ?? hojeNaEscola();

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
// Antes qualquer texto ia direto para a consulta e virava erro 500.
if (!dataIsoValida($dataInicio) || !dataIsoValida($dataFim)) {
    enviarErro("Informe o período no formato AAAA-MM-DD.", 422);
}
if ($dataInicio > $dataFim) {
    enviarErro("A data inicial não pode ser depois da final.", 422);
}
// Limites do período em UTC: do início do primeiro dia ao início do dia
// seguinte ao último (no fuso da escola).
$limiteInicio = localParaUtc($dataInicio);
$limiteFim = localParaUtc((new DateTimeImmutable($dataFim))->modify('+1 day')->format('Y-m-d'));

try {
    // Só o histórico de aluno da própria escola. Junto, a conta do app
    // (e-mail e desde quando) e quantos celulares estão conectados, para
    // o painel "App do aluno" do site.
    $stmtAluno = $conexao->prepare("
        SELECT al.id_aluno, al.nome_aluno, s.nome_sala,
               c.email_conta AS email_app, c.criado_em AS conta_app_desde,
               (SELECT count(*) FROM tb_dispositivo_aluno d WHERE d.id_aluno = al.id_aluno AND d.ativo) AS celulares_ativos
        FROM tb_aluno al
        JOIN tb_sala s ON s.id_sala = al.id_sala
        LEFT JOIN tb_conta_aluno c ON c.id_aluno = al.id_aluno
        WHERE al.id_aluno = ? AND s.id_escola = ?
    ");
    $stmtAluno->bindValue(1, $idAluno);
    $stmtAluno->bindValue(2, escolaDaCoordenacao($conexao, $idCoordenacao));
    $stmtAluno->execute();
    $aluno = $stmtAluno->fetch(PDO::FETCH_ASSOC);

    if (!$aluno) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $stmtFaltas = $conexao->prepare("
        SELECT id_falta AS id, dta_falta AS data, motivo_falta AS motivo, justificado_falta AS justificado, observacao_falta AS observacao
        FROM tb_falta WHERE id_aluno = ? AND dta_falta >= ? AND dta_falta < ?
        ORDER BY dta_falta DESC
    ");
    $stmtFaltas->bindValue(1, $idAluno);
    $stmtFaltas->bindValue(2, $limiteInicio);
    $stmtFaltas->bindValue(3, $limiteFim);
    $stmtFaltas->execute();
    $faltas = $stmtFaltas->fetchAll(PDO::FETCH_ASSOC);

    $stmtAtrasos = $conexao->prepare("
        SELECT id_atraso AS id, dta_atraso AS data, motivo_atraso AS motivo, justificado_atraso AS justificado, observacao_atraso AS observacao
        FROM tb_atraso WHERE id_aluno = ? AND dta_atraso >= ? AND dta_atraso < ?
        ORDER BY dta_atraso DESC
    ");
    $stmtAtrasos->bindValue(1, $idAluno);
    $stmtAtrasos->bindValue(2, $limiteInicio);
    $stmtAtrasos->bindValue(3, $limiteFim);
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
