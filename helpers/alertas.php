<?php
require_once __DIR__ . '/indicadores.php';

/**
 * Lista de alertas (alunos que precisam de atenção), compartilhada entre
 * user/alertas.php, user/dashboard.php e relatorio/exportarXml.php.
 *
 * Um alerta por aluno e por tipo - "atrasos" (entradas atrasadas) ou
 * "saidas" (saídas antecipadas) - quando a taxa dele na janela da escola
 * (padrão: últimos 30 dias) chega à faixa de helpers/indicadores.php:
 *   baixa - metade do limite (padrão 10% dos dias de aula, 2 ocorrências);
 *   media - o limite (padrão 20%, pelo menos 3): o responsável é avisado;
 *   alta  - o dobro do limite.
 *
 * Os alertas por falta e por "frequência" saíram junto com as faltas: o
 * BioFrequência registra entradas e saídas fora do horário; a frequência
 * fica na Sala do Futuro.
 *
 * "Encerrar" um alerta (tb_alerta_encerrado) é por ALUNO: suprime os
 * alertas dele até aparecer uma ocorrência num dia depois do encerramento
 * (comparação por DATA, no fuso da escola).
 */
function buscarAlertas(PDO $conexao, int $idEscola, ?array $config = null, ?array $janela = null): array {
    $config = $config ?? configuracaoDaEscola($conexao, $idEscola);
    $janela = $janela ?? indicadoresDaJanela($conexao, $idEscola, $config);

    $encerrados = [];
    $stmtEncerrados = $conexao->prepare("
        SELECT e.id_aluno, " . sqlDataNaEscola('e.encerrado_em') . "::text AS encerrado_em
        FROM tb_alerta_encerrado e
        JOIN tb_aluno a ON a.id_aluno = e.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE s.id_escola = ?
    ");
    $stmtEncerrados->execute([$idEscola]);
    foreach ($stmtEncerrados->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $encerrados[(int) $linha['id_aluno']] = $linha['encerrado_em'];
    }

    $tipos = [
        'atrasos' => ['prefixo' => 'atr', 'singular' => 'entrada atrasada', 'plural' => 'entradas atrasadas', 'ultimo' => 'ultimo_atraso'],
        'saidas'  => ['prefixo' => 'sai', 'singular' => 'saída antecipada', 'plural' => 'saídas antecipadas', 'ultimo' => 'ultima_saida'],
    ];

    $alertas = [];
    foreach ($janela['alunos'] as $idAluno => $indicador) {
        $encerradoEm = $encerrados[$idAluno] ?? null;
        foreach ($tipos as $tipo => $t) {
            $prioridade = $indicador["prioridade_$tipo"];
            if ($prioridade === null) {
                continue;
            }
            $referencia = $indicador[$t['ultimo']];
            if ($encerradoEm !== null && $encerradoEm >= $referencia) {
                continue;
            }
            $ocorrencias = $indicador[$tipo];
            $alertas[] = [
                "id_alerta"      => "{$t['prefixo']}-$idAluno",
                "id_aluno"       => $idAluno,
                "nome_aluno"     => $indicador['nome_aluno'],
                "id_sala"        => $indicador['id_sala'],
                "turma"          => $indicador['nome_sala'],
                "tipo"           => $tipo,
                "prioridade"     => $prioridade,
                "ocorrencias"    => $ocorrencias,
                "dias_letivos"   => $indicador['dias_letivos'],
                "taxa"           => $indicador["taxa_$tipo"],
                "motivo"         => sprintf(
                    '%d %s em %d dias de aula (%s%%) nos últimos %d dias. Limite da escola: %d%%.',
                    $ocorrencias,
                    $ocorrencias === 1 ? $t['singular'] : $t['plural'],
                    $indicador['dias_letivos'],
                    numeroBr($indicador["taxa_$tipo"]),
                    $config['janela_alerta_dias'],
                    $config['limite_alerta_percentual']
                ),
                "dta_referencia" => $referencia,
            ];
        }
    }

    $pesoPrioridade = ['alta' => 0, 'media' => 1, 'baixa' => 2];
    usort($alertas, function ($a, $b) use ($pesoPrioridade) {
        $peso = $pesoPrioridade[$a['prioridade']] <=> $pesoPrioridade[$b['prioridade']];
        return $peso !== 0 ? $peso : (($b['taxa'] ?? 0) <=> ($a['taxa'] ?? 0));
    });

    return $alertas;
}
