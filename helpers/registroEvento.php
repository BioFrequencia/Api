<?php
require_once __DIR__ . '/tempo.php';
require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/configuracaoEscola.php';

/**
 * Registro de entrada atrasada e de saída antecipada, compartilhado por
 * user/marcarAtraso.php, user/marcarSaida.php, a presença do app
 * (helpers/presenca.php) e ocorrencia/revisarOcorrencia.php - todos
 * gravam nas mesmas tabelas e precisam da mesma regra:
 *
 *  - O registro só existe num dia de aula e dentro do horário da turma
 *    (exigirHorarioDoRegistro, abaixo; as regras em helpers/calendario.php).
 *  - Um aluno tem no máximo UMA entrada atrasada e UMA saída antecipada
 *    por dia (no fuso da escola). Antes não havia trava nenhuma: clique
 *    duplo no botão, ou duas pessoas lançando ao mesmo tempo, gravavam
 *    duas linhas, e as porcentagens contavam o mesmo dia duas vezes.
 *  - A trava usa pg_advisory_xact_lock por aluno: duas requisições
 *    simultâneas para o mesmo aluno esperam uma pela outra, e a segunda
 *    enxerga a linha que a primeira gravou. É liberada sozinha no fim da
 *    transação (funciona com o pooler do Supabase em modo Transaction).
 *
 * registrarEventoFrequencia() precisa ser chamada DENTRO de uma transação.
 */

// Faltas não são mais registradas (ficam na Sala do Futuro): tb_falta
// continua no banco com os registros antigos, mas nada grava nela.
// 'extras' são as colunas próprias de cada tabela que quem chama pode
// preencher.
const EVENTOS_FREQUENCIA = [
    'atraso' => [
        'tabela' => 'tb_atraso', 'id' => 'id_atraso', 'data' => 'dta_atraso',
        'motivo' => 'motivo_atraso', 'justificado' => 'justificado_atraso',
        'observacao' => 'observacao_atraso', 'trava' => 7302, 'extras' => ['origem'],
    ],
    'saida' => [
        'tabela' => 'tb_saida_antecipada', 'id' => 'id_saida', 'data' => 'dta_saida',
        'motivo' => 'motivo_saida', 'justificado' => 'justificado_saida',
        'observacao' => 'observacao_saida', 'trava' => 7303, 'extras' => ['retirado_por'],
    ],
];

/**
 * Lê a data/hora opcional enviada pelo cliente (horário LOCAL da escola:
 * 'Y-m-d H:i' ou 'Y-m-d H:i:s') e devolve em UTC, ou null para "agora".
 * Encerra com 422 se vier num formato inválido, sem o horário ou no
 * futuro - antes qualquer texto ia direto para o banco e virava erro 500.
 *
 * O horário é obrigatório: é ele que diz se foi mesmo uma entrada
 * atrasada (depois da tolerância) ou uma saída antecipada (antes do fim
 * da aula). Só com a data, o registro virava meio-dia - fora do horário
 * das turmas da tarde e da noite.
 */
function lerMomentoDoEvento($valor): ?string {
    if ($valor === null || $valor === '') {
        return null;
    }
    if (dataIsoValida($valor)) {
        enviarErro("Informe também o horário (AAAA-MM-DD HH:MM).", 422);
    }
    $formatos = ['!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i', '!Y-m-d\TH:i:s'];
    $momento = false;
    foreach ($formatos as $formato) {
        $momento = is_string($valor) ? DateTimeImmutable::createFromFormat($formato, $valor, fusoEscola()) : false;
        if ($momento !== false && $momento->format(substr($formato, 1)) === $valor) {
            break;
        }
        $momento = false;
    }
    if ($momento === false) {
        enviarErro("Data inválida. Use o formato AAAA-MM-DD HH:MM.", 422);
    }
    if ($momento > agoraUtc()->modify('+5 minutes')) {
        enviarErro("Não é possível registrar uma ocorrência no futuro.", 422);
    }
    return $momento->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Turma do aluno, com o horário (para as regras de calendario.php). */
function turmaDoAluno(PDO $conexao, int $idAluno): ?array {
    $stmt = $conexao->prepare("
        SELECT a.nome_aluno, s.id_sala, s.nome_sala, s.periodo_sala, s.id_escola,
               to_char(s.horario_inicio, 'HH24:MI') AS horario_inicio,
               to_char(s.horario_fim, 'HH24:MI') AS horario_fim
        FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE a.id_aluno = ?
    ");
    $stmt->execute([$idAluno]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Confere, antes de gravar, se o registro pode existir: dia de aula,
 * horário da turma e tolerância da escola. Encerra com 422 e a
 * explicação quando não pode - ex.: entrada atrasada num sábado, num
 * feriado ou de um aluno da manhã às 17h.
 *
 * @return array{turma: array, configuracao: array, horario: array}
 */
function exigirHorarioDoRegistro(PDO $conexao, string $tipo, int $idAluno, int $idEscola, string $momentoUtc): array {
    $local = (new DateTimeImmutable($momentoUtc, new DateTimeZone('UTC')))->setTimezone(fusoEscola());
    $data = $local->format('Y-m-d');
    try {
        $turma = turmaDoAluno($conexao, $idAluno) ?? [];
        $configuracao = configuracaoDaEscola($conexao, $idEscola);
        $situacaoDia = situacaoDoDia($data, calendarioDaEscola($conexao, $idEscola, $data, $data));
    } catch (PDOException $erro) {
        enviarErro("Erro no servidor ao conferir o calendário da escola.", 500, $erro);
    }
    $horario = horarioDaTurma($turma['periodo_sala'] ?? null, $turma['horario_inicio'] ?? null, $turma['horario_fim'] ?? null);
    $erro = conferirHorarioDoRegistro(
        $tipo, $data, $local->format('H:i'), $situacaoDia, $horario,
        $configuracao['tolerancia_minutos'], (string) ($turma['nome_sala'] ?? '')
    );
    if ($erro !== null) {
        enviarErro($erro, 422, null, [
            "dia"                => $situacaoDia,
            "horario_turma"      => $horario,
            "tolerancia_minutos" => $configuracao['tolerancia_minutos'],
        ]);
    }
    return ['turma' => $turma, 'configuracao' => $configuracao, 'horario' => $horario];
}

/**
 * Grava a entrada atrasada / saída antecipada, ou devolve a que já existe
 * no mesmo dia.
 *
 * @param array $extras colunas próprias do tipo (EVENTOS_FREQUENCIA[...]['extras'])
 * @return array{id:int, data:string, ja_existia:bool}  data em UTC
 */
function registrarEventoFrequencia(
    PDO $conexao,
    string $tipo,
    int $idAluno,
    int $idCoordenacao,
    ?string $momentoUtc,
    ?string $motivo,
    ?string $observacao,
    bool $justificado,
    array $extras = []
): array {
    $c = EVENTOS_FREQUENCIA[$tipo];
    $momentoUtc = $momentoUtc ?? agoraUtc()->format('Y-m-d H:i:s');
    $extras = array_intersect_key($extras, array_flip($c['extras']));

    $trava = $conexao->prepare("SELECT pg_advisory_xact_lock(?, ?)");
    $trava->execute([$c['trava'], $idAluno]);

    $existente = $conexao->prepare("
        SELECT {$c['id']} AS id, {$c['data']} AS data
        FROM {$c['tabela']}
        WHERE id_aluno = ? AND " . sqlDataNaEscola($c['data']) . " = ?::date
        ORDER BY {$c['id']}
        LIMIT 1
    ");
    $existente->execute([$idAluno, dataNaEscola($momentoUtc)]);
    $linha = $existente->fetch(PDO::FETCH_ASSOC);
    if ($linha) {
        return ['id' => (int) $linha['id'], 'data' => $linha['data'], 'ja_existia' => true];
    }

    // justificado vai como texto 'true'/'false': PDO::PARAM_BOOL tem um
    // bug conhecido no driver pgsql que costuma virar '' e quebrar o
    // insert numa coluna boolean.
    $colunasExtras = implode('', array_map(fn($coluna) => ", $coluna", array_keys($extras)));
    $marcadoresExtras = str_repeat(', ?', count($extras));
    $insert = $conexao->prepare("
        INSERT INTO {$c['tabela']} (id_aluno, id_coordenacao, {$c['data']}, {$c['motivo']}, {$c['justificado']}, {$c['observacao']}$colunasExtras)
        VALUES (?, ?, ?, ?, ?, ?$marcadoresExtras)
        RETURNING {$c['id']} AS id, {$c['data']} AS data
    ");
    $insert->execute([
        $idAluno,
        $idCoordenacao,
        $momentoUtc,
        $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null,
        $justificado ? 'true' : 'false',
        $observacao !== null && trim($observacao) !== '' ? trim($observacao) : null,
        ...array_values($extras),
    ]);
    $novo = $insert->fetch(PDO::FETCH_ASSOC);
    return ['id' => (int) $novo['id'], 'data' => $novo['data'], 'ja_existia' => false];
}
