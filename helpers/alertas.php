<?php
require_once __DIR__ . '/frequencia.php';
require_once __DIR__ . '/tempo.php';

/**
 * Monta a lista de alertas (alunos que precisam de atenção) a partir de
 * faltas/atrasos reais. Compartilhada entre user/alertas.php,
 * user/dashboard.php e relatorio/exportarXml.php.
 *
 * Critérios usados (não havia nenhuma regra de negócio formalmente
 * especificada para isso - são os limiares mais diretos derivados da
 * tela de protótipo fornecida; ajustar se a coordenação pedagógica
 * definir outros):
 *  - tipo "frequencia": calcularStatusFrequencia() = "Em risco"
 *    (frequência < 85%) -> prioridade alta; "Atenção" (85-89%) -> média.
 *  - tipo "faltas": faltas consecutivas (calcularFaltasConsecutivas) >= 5
 *    -> alta; >= 3 -> média.
 *  - tipo "atrasos": dias com atraso nos últimos 7 dias >= 4 -> média;
 *    >= 2 -> baixa.
 * Um aluno pode gerar mais de um alerta ao mesmo tempo (ex.: frequência
 * baixa E muitos atrasos) - cada um vira uma linha separada, como na
 * tela de protótipo (cada card é um alerta, não um aluno).
 *
 * Contagem por DIA, no fuso da escola: duas faltas lançadas para o
 * mesmo aluno no mesmo dia (clique duplo, ou lançamento manual + envio
 * do app aprovado) são um dia de ausência, não dois. Produção já tinha
 * dois atrasos do mesmo aluno com 26 segundos de diferença.
 *
 * "Encerrar" um alerta (tb_alerta_encerrado) é por ALUNO, não por tipo:
 * simplificação deliberada para não multiplicar tabelas. Encerrar
 * suprime os alertas do aluno pelo resto do dia corrente; a comparação é
 * por DATA (não timestamp completo) de propósito - um novo evento no dia
 * seguinte (ou depois) faz o alerta correspondente reaparecer sozinho,
 * sem precisar de nenhuma ação manual.
 */
function buscarAlertas(PDO $conexao, int $idEscola): array {
    $inicioMes = inicioDoMesUtc();
    $seteDiasAtras = inicioDoDiaUtc(7);
    $sessentaDiasAtras = inicioDoDiaUtc(60);
    $hoje = hojeNaEscola();
    $diaFalta = sqlDataNaEscola('f.dta_falta');
    $diaAtraso = sqlDataNaEscola('t.dta_atraso');

    $stmt = $conexao->prepare("
        SELECT al.id_aluno, al.nome_aluno, s.id_sala, s.nome_sala,
               COALESCE(f.dias_falta, 0) AS qtd_faltas
        FROM tb_aluno al
        JOIN tb_sala s ON s.id_sala = al.id_sala
        LEFT JOIN (
            SELECT f.id_aluno, COUNT(DISTINCT $diaFalta) AS dias_falta
            FROM tb_falta f
            WHERE f.dta_falta >= ?
            GROUP BY f.id_aluno
        ) f ON f.id_aluno = al.id_aluno
        WHERE s.id_escola = ?
    ");
    $stmt->execute([$inicioMes, $idEscola]);
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Datas de falta (para calcular consecutivas) e atrasos recentes,
    // buscados à parte, uma query cada para a escola inteira de uma vez -
    // evita N+1 (uma query por aluno). Restritas à escola: antes liam as
    // faltas de todas as escolas a cada abertura da tela.
    $datasFaltaPorAluno = [];
    $stmtFaltas = $conexao->prepare("
        SELECT DISTINCT f.id_aluno, $diaFalta AS d
        FROM tb_falta f
        JOIN tb_aluno a ON a.id_aluno = f.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE s.id_escola = ? AND f.dta_falta >= ?
        ORDER BY f.id_aluno, d DESC
    ");
    $stmtFaltas->execute([$idEscola, $sessentaDiasAtras]);
    foreach ($stmtFaltas->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $datasFaltaPorAluno[(int) $linha['id_aluno']][] = $linha['d'];
    }

    $atrasosRecentesPorAluno = [];
    $stmtAtrasos = $conexao->prepare("
        SELECT t.id_aluno, COUNT(DISTINCT $diaAtraso) AS qtd, MAX($diaAtraso) AS ultimo
        FROM tb_atraso t
        JOIN tb_aluno a ON a.id_aluno = t.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE s.id_escola = ? AND t.dta_atraso >= ?
        GROUP BY t.id_aluno
    ");
    $stmtAtrasos->execute([$idEscola, $seteDiasAtras]);
    foreach ($stmtAtrasos->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $atrasosRecentesPorAluno[(int) $linha['id_aluno']] = $linha;
    }

    // Normalizado para data (sem hora), no fuso da escola: "encerrar" um
    // alerta suprime até o FIM do dia em que foi encerrado - comparar
    // timestamp completo com uma data quebraria a comparação de string
    // sempre que os dois caíssem no mesmo dia (bug real encontrado em
    // teste numa rodada anterior).
    $encerrados = [];
    $stmtEncerrados = $conexao->prepare("
        SELECT e.id_aluno, " . sqlDataNaEscola('e.encerrado_em') . " AS encerrado_em
        FROM tb_alerta_encerrado e
        JOIN tb_aluno a ON a.id_aluno = e.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE s.id_escola = ?
    ");
    $stmtEncerrados->execute([$idEscola]);
    foreach ($stmtEncerrados->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $encerrados[(int) $linha['id_aluno']] = $linha['encerrado_em'];
    }

    $alertas = [];
    foreach ($alunos as $aluno) {
        $idAluno = (int) $aluno['id_aluno'];
        $qtdFaltas = (int) $aluno['qtd_faltas'];
        $frequencia = calcularFrequencia($qtdFaltas);
        $status = calcularStatusFrequencia($frequencia);
        $encerradoEm = $encerrados[$idAluno] ?? null;
        $datasFalta = $datasFaltaPorAluno[$idAluno] ?? [];
        $base = [
            "id_aluno"   => $idAluno,
            "nome_aluno" => $aluno['nome_aluno'],
            "id_sala"    => (int) $aluno['id_sala'],
            "turma"      => $aluno['nome_sala'],
            "frequencia" => $frequencia,
        ];

        if ($status === 'Em risco' || $status === 'Atenção') {
            $ultimaReferencia = $datasFalta[0] ?? $hoje;
            if ($encerradoEm === null || $encerradoEm < $ultimaReferencia) {
                $alertas[] = ["id_alerta" => "freq-$idAluno"] + $base + [
                    "tipo"           => "frequencia",
                    "prioridade"     => $status === 'Em risco' ? 'alta' : 'media',
                    "motivo"         => "Frequência em {$frequencia}%, classificada como \"$status\".",
                    "dta_referencia" => $ultimaReferencia,
                ];
            }
        }

        $consecutivas = calcularFaltasConsecutivas($datasFalta, $hoje);
        if ($consecutivas >= 3) {
            $ultimaReferencia = $datasFalta[0];
            if ($encerradoEm === null || $encerradoEm < $ultimaReferencia) {
                $alertas[] = ["id_alerta" => "falt-$idAluno"] + $base + [
                    "tipo"           => "faltas",
                    "prioridade"     => $consecutivas >= 5 ? 'alta' : 'media',
                    "motivo"         => "$consecutivas faltas consecutivas registradas.",
                    "dta_referencia" => $ultimaReferencia,
                ];
            }
        }

        $atrasoInfo = $atrasosRecentesPorAluno[$idAluno] ?? null;
        if ($atrasoInfo !== null && (int) $atrasoInfo['qtd'] >= 2) {
            $qtdAtrasos = (int) $atrasoInfo['qtd'];
            $ultimaReferencia = $atrasoInfo['ultimo'];
            if ($encerradoEm === null || $encerradoEm < $ultimaReferencia) {
                $alertas[] = ["id_alerta" => "atr-$idAluno"] + $base + [
                    "tipo"           => "atrasos",
                    "prioridade"     => $qtdAtrasos >= 4 ? 'media' : 'baixa',
                    "motivo"         => "Atrasos em $qtdAtrasos dias nos últimos 7 dias.",
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
