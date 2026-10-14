<?php
require_once __DIR__ . '/frequencia.php';

/**
 * Monta a lista de alertas (alunos que precisam de atenção) a partir de
 * faltas/atrasos reais. Compartilhada entre user/alertas.php e
 * user/dashboard.php - antes não existia nenhum lugar que decidisse
 * "isso é um alerta, desse tipo, com esta prioridade" (só o cálculo de
 * frequência do mês, em filtroAluno.php).
 *
 * Critérios usados (não havia nenhuma regra de negócio formalmente
 * especificada para isso - são os limiares mais diretos derivados da
 * tela de protótipo fornecida; ajustar se a coordenação pedagógica
 * definir outros):
 *  - tipo "frequencia": calcularStatusFrequencia() = "Em risco"
 *    (frequência < 85%) -> prioridade alta; "Atenção" (85-89%) -> média.
 *  - tipo "faltas": faltas consecutivas (calcularFaltasConsecutivas) >= 5
 *    -> alta; >= 3 -> média.
 *  - tipo "atrasos": atrasos nos últimos 7 dias >= 4 -> média; >= 2 -> baixa.
 * Um aluno pode gerar mais de um alerta ao mesmo tempo (ex.: frequência
 * baixa E muitos atrasos) - cada um vira uma linha separada, como na
 * tela de protótipo (cada card é um alerta, não um aluno).
 *
 * "Encerrar" um alerta (tb_alerta_encerrado) é por ALUNO, não por tipo:
 * simplificação deliberada para não multiplicar tabelas nesta rodada.
 * Encerrar suprime os alertas do aluno pelo resto do dia corrente; a
 * comparação é por DATA (não timestamp completo) de propósito - um novo
 * evento no dia seguinte (ou depois) faz o alerta correspondente
 * reaparecer sozinho, sem precisar de nenhuma ação manual.
 */
function buscarAlertas(PDO $conexao, int $idEscola): array {
    $primeiroDiaMes = date('Y-m-01');
    $seteDiasAtras = date('Y-m-d', strtotime('-7 days'));
    $sessentaDiasAtras = date('Y-m-d', strtotime('-60 days'));

    $stmt = $conexao->prepare("
        SELECT al.id_aluno, al.nome_aluno, s.nome_sala,
               COALESCE(f.qtd_faltas, 0) AS qtd_faltas
        FROM tb_aluno al
        JOIN tb_sala s ON s.id_sala = al.id_sala
        LEFT JOIN (
            SELECT id_aluno, COUNT(*) AS qtd_faltas
            FROM tb_falta
            WHERE dta_falta >= ?
            GROUP BY id_aluno
        ) f ON f.id_aluno = al.id_aluno
        WHERE s.id_escola = ?
    ");
    $stmt->execute([$primeiroDiaMes, $idEscola]);
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Datas de falta (para calcular consecutivas) e atrasos recentes,
    // buscados à parte, uma query cada para todo mundo de uma vez -
    // evita N+1 (uma query por aluno).
    $datasFaltaPorAluno = [];
    $stmtFaltas = $conexao->prepare("SELECT id_aluno, dta_falta::date AS d FROM tb_falta WHERE dta_falta >= ? ORDER BY id_aluno, dta_falta DESC");
    $stmtFaltas->execute([$sessentaDiasAtras]);
    foreach ($stmtFaltas->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $datasFaltaPorAluno[(int)$linha['id_aluno']][] = $linha['d'];
    }

    $atrasosRecentesPorAluno = [];
    $stmtAtrasos = $conexao->prepare("SELECT id_aluno, COUNT(*) AS qtd, MAX(dta_atraso)::date AS ultimo FROM tb_atraso WHERE dta_atraso >= ? GROUP BY id_aluno");
    $stmtAtrasos->execute([$seteDiasAtras]);
    foreach ($stmtAtrasos->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $atrasosRecentesPorAluno[(int)$linha['id_aluno']] = $linha;
    }

    // Normalizado para data (sem hora): "encerrar" um alerta suprime até
    // o FIM do dia em que foi encerrado - comparar timestamp completo
    // (encerrado_em) com uma data (dta_falta::date/dta_atraso::date)
    // quebraria a comparação lexicográfica de string sempre que os dois
    // caíssem no mesmo dia (ex.: "2026-09-16 10:49" não é "<" que
    // "2026-09-16", então um novo evento no mesmo dia do encerramento
    // nunca fazia o alerta reaparecer - bug real encontrado em teste).
    $encerrados = [];
    foreach ($conexao->query("SELECT id_aluno, encerrado_em::date AS encerrado_em FROM tb_alerta_encerrado")->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $encerrados[(int)$linha['id_aluno']] = $linha['encerrado_em'];
    }

    $alertas = [];
    foreach ($alunos as $aluno) {
        $idAluno = (int)$aluno['id_aluno'];
        $qtdFaltas = (int)$aluno['qtd_faltas'];
        $frequencia = calcularFrequencia($qtdFaltas);
        $status = calcularStatusFrequencia($frequencia);
        $encerradoEm = $encerrados[$idAluno] ?? null;
        $datasFalta = $datasFaltaPorAluno[$idAluno] ?? [];

        if ($status === 'Em risco' || $status === 'Atenção') {
            $ultimaReferencia = $datasFalta[0] ?? date('Y-m-d');
            if ($encerradoEm === null || $encerradoEm < $ultimaReferencia) {
                $alertas[] = [
                    "id_alerta"   => "freq-$idAluno",
                    "id_aluno"    => $idAluno,
                    "nome_aluno"  => $aluno['nome_aluno'],
                    "turma"       => $aluno['nome_sala'],
                    "tipo"        => "frequencia",
                    "prioridade"  => $status === 'Em risco' ? 'alta' : 'media',
                    "motivo"      => "Frequência em {$frequencia}%, classificada como \"$status\".",
                    "frequencia"  => $frequencia,
                    "dta_referencia" => $ultimaReferencia,
                ];
            }
        }

        $consecutivas = calcularFaltasConsecutivas($datasFalta);
        if ($consecutivas >= 3) {
            $ultimaReferencia = $datasFalta[0];
            if ($encerradoEm === null || $encerradoEm < $ultimaReferencia) {
                $alertas[] = [
                    "id_alerta"   => "falt-$idAluno",
                    "id_aluno"    => $idAluno,
                    "nome_aluno"  => $aluno['nome_aluno'],
                    "turma"       => $aluno['nome_sala'],
                    "tipo"        => "faltas",
                    "prioridade"  => $consecutivas >= 5 ? 'alta' : 'media',
                    "motivo"      => "$consecutivas faltas consecutivas registradas.",
                    "frequencia"  => $frequencia,
                    "dta_referencia" => $ultimaReferencia,
                ];
            }
        }

        $atrasoInfo = $atrasosRecentesPorAluno[$idAluno] ?? null;
        if ($atrasoInfo !== null && (int)$atrasoInfo['qtd'] >= 2) {
            $qtdAtrasos = (int)$atrasoInfo['qtd'];
            $ultimaReferencia = $atrasoInfo['ultimo'];
            if ($encerradoEm === null || $encerradoEm < $ultimaReferencia) {
                $alertas[] = [
                    "id_alerta"   => "atr-$idAluno",
                    "id_aluno"    => $idAluno,
                    "nome_aluno"  => $aluno['nome_aluno'],
                    "turma"       => $aluno['nome_sala'],
                    "tipo"        => "atrasos",
                    "prioridade"  => $qtdAtrasos >= 4 ? 'media' : 'baixa',
                    "motivo"      => "$qtdAtrasos atrasos nos últimos 7 dias.",
                    "frequencia"  => $frequencia,
                    "dta_referencia" => $ultimaReferencia,
                ];
            }
        }
    }

    $pesoPrioridade = ['alta' => 0, 'media' => 1, 'baixa' => 2];
    usort($alertas, function ($a, $b) use ($pesoPrioridade) {
        $peso = $pesoPrioridade[$a['prioridade']] <=> $pesoPrioridade[$b['prioridade']];
        return $peso !== 0 ? $peso : ($a['frequencia'] <=> $b['frequencia']);
    });

    return $alertas;
}
