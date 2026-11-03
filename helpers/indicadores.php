<?php
require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/configuracaoEscola.php';

/**
 * Indicadores de entradas atrasadas e saídas antecipadas - a base do
 * dashboard, dos alertas, da lista de alunos e dos avisos aos
 * responsáveis.
 *
 * Por que mudou: a "frequência" de antes era 100% menos as faltas sobre
 * um mês fixo de 20 dias. Com as faltas fora do sistema (ficam na Sala
 * do Futuro), meses sem nenhum uso apareciam como 100%, o mês corrente
 * era dividido por 20 dias que ainda não tinham acontecido e registros em
 * sábados contavam - os "dados falsos" que a equipe encontrou.
 *
 * Agora toda porcentagem tem um denominador de verdade:
 *   dias de aula = dias letivos do período (helpers/calendario.php) que
 *                  já passaram, contados para cada aluno desde que ele
 *                  foi cadastrado;
 *   ocorrências  = DIAS (não linhas) com entrada atrasada ou saída
 *                  antecipada, só em dias letivos: um registro antigo num
 *                  sábado ou feriado não entra na conta.
 *
 *   taxa de atrasos = dias com entrada atrasada / dias de aula
 *   pontualidade    = 100% - taxa de atrasos (dias de aula sem atraso)
 *
 * Sem nenhum dia de aula no período a taxa é null ("ainda sem dados"),
 * nunca 100%.
 */

function taxaPercentual(int $ocorrencias, int $dias): ?float {
    if ($dias <= 0) {
        return null;
    }
    return round(100 * min($ocorrencias, $dias) / $dias, 1);
}

function pontualidadePercentual(int $diasComAtraso, int $diasDeAula): ?float {
    $taxa = taxaPercentual($diasComAtraso, $diasDeAula);
    return $taxa === null ? null : round(100 - $taxa, 1);
}

/**
 * Prioridade do alerta pela taxa do aluno (função pura):
 *   alta  - pelo menos o dobro do limite da escola;
 *   media - passou do limite (é quando o responsável é avisado);
 *   baixa - chegou à metade do limite: a coordenação pode agir antes.
 * Sempre com o mínimo de ocorrências da escola (na baixa, um a menos,
 * nunca menos de 2): um atraso em dois dias de aula não é "50%" de nada.
 */
function classificarTaxa(?float $taxa, int $ocorrencias, array $config): ?string {
    if ($taxa === null || $ocorrencias <= 0) {
        return null;
    }
    $limite = $config['limite_alerta_percentual'];
    $minimo = $config['minimo_ocorrencias_alerta'];
    if ($ocorrencias >= $minimo && $taxa >= min(100, 2 * $limite)) {
        return 'alta';
    }
    if ($ocorrencias >= $minimo && $taxa >= $limite) {
        return 'media';
    }
    if ($ocorrencias >= max(2, $minimo - 1) && $taxa >= $limite / 2) {
        return 'baixa';
    }
    return null;
}

/** Rótulo da situação do aluno pela pior prioridade entre atrasos e saídas. */
function situacaoDoAluno(?string $prioridadeAtrasos, ?string $prioridadeSaidas, int $diasDeAula): string {
    $peso = ['alta' => 3, 'media' => 2, 'baixa' => 1];
    $pior = max($peso[$prioridadeAtrasos] ?? 0, $peso[$prioridadeSaidas] ?? 0);
    if ($pior === 0) {
        return $diasDeAula > 0 ? 'Regular' : 'Sem dias de aula';
    }
    return [1 => 'Perto do limite', 2 => 'Acima do limite', 3 => 'Crítico'][$pior];
}

/**
 * Dias de aula de um aluno: os dias letivos a partir do cadastro dele.
 * Aluno sem data de cadastro (os que já existiam antes da migração 008)
 * conta o período todo.
 *
 * @param string[] $diasLetivos Y-m-d
 */
function diasDeAulaDoAluno(array $diasLetivos, ?string $cadastradoEm): int {
    if ($cadastradoEm === null || $cadastradoEm === '') {
        return count($diasLetivos);
    }
    return count(array_filter($diasLetivos, fn($dia) => $dia >= $cadastradoEm));
}

/**
 * Soma de um grupo de alunos num período (função pura): dias de aula de
 * cada um e dias com entrada atrasada / saída antecipada em dia letivo.
 *
 * @param array $alunos linhas com id_aluno e cadastrado_em
 * @param string[] $diasLetivos
 * @param array<int,string[]> $atrasos id_aluno => datas
 * @param array<int,string[]> $saidas  id_aluno => datas
 */
function resumoDoPeriodo(array $alunos, array $diasLetivos, array $atrasos, array $saidas): array {
    $letivos = array_flip($diasLetivos);
    $diasAluno = 0;
    $diasAtraso = 0;
    $diasSaida = 0;
    foreach ($alunos as $aluno) {
        $id = (int) $aluno['id_aluno'];
        $diasAluno += diasDeAulaDoAluno($diasLetivos, $aluno['cadastrado_em'] ?? null);
        $diasAtraso += count(array_filter($atrasos[$id] ?? [], fn($dia) => isset($letivos[$dia])));
        $diasSaida += count(array_filter($saidas[$id] ?? [], fn($dia) => isset($letivos[$dia])));
    }
    return [
        'dias_letivos' => count($diasLetivos),
        'aluno_dias'   => $diasAluno,
        'atrasos'      => $diasAtraso,
        'saidas'       => $diasSaida,
        'pontualidade' => pontualidadePercentual($diasAtraso, $diasAluno),
    ];
}

/** "22,5" - número com vírgula, como se escreve para a família. */
function numeroBr(?float $valor): string {
    if ($valor === null) {
        return '-';
    }
    return str_replace('.', ',', (string) (floor($valor) == $valor ? (int) $valor : $valor));
}

function diaSeguinte(string $data): string {
    return (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d');
}

/**
 * Alunos da escola (ou de uma turma, ou um só) com a data local do
 * cadastro.
 */
function alunosDaEscola(PDO $conexao, int $idEscola, ?int $idSala = null, ?int $idAluno = null): array {
    $stmt = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala, s.periodo_sala,
               " . sqlDataNaEscola('a.criado_em') . "::text AS cadastrado_em
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE s.id_escola = ?
          AND (?::int IS NULL OR s.id_sala = ?::int)
          AND (?::int IS NULL OR a.id_aluno = ?::int)
        ORDER BY a.nome_aluno
    ");
    $stmt->bindValue(1, $idEscola);
    $stmt->bindValue(2, $idSala);
    $stmt->bindValue(3, $idSala);
    $stmt->bindValue(4, $idAluno);
    $stmt->bindValue(5, $idAluno);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Datas (Y-m-d, fuso da escola) com entrada atrasada ('atraso') ou saída
 * antecipada ('saida') de cada aluno da escola entre $inicio e $fim
 * (inclusive), uma por dia.
 *
 * @return array<int,string[]> id_aluno => datas em ordem decrescente
 */
function datasDeRegistros(PDO $conexao, string $tipo, int $idEscola, string $inicio, string $fim, ?int $idAluno = null): array {
    [$tabela, $coluna] = $tipo === 'atraso' ? ['tb_atraso', 'dta_atraso'] : ['tb_saida_antecipada', 'dta_saida'];
    $dia = sqlDataNaEscola("r.$coluna");
    $stmt = $conexao->prepare("
        SELECT DISTINCT r.id_aluno, $dia::text AS dia
        FROM $tabela r
        JOIN tb_aluno a ON a.id_aluno = r.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE s.id_escola = ? AND r.$coluna >= ? AND r.$coluna < ?
          AND (?::int IS NULL OR r.id_aluno = ?::int)
        ORDER BY dia DESC
    ");
    $stmt->bindValue(1, $idEscola);
    $stmt->bindValue(2, localParaUtc($inicio));
    $stmt->bindValue(3, localParaUtc(diaSeguinte($fim)));
    $stmt->bindValue(4, $idAluno);
    $stmt->bindValue(5, $idAluno);
    $stmt->execute();
    $porAluno = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $porAluno[(int) $linha['id_aluno']][] = $linha['dia'];
    }
    return $porAluno;
}

/**
 * Indicadores de cada aluno na janela de alerta da escola (os últimos N
 * dias até hoje): dias de aula, dias com entrada atrasada e com saída
 * antecipada, as taxas e a prioridade de cada uma.
 *
 * @return array{inicio: string, fim: string, dias_letivos: int, alunos: array<int, array>}
 */
function indicadoresDaJanela(PDO $conexao, int $idEscola, array $config, ?string $hoje = null, ?int $idSala = null, ?int $idAluno = null): array {
    $hoje = $hoje ?? hojeNaEscola();
    $inicio = (new DateTimeImmutable($hoje))->modify('-' . ($config['janela_alerta_dias'] - 1) . ' days')->format('Y-m-d');
    $diasLetivos = diasLetivosEntre($inicio, $hoje, calendarioDaEscola($conexao, $idEscola, $inicio, $hoje));
    $letivos = array_flip($diasLetivos);
    $atrasos = datasDeRegistros($conexao, 'atraso', $idEscola, $inicio, $hoje, $idAluno);
    $saidas = datasDeRegistros($conexao, 'saida', $idEscola, $inicio, $hoje, $idAluno);
    // Só os dias letivos (as datas já vêm em ordem decrescente).
    $emDiaLetivo = fn(array $datas) => array_values(array_filter($datas, fn($dia) => isset($letivos[$dia])));

    $alunos = [];
    foreach (alunosDaEscola($conexao, $idEscola, $idSala, $idAluno) as $aluno) {
        $id = (int) $aluno['id_aluno'];
        $dias = diasDeAulaDoAluno($diasLetivos, $aluno['cadastrado_em']);
        $datasAtraso = $emDiaLetivo($atrasos[$id] ?? []);
        $datasSaida = $emDiaLetivo($saidas[$id] ?? []);
        $taxaAtrasos = taxaPercentual(count($datasAtraso), $dias);
        $taxaSaidas = taxaPercentual(count($datasSaida), $dias);
        $alunos[$id] = [
            'id_aluno'           => $id,
            'nome_aluno'         => $aluno['nome_aluno'],
            'id_sala'            => (int) $aluno['id_sala'],
            'nome_sala'          => $aluno['nome_sala'],
            'dias_letivos'       => $dias,
            'atrasos'            => count($datasAtraso),
            'saidas'             => count($datasSaida),
            'taxa_atrasos'       => $taxaAtrasos,
            'taxa_saidas'        => $taxaSaidas,
            'prioridade_atrasos' => classificarTaxa($taxaAtrasos, count($datasAtraso), $config),
            'prioridade_saidas'  => classificarTaxa($taxaSaidas, count($datasSaida), $config),
            'ultimo_atraso'      => $datasAtraso[0] ?? null,
            'ultima_saida'       => $datasSaida[0] ?? null,
        ];
    }
    return ['inicio' => $inicio, 'fim' => $hoje, 'dias_letivos' => count($diasLetivos), 'alunos' => $alunos];
}
