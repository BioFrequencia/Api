<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/frequencia.php';
require_once __DIR__ . '/../helpers/alertas.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

$idCoordenacao = autenticar();

/**
 * Frequência "da rede" (ou de uma turma) num intervalo: 100% menos a
 * proporção de faltas registradas sobre o total de dias-letivos-aluno
 * possíveis no período (dias letivos do mês x número de alunos).
 */
function frequenciaAgregada(int $totalFaltas, int $totalAlunos): float {
    if ($totalAlunos <= 0) return 100.0;
    return max(0.0, round(100 - ($totalFaltas / ($totalAlunos * DIAS_LETIVOS_MES)) * 100, 1));
}

try {
    $totalAlunos = (int) $conexao->query("SELECT COUNT(*) FROM tb_aluno")->fetchColumn();
    $primeiroDiaMes = date('Y-m-01');
    $primeiroDiaMesAnterior = date('Y-m-01', strtotime('-1 month'));

    $stmtFaltasMes = $conexao->prepare("SELECT COUNT(*) FROM tb_falta WHERE dta_falta >= ?");
    $stmtFaltasMes->execute([$primeiroDiaMes]);
    $faltasMes = (int) $stmtFaltasMes->fetchColumn();

    $stmtFaltasMesAnterior = $conexao->prepare("SELECT COUNT(*) FROM tb_falta WHERE dta_falta >= ? AND dta_falta < ?");
    $stmtFaltasMesAnterior->execute([$primeiroDiaMesAnterior, $primeiroDiaMes]);
    $faltasMesAnterior = (int) $stmtFaltasMesAnterior->fetchColumn();

    $frequenciaGeral = frequenciaAgregada($faltasMes, $totalAlunos);
    $frequenciaMesAnterior = frequenciaAgregada($faltasMesAnterior, $totalAlunos);

    $stmtAtrasosMes = $conexao->prepare("SELECT COUNT(*) FROM tb_atraso WHERE dta_atraso >= ?");
    $stmtAtrasosMes->execute([$primeiroDiaMes]);
    $atrasosMes = (int) $stmtAtrasosMes->fetchColumn();

    $stmtAtrasosMesAnterior = $conexao->prepare("SELECT COUNT(*) FROM tb_atraso WHERE dta_atraso >= ? AND dta_atraso < ?");
    $stmtAtrasosMesAnterior->execute([$primeiroDiaMesAnterior, $primeiroDiaMes]);
    $atrasosMesAnterior = (int) $stmtAtrasosMesAnterior->fetchColumn();

    // Alunos "em observação": frequência entre 80% e 90% (abaixo de 80
    // já vira alerta de prioridade alta, então não conta duas vezes aqui).
    $stmtObs = $conexao->prepare("
        SELECT COALESCE(f.qtd_faltas, 0) AS qtd_faltas
        FROM tb_aluno al
        LEFT JOIN (
            SELECT id_aluno, COUNT(*) AS qtd_faltas FROM tb_falta WHERE dta_falta >= ? GROUP BY id_aluno
        ) f ON f.id_aluno = al.id_aluno
    ");
    $stmtObs->execute([$primeiroDiaMes]);
    $alunosObservacao = 0;
    foreach ($stmtObs->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $freq = calcularFrequencia((int) $linha['qtd_faltas']);
        if ($freq >= 80 && $freq < 90) $alunosObservacao++;
    }

    // Evolução mensal: últimos 6 meses (incluindo o atual).
    $evolucaoMensal = [];
    for ($i = 5; $i >= 0; $i--) {
        $inicio = date('Y-m-01', strtotime("-$i month"));
        $fim = date('Y-m-t', strtotime("-$i month")) . ' 23:59:59';
        $stmt = $conexao->prepare("SELECT COUNT(*) FROM tb_falta WHERE dta_falta >= ? AND dta_falta <= ?");
        $stmt->execute([$inicio, $fim]);
        $faltas = (int) $stmt->fetchColumn();
        $evolucaoMensal[] = [
            "mes"        => $inicio,
            "frequencia" => frequenciaAgregada($faltas, $totalAlunos),
        ];
    }

    // Frequência por turma no mês atual.
    $stmtTurmas = $conexao->prepare("
        SELECT s.id_sala, s.nome_sala, COUNT(al.id_aluno) AS total_alunos, COALESCE(SUM(sub.qtd_faltas), 0) AS total_faltas
        FROM tb_sala s
        LEFT JOIN tb_aluno al ON al.id_sala = s.id_sala
        LEFT JOIN (
            SELECT id_aluno, COUNT(*) AS qtd_faltas FROM tb_falta WHERE dta_falta >= ? GROUP BY id_aluno
        ) sub ON sub.id_aluno = al.id_aluno
        GROUP BY s.id_sala, s.nome_sala
        ORDER BY s.nome_sala
    ");
    $stmtTurmas->execute([$primeiroDiaMes]);
    $turmas = [];
    foreach ($stmtTurmas->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $turmas[] = [
            "nome_sala"    => $t['nome_sala'],
            "total_alunos" => (int) $t['total_alunos'],
            "frequencia"   => frequenciaAgregada((int) $t['total_faltas'], (int) $t['total_alunos']),
        ];
    }

    $alertas = buscarAlertas($conexao, escolaDaCoordenacao($conexao, $idCoordenacao));
    $alertasAltaPrioridade = count(array_filter($alertas, fn($a) => $a['prioridade'] === 'alta'));

    // Contatos pendentes: alunos com alerta ativo sem nenhum registro de
    // acompanhamento nos últimos 7 dias.
    $idsComAlerta = array_values(array_unique(array_column($alertas, 'id_aluno')));
    $contatosPendentes = count($idsComAlerta);
    if (!empty($idsComAlerta)) {
        $placeholders = implode(',', array_fill(0, count($idsComAlerta), '?'));
        $stmtAcomp = $conexao->prepare("
            SELECT DISTINCT id_aluno FROM tb_acompanhamento
            WHERE id_aluno IN ($placeholders) AND data_registro >= ?
        ");
        $stmtAcomp->execute([...$idsComAlerta, date('Y-m-d', strtotime('-7 days'))]);
        $idsComAcompanhamento = array_map('intval', array_column($stmtAcomp->fetchAll(PDO::FETCH_ASSOC), 'id_aluno'));
        $contatosPendentes = count(array_diff($idsComAlerta, $idsComAcompanhamento));
    }

    enviarSucesso("Dashboard carregado com sucesso.", [
        "frequencia_geral"        => $frequenciaGeral,
        "delta_frequencia"        => round($frequenciaGeral - $frequenciaMesAnterior, 1),
        "alertas_ativos"          => count($alertas),
        "alertas_alta_prioridade" => $alertasAltaPrioridade,
        "alunos_observacao"       => $alunosObservacao,
        "atrasos_mes"             => $atrasosMes,
        "delta_atrasos"           => $atrasosMes - $atrasosMesAnterior,
        "contatos_pendentes"      => $contatosPendentes,
        "evolucao_mensal"         => $evolucaoMensal,
        "turmas"                  => $turmas,
        "prioridade_lista"        => array_slice($alertas, 0, 5),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar o dashboard.", 500, $erro);
}
