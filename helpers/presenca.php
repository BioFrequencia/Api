<?php
require_once __DIR__ . '/registroEvento.php';
require_once __DIR__ . '/avisos.php';

/**
 * Presença registrada pelo app da coordenação (reconhecimento facial,
 * digital ou manual). Uma por aluno por dia letivo - garantido pela
 * restrição UNIQUE (id_aluno, dia_letivo) da migração 006, não só por esta
 * função: dois tablets reconhecendo o mesmo aluno ao mesmo tempo gravam
 * uma linha só.
 */

const METODOS_PRESENCA = ['facial', 'digital', 'manual'];

/**
 * @return array{nova: bool, id_presenca: int, registrada_em: string, metodo: string}
 *   nova = false quando o aluno já tinha presença hoje (devolve a existente).
 */
function registrarPresenca(PDO $conexao, int $idAluno, int $idCoordenacao, string $metodo, ?float $distancia = null): array {
    $dia = hojeNaEscola();

    $insere = $conexao->prepare("
        INSERT INTO tb_presenca (id_aluno, dia_letivo, metodo, id_coordenacao, distancia)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (id_aluno, dia_letivo) DO NOTHING
        RETURNING id_presenca, registrada_em, metodo
    ");
    $insere->bindValue(1, $idAluno);
    $insere->bindValue(2, $dia);
    $insere->bindValue(3, $metodo);
    $insere->bindValue(4, $idCoordenacao);
    $insere->bindValue(5, $distancia === null ? null : round($distancia, 5));
    $insere->execute();
    $nova = $insere->fetch(PDO::FETCH_ASSOC);

    if ($nova) {
        return ["nova" => true] + normalizarPresenca($nova);
    }

    $existente = $conexao->prepare("
        SELECT id_presenca, registrada_em, metodo FROM tb_presenca
        WHERE id_aluno = ? AND dia_letivo = ?
    ");
    $existente->bindValue(1, $idAluno);
    $existente->bindValue(2, $dia);
    $existente->execute();
    return ["nova" => false] + normalizarPresenca($existente->fetch(PDO::FETCH_ASSOC));
}

function normalizarPresenca(array $linha): array {
    return [
        "id_presenca"   => (int) $linha['id_presenca'],
        "registrada_em" => $linha['registrada_em'],
        "metodo"        => $linha['metodo'],
    ];
}

/** Aluno da escola com a turma, para a resposta da identificação. */
function dadosDoAluno(PDO $conexao, int $idAluno): array {
    $stmt = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala
        FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE a.id_aluno = ?
    ");
    $stmt->bindValue(1, $idAluno);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Depois de uma presença NOVA registrada pelo app: a entrada foi no
 * horário? Se foi depois da tolerância, ainda durante a aula, e a escola
 * deixa a presença valer como registro (atraso_pela_presenca, ligado por
 * padrão), grava a entrada atrasada com origem 'presenca' e dispara os
 * avisos aos responsáveis - o objetivo do TCC é exatamente esse: o aluno
 * passa pela biometria na chegada e o atraso fica registrado sozinho.
 *
 * Presença fora do horário da turma ou num dia sem aula fica só como
 * presença. Uma falha aqui nunca derruba a presença, que já foi gravada.
 *
 * @return ?array{situacao: string, horario_turma: array, tolerancia_minutos: int, id_atraso: ?int, avisos: ?array}
 *         null quando não deu para conferir (a presença continua valendo)
 */
function conferirEntradaDaPresenca(PDO $conexao, int $idAluno, int $idCoordenacao, int $idEscola, string $registradaEmUtc, string $metodo): ?array {
    $local = (new DateTimeImmutable($registradaEmUtc, new DateTimeZone('UTC')))->setTimezone(fusoEscola());
    $data = $local->format('Y-m-d');
    $hora = $local->format('H:i');
    try {
        $turma = turmaDoAluno($conexao, $idAluno) ?? [];
        $configuracao = configuracaoDaEscola($conexao, $idEscola);
        $situacaoDia = situacaoDoDia($data, calendarioDaEscola($conexao, $idEscola, $data, $data));
    } catch (PDOException $erro) {
        error_log('[BioFrequenciaApi] Situação da entrada não conferida: ' . $erro->getMessage());
        return null;
    }
    $horario = horarioDaTurma($turma['periodo_sala'] ?? null, $turma['horario_inicio'] ?? null, $turma['horario_fim'] ?? null);
    $situacao = situacaoDaEntrada($hora, $situacaoDia, $horario, $configuracao['tolerancia_minutos']);

    $entrada = [
        "situacao"           => $situacao,
        "horario_turma"      => $horario,
        "tolerancia_minutos" => $configuracao['tolerancia_minutos'],
        "id_atraso"          => null,
        "avisos"             => null,
    ];
    if ($situacao !== 'atrasada' || !$configuracao['atraso_pela_presenca']) {
        return $entrada;
    }

    try {
        $conexao->beginTransaction();
        $atraso = registrarEventoFrequencia(
            $conexao, 'atraso', $idAluno, $idCoordenacao, $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            null, "Registrada pela presença ($metodo) às $hora.", false, ['origem' => 'presenca']
        );
        $conexao->commit();
    } catch (PDOException $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        error_log('[BioFrequenciaApi] Entrada atrasada pela presença não registrada: ' . $erro->getMessage());
        return $entrada;
    }
    $entrada['id_atraso'] = $atraso['id'];
    if (!$atraso['ja_existia']) {
        $entrada['avisos'] = avisosDepoisDoRegistro($conexao, $idEscola, $idAluno, (string) ($turma['nome_aluno'] ?? ''), 'atraso', $atraso['data']);
    }
    return $entrada;
}

/** Mensagem da presença nova para o app, dizendo se a entrada atrasou. */
function mensagemDaPresenca(?array $entrada): string {
    if ($entrada !== null && $entrada['situacao'] === 'atrasada') {
        return $entrada['id_atraso'] !== null
            ? "Presença registrada como entrada atrasada (depois das {$entrada['horario_turma']['inicio']} + {$entrada['tolerancia_minutos']} min de tolerância)."
            : "Presença registrada depois da tolerância da turma.";
    }
    return "Presença registrada.";
}
