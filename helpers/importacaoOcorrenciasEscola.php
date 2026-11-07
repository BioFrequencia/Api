<?php
/**
 * O banco da importação de entradas atrasadas e saídas antecipadas (as
 * regras puras ficam em helpers/importacaoOcorrencias.php): os alunos da
 * escola, o que já está registrado nos dias da planilha, o calendário e a
 * tolerância - e a gravação, pela mesma função do lançamento manual.
 */

require_once __DIR__ . '/importacaoOcorrencias.php';
require_once __DIR__ . '/registroEvento.php';

/** Alunos da escola, por turma e nome: id => ['nome', 'turma', 'periodo', 'inicio', 'fim']. */
function alunosDaEscolaParaOcorrencias(PDO $conexao, int $idEscola): array {
    $busca = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, s.nome_sala, s.periodo_sala,
               to_char(s.horario_inicio, 'HH24:MI') AS inicio,
               to_char(s.horario_fim, 'HH24:MI') AS fim
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE s.id_escola = ?
        ORDER BY s.nome_sala, a.nome_aluno, a.id_aluno
    ");
    $busca->execute([$idEscola]);
    $alunos = [];
    foreach ($busca->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $alunos[(int) $linha['id_aluno']] = [
            'nome' => $linha['nome_aluno'],
            'turma' => $linha['nome_sala'],
            'periodo' => $linha['periodo_sala'],
            'inicio' => $linha['inicio'],
            'fim' => $linha['fim'],
        ];
    }
    return $alunos;
}

/**
 * O que a prévia precisa saber da escola (formato em
 * montarPreviaDeOcorrencias): só os registros e o calendário entre a
 * primeira e a última data da planilha.
 */
function dadosDaEscolaParaOcorrencias(PDO $conexao, int $idEscola, array $linhas): array {
    $alunos = alunosDaEscolaParaOcorrencias($conexao, $idEscola);
    $datas = [];
    foreach ($linhas as [, $valores]) {
        $data = lerDataDaCelula($valores['data'] ?? null, true);
        if ($data !== null) $datas[] = $data;
    }

    $registros = [];
    $calendario = [];
    if ($datas !== [] && $alunos !== []) {
        $inicio = min($datas);
        $fim = max($datas);
        foreach (EVENTOS_FREQUENCIA as $tipo => $c) {
            $dia = sqlDataNaEscola("r.{$c['data']}");
            $busca = $conexao->prepare("
                SELECT r.id_aluno, {$dia}::text AS dia,
                       to_char((r.{$c['data']}) AT TIME ZONE 'UTC' AT TIME ZONE '" . FUSO_ESCOLA . "', 'HH24:MI') AS hora
                FROM {$c['tabela']} r
                JOIN tb_aluno a ON a.id_aluno = r.id_aluno
                JOIN tb_sala s ON s.id_sala = a.id_sala
                WHERE s.id_escola = ? AND {$dia} BETWEEN ?::date AND ?::date
                ORDER BY r.{$c['id']}
            ");
            $busca->execute([$idEscola, $inicio, $fim]);
            foreach ($busca->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $registros["{$tipo}|{$r['id_aluno']}|{$r['dia']}"] ??= $r['hora'];
            }
        }
        $calendario = calendarioDaEscola($conexao, $idEscola, $inicio, $fim);
    }

    return [
        'alunos' => $alunos,
        'registros' => $registros,
        'calendario' => $calendario,
        'tolerancia' => (int) configuracaoDaEscola($conexao, $idEscola)['tolerancia_minutos'],
    ];
}

/** Agora no fuso da escola ('Y-m-d H:i'), para a prévia recusar o futuro. */
function agoraNaEscolaParaPrevia(): string {
    return agoraUtc()->setTimezone(fusoEscola())->format('Y-m-d H:i');
}

/**
 * Grava as linhas 'nova' da prévia - DENTRO da transação de quem chama -
 * pela mesma função do lançamento manual, que trava cada aluno e não
 * duplica o dia. Entrada atrasada importada é lançamento da coordenação
 * (origem 'manual'). Devolve as contagens e os alunos que ganharam
 * registro (para o aviso de limite).
 */
function gravarOcorrenciasImportadas(PDO $conexao, int $idCoordenacao, array $previa): array {
    $resultado = ['atrasos_criados' => 0, 'saidas_criadas' => 0, 'alunos' => []];
    foreach ($previa['linhas'] as $linha) {
        if ($linha['situacao'] !== 'nova') continue;
        $extras = $linha['tipo'] === 'atraso' ? ['origem' => 'manual'] : ['retirado_por' => $linha['retirado_por']];
        $gravado = registrarEventoFrequencia(
            $conexao, $linha['tipo'], (int) $linha['id_aluno'], $idCoordenacao, localParaUtc("{$linha['data']} {$linha['hora']}"),
            $linha['motivo'], $linha['observacao'], $linha['justificado'], $extras
        );
        if ($gravado['ja_existia']) continue;
        $resultado[$linha['tipo'] === 'atraso' ? 'atrasos_criados' : 'saidas_criadas']++;
        $resultado['alunos'][(int) $linha['id_aluno']] = true;
    }
    $resultado['alunos'] = array_keys($resultado['alunos']);
    return $resultado;
}
