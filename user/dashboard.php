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
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

/**
 * Frequência "da escola" (ou de uma turma) num intervalo: 100% menos a
 * proporção de dias de falta registrados sobre o total de
 * dias-letivos-aluno possíveis no período (dias letivos do mês x número
 * de alunos).
 */
function frequenciaAgregada(int $totalDiasFalta, int $totalAlunos): float {
    if ($totalAlunos <= 0) return 100.0;
    return max(0.0, round(100 - ($totalDiasFalta / ($totalAlunos * DIAS_LETIVOS_MES)) * 100, 1));
}

/**
 * Todas as contagens abaixo são da escola de quem está logado. Antes
 * deste ajuste o dashboard somava alunos, faltas e atrasos de TODAS as
 * escolas e listava as turmas das outras escolas - era o único painel
 * que ainda não respeitava o escopo da migração 005.
 *
 * Faltas e atrasos contam por dia (aluno + data no fuso da escola), não
 * por linha: um lançamento duplicado no mesmo dia não derruba a
 * frequência duas vezes.
 */
$diaFalta = sqlDataNaEscola('f.dta_falta');
$diaAtraso = sqlDataNaEscola('t.dta_atraso');
$joinEscola = "JOIN tb_aluno a ON a.id_aluno = %s.id_aluno JOIN tb_sala s ON s.id_sala = a.id_sala AND s.id_escola = ?";

try {
    $stmtTotalAlunos = $conexao->prepare("
        SELECT COUNT(*) FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala WHERE s.id_escola = ?
    ");
    $stmtTotalAlunos->execute([$idEscola]);
    $totalAlunos = (int) $stmtTotalAlunos->fetchColumn();

    $contarDiasFalta = $conexao->prepare("
        SELECT COUNT(DISTINCT (f.id_aluno, $diaFalta))
        FROM tb_falta f " . sprintf($joinEscola, 'f') . "
        WHERE f.dta_falta >= ? AND f.dta_falta < ?
    ");
    $contarDiasAtraso = $conexao->prepare("
        SELECT COUNT(DISTINCT (t.id_aluno, $diaAtraso))
        FROM tb_atraso t " . sprintf($joinEscola, 't') . "
        WHERE t.dta_atraso >= ? AND t.dta_atraso < ?
    ");
    $contar = function (PDOStatement $stmt, int $mesesAtras) use ($idEscola): int {
        $stmt->execute([$idEscola, inicioDoMesUtc($mesesAtras), inicioDoMesUtc($mesesAtras - 1)]);
        return (int) $stmt->fetchColumn();
    };

    $faltasMes = $contar($contarDiasFalta, 0);
    $faltasMesAnterior = $contar($contarDiasFalta, 1);
    $frequenciaGeral = frequenciaAgregada($faltasMes, $totalAlunos);
    $frequenciaMesAnterior = frequenciaAgregada($faltasMesAnterior, $totalAlunos);

    $atrasosMes = $contar($contarDiasAtraso, 0);
    $atrasosMesAnterior = $contar($contarDiasAtraso, 1);

    // Alunos "em observação": frequência entre 80% e 90% (abaixo de 80
    // já vira alerta de prioridade alta, então não conta duas vezes aqui).
    $stmtObs = $conexao->prepare("
        SELECT COALESCE(f.dias_falta, 0) AS qtd_faltas
        FROM tb_aluno al
        JOIN tb_sala sa ON sa.id_sala = al.id_sala
        LEFT JOIN (
            SELECT f.id_aluno, COUNT(DISTINCT $diaFalta) AS dias_falta
            FROM tb_falta f WHERE f.dta_falta >= ? GROUP BY f.id_aluno
        ) f ON f.id_aluno = al.id_aluno
        WHERE sa.id_escola = ?
    ");
    $stmtObs->execute([inicioDoMesUtc(), $idEscola]);
    $alunosObservacao = 0;
    foreach ($stmtObs->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $freq = calcularFrequencia((int) $linha['qtd_faltas']);
        if ($freq >= 80 && $freq < 90) $alunosObservacao++;
    }

    // Evolução mensal: últimos 6 meses (incluindo o atual).
    $evolucaoMensal = [];
    for ($i = 5; $i >= 0; $i--) {
        $evolucaoMensal[] = [
            "mes"        => inicioDoMesLocal($i),
            "frequencia" => frequenciaAgregada($contar($contarDiasFalta, $i), $totalAlunos),
        ];
    }

    // Frequência por turma no mês atual - só as turmas desta escola.
    $stmtTurmas = $conexao->prepare("
        SELECT sa.id_sala, sa.nome_sala, COUNT(al.id_aluno) AS total_alunos, COALESCE(SUM(sub.dias_falta), 0) AS total_faltas
        FROM tb_sala sa
        LEFT JOIN tb_aluno al ON al.id_sala = sa.id_sala
        LEFT JOIN (
            SELECT f.id_aluno, COUNT(DISTINCT $diaFalta) AS dias_falta
            FROM tb_falta f WHERE f.dta_falta >= ? GROUP BY f.id_aluno
        ) sub ON sub.id_aluno = al.id_aluno
        WHERE sa.id_escola = ?
        GROUP BY sa.id_sala, sa.nome_sala
        ORDER BY sa.nome_sala
    ");
    $stmtTurmas->execute([inicioDoMesUtc(), $idEscola]);
    $turmas = [];
    foreach ($stmtTurmas->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $turmas[] = [
            "id_sala"      => (int) $t['id_sala'],
            "nome_sala"    => $t['nome_sala'],
            "total_alunos" => (int) $t['total_alunos'],
            "frequencia"   => frequenciaAgregada((int) $t['total_faltas'], (int) $t['total_alunos']),
        ];
    }

    $alertas = buscarAlertas($conexao, $idEscola);
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
        $stmtAcomp->execute([...$idsComAlerta, inicioDoDiaUtc(7)]);
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
