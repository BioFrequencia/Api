<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/indicadores.php';
require_once __DIR__ . '/../helpers/alertas.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Dashboard da escola de quem está logado.
 *
 *   pontualidade   % dos dias de aula do mês (até hoje) sem entrada
 *                  atrasada, somando os alunos - o gráfico radial. Null
 *                  quando o mês ainda não teve dia de aula.
 *   evolucao       entradas atrasadas e saídas antecipadas de cada mês
 *                  (dias distintos por aluno, só em dia letivo), dos
 *                  últimos 6 meses - mas só a partir do mês do primeiro
 *                  registro da escola: antes disso o sistema não era
 *                  usado, e mostrar "0 atrasos" seria inventar dado.
 *   turmas         pontualidade e ocorrências de cada turma no mês.
 *   fora_do_calendario  registros antigos (anteriores à validação) num
 *                  sábado, domingo, feriado ou dia sem aula: não entram
 *                  em nenhuma conta, e o site avisa quantos são.
 *
 * As regras das porcentagens estão em helpers/indicadores.php.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $config = configuracaoDaEscola($conexao, $idEscola);
    $hoje = hojeNaEscola();
    $meses = [];
    for ($i = 5; $i >= 0; $i--) {
        $inicio = inicioDoMesLocal($i);
        $fim = (new DateTimeImmutable($inicio))->modify('last day of this month')->format('Y-m-d');
        $meses[] = ['inicio' => $inicio, 'fim' => min($fim, $hoje)];
    }
    $inicioPeriodo = $meses[0]['inicio'];

    $calendario = calendarioDaEscola($conexao, $idEscola, $inicioPeriodo, $hoje);
    $alunos = alunosDaEscola($conexao, $idEscola);
    $atrasos = datasDeRegistros($conexao, 'atraso', $idEscola, $inicioPeriodo, $hoje);
    $saidas = datasDeRegistros($conexao, 'saida', $idEscola, $inicioPeriodo, $hoje);

    // Primeiro mês com algum registro (entrada atrasada, saída ou presença).
    $diaPresenca = 'p.dia_letivo';
    $stmtPrimeiro = $conexao->prepare("
        SELECT MIN(dia)::text FROM (
            SELECT MIN(" . sqlDataNaEscola('t.dta_atraso') . ") AS dia FROM tb_atraso t
                JOIN tb_aluno a ON a.id_aluno = t.id_aluno JOIN tb_sala s ON s.id_sala = a.id_sala WHERE s.id_escola = ?
            UNION ALL
            SELECT MIN(" . sqlDataNaEscola('r.dta_saida') . ") FROM tb_saida_antecipada r
                JOIN tb_aluno a ON a.id_aluno = r.id_aluno JOIN tb_sala s ON s.id_sala = a.id_sala WHERE s.id_escola = ?
            UNION ALL
            SELECT MIN($diaPresenca) FROM tb_presenca p
                JOIN tb_aluno a ON a.id_aluno = p.id_aluno JOIN tb_sala s ON s.id_sala = a.id_sala WHERE s.id_escola = ?
        ) x
    ");
    $stmtPrimeiro->execute([$idEscola, $idEscola, $idEscola]);
    $primeiroRegistro = $stmtPrimeiro->fetchColumn() ?: null;

    $filtrarMes = fn(array $porAluno, string $inicio, string $fim) => array_map(
        fn(array $datas) => array_values(array_filter($datas, fn($dia) => $dia >= $inicio && $dia <= $fim)),
        $porAluno
    );

    $evolucao = [];
    $resumoPorMes = [];
    $foraDoCalendario = 0;
    foreach ($meses as $mes) {
        $diasLetivos = diasLetivosEntre($mes['inicio'], $mes['fim'], $calendario);
        $atrasosMes = $filtrarMes($atrasos, $mes['inicio'], $mes['fim']);
        $saidasMes = $filtrarMes($saidas, $mes['inicio'], $mes['fim']);
        $resumo = resumoDoPeriodo($alunos, $diasLetivos, $atrasosMes, $saidasMes);
        $resumoPorMes[] = $resumo;

        $todas = array_sum(array_map('count', $atrasosMes)) + array_sum(array_map('count', $saidasMes));
        $foraDoCalendario += $todas - $resumo['atrasos'] - $resumo['saidas'];

        if ($primeiroRegistro !== null && substr($mes['inicio'], 0, 7) >= substr($primeiroRegistro, 0, 7)) {
            $evolucao[] = [
                "mes"          => $mes['inicio'],
                "atrasos"      => $resumo['atrasos'],
                "saidas"       => $resumo['saidas'],
                "dias_letivos" => $resumo['dias_letivos'],
                "pontualidade" => $resumo['pontualidade'],
            ];
        }
    }
    $mesAtual = $resumoPorMes[5];
    $mesAnterior = $resumoPorMes[4];

    $turmas = [];
    $stmtTurmas = $conexao->prepare("SELECT id_sala, nome_sala, periodo_sala FROM tb_sala WHERE id_escola = ? ORDER BY nome_sala");
    $stmtTurmas->execute([$idEscola]);
    $diasLetivosMes = diasLetivosEntre($meses[5]['inicio'], $meses[5]['fim'], $calendario);
    $atrasosMesAtual = $filtrarMes($atrasos, $meses[5]['inicio'], $meses[5]['fim']);
    $saidasMesAtual = $filtrarMes($saidas, $meses[5]['inicio'], $meses[5]['fim']);
    foreach ($stmtTurmas->fetchAll(PDO::FETCH_ASSOC) as $turma) {
        $daTurma = array_values(array_filter($alunos, fn($a) => (int) $a['id_sala'] === (int) $turma['id_sala']));
        $resumo = resumoDoPeriodo($daTurma, $diasLetivosMes, $atrasosMesAtual, $saidasMesAtual);
        $turmas[] = [
            "id_sala"      => (int) $turma['id_sala'],
            "nome_sala"    => $turma['nome_sala'],
            "periodo_sala" => $turma['periodo_sala'],
            "total_alunos" => count($daTurma),
            "atrasos"      => $resumo['atrasos'],
            "saidas"       => $resumo['saidas'],
            "pontualidade" => $resumo['pontualidade'],
        ];
    }

    $janela = indicadoresDaJanela($conexao, $idEscola, $config, $hoje);
    $alertas = buscarAlertas($conexao, $idEscola, $config, $janela);
    $acimaDoLimite = count(array_unique(array_column(
        array_filter($alertas, fn($a) => in_array($a['prioridade'], ['media', 'alta'], true)), 'id_aluno'
    )));

    // Contatos pendentes: alunos com alerta e sem acompanhamento nos últimos 7 dias.
    $idsComAlerta = array_values(array_unique(array_column($alertas, 'id_aluno')));
    $contatosPendentes = count($idsComAlerta);
    if (!empty($idsComAlerta)) {
        $stmtAcomp = $conexao->prepare("
            SELECT DISTINCT id_aluno FROM tb_acompanhamento
            WHERE id_aluno = ANY(?::int[]) AND data_registro >= ?
        ");
        $stmtAcomp->execute(['{' . implode(',', $idsComAlerta) . '}', inicioDoDiaUtc(7)]);
        $contatosPendentes = count(array_diff($idsComAlerta, array_map('intval', $stmtAcomp->fetchAll(PDO::FETCH_COLUMN))));
    }

    // Alunos cuja família recebeu (ou tem na fila) o aviso por limite neste mês.
    $stmtAvisos = $conexao->prepare("
        SELECT COUNT(DISTINCT l.id_aluno)
        FROM tb_aviso_limite l
        JOIN tb_aluno a ON a.id_aluno = l.id_aluno JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE s.id_escola = ? AND l.enviado_em >= ?
    ");
    $stmtAvisos->execute([$idEscola, inicioDoMesUtc()]);

    $situacaoHoje = situacaoDoDia($hoje, $calendario);

    enviarSucesso("Dashboard carregado com sucesso.", [
        "mes"                       => $meses[5]['inicio'],
        "hoje"                      => ["data" => $hoje] + $situacaoHoje,
        "pontualidade"              => $mesAtual['pontualidade'],
        "pontualidade_anterior"     => $mesAnterior['pontualidade'],
        "delta_pontualidade"        => $mesAtual['pontualidade'] !== null && $mesAnterior['pontualidade'] !== null
                                        ? round($mesAtual['pontualidade'] - $mesAnterior['pontualidade'], 1) : null,
        "dias_letivos_mes"          => $mesAtual['dias_letivos'],
        "aluno_dias_mes"            => $mesAtual['aluno_dias'],
        "total_alunos"              => count($alunos),
        "atrasos_mes"               => $mesAtual['atrasos'],
        "delta_atrasos"             => $mesAtual['atrasos'] - $mesAnterior['atrasos'],
        "saidas_mes"                => $mesAtual['saidas'],
        "delta_saidas"              => $mesAtual['saidas'] - $mesAnterior['saidas'],
        "alertas_ativos"            => count($alertas),
        "alertas_alta_prioridade"   => count(array_filter($alertas, fn($a) => $a['prioridade'] === 'alta')),
        "alunos_acima_limite"       => $acimaDoLimite,
        "responsaveis_avisados_mes" => (int) $stmtAvisos->fetchColumn(),
        "contatos_pendentes"        => $contatosPendentes,
        "limite_alerta"             => $config['limite_alerta_percentual'],
        "janela_alerta_dias"        => $config['janela_alerta_dias'],
        "evolucao_mensal"           => $evolucao,
        "turmas"                    => $turmas,
        "fora_do_calendario"        => $foraDoCalendario,
        "prioridade_lista"          => array_slice($alertas, 0, 5),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar o dashboard.", 500, $erro);
}
