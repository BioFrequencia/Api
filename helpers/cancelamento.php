<?php
require_once __DIR__ . '/tempo.php';
require_once __DIR__ . '/registroEvento.php';

/**
 * Cancelamento (exclusão lógica) de entradas atrasadas, saídas antecipadas
 * e presenças, com histórico de auditoria (migração 012).
 *
 * Cancelar, e não apagar: o registro errado continua no banco com quem
 * cancelou, quando e por quê, e tb_auditoria_registro guarda o registro
 * como estava antes e como ficou (só aceita inclusões). Cancelado, ele sai
 * das contas (porcentagens, alertas, aviso por limite, relatórios) e da
 * regra "um por dia" - o registro certo pode ser lançado em seguida.
 *
 * Quem pode:
 *  - a gestão da escola: qualquer registro da escola, a qualquer momento;
 *    só ela restaura um cancelado;
 *  - quem fez o registro: até o fim do dia em que registrou (fuso da
 *    escola), para desfazer um engano - marcou o aluno errado, apertou sem
 *    querer, o reconhecimento facial apontou outro aluno. Registros feitos
 *    antes da migração 012 não têm a hora em que foram feitos: só a gestão.
 *
 * Cancelar uma presença cancela junto a entrada atrasada que ela gerou
 * (origem 'presenca', mesmo aluno e mesmo dia). Avisos que já foram para a
 * família não voltam.
 */

const MOTIVO_CANCELAMENTO_MAXIMO = 200;

const REGISTROS_CANCELAVEIS = [
    'atraso'   => ['tabela' => 'tb_atraso', 'id' => 'id_atraso', 'data' => 'dta_atraso', 'criado' => 'criado_em', 'trava' => 7302],
    'saida'    => ['tabela' => 'tb_saida_antecipada', 'id' => 'id_saida', 'data' => 'dta_saida', 'criado' => 'criado_em', 'trava' => 7303],
    'presenca' => ['tabela' => 'tb_presenca', 'id' => 'id_presenca', 'data' => 'registrada_em', 'criado' => 'registrada_em', 'trava' => 7304],
];

/**
 * A conta pode cancelar este registro? Função pura.
 *
 * @param array $conta    id_coordenacao, papel
 * @param array $registro id_coordenacao (quem fez), criado_em (UTC, 'Y-m-d H:i:s' ou null)
 */
function podeCancelarRegistro(array $conta, array $registro, DateTimeImmutable $agoraUtc): bool {
    if (($conta['papel'] ?? null) === 'gestor') {
        return true;
    }
    if ((int) ($registro['id_coordenacao'] ?? 0) !== (int) ($conta['id_coordenacao'] ?? -1)) {
        return false;
    }
    $criado = $registro['criado_em'] ?? null;
    if (!is_string($criado) || trim($criado) === '') {
        return false;
    }
    try {
        $criadoEm = new DateTimeImmutable($criado, new DateTimeZone('UTC'));
    } catch (Exception) {
        return false;
    }
    // O mesmo dia na escola (não 24 horas): o engano de hoje se desfaz hoje.
    return $criadoEm->setTimezone(fusoEscola())->format('Y-m-d') === $agoraUtc->setTimezone(fusoEscola())->format('Y-m-d');
}

/**
 * Motivo do cancelamento ou da restauração: espaços juntados, até
 * MOTIVO_CANCELAMENTO_MAXIMO caracteres. Função pura.
 *
 * @return array{0: ?string, 1: ?string} [motivo ou null se vazio, erro]
 */
function lerMotivoCancelamento($motivo): array {
    if ($motivo === null) {
        return [null, null];
    }
    if (!is_string($motivo)) {
        return [null, 'Motivo inválido.'];
    }
    $motivo = trim((string) preg_replace('/\s+/u', ' ', $motivo));
    if ($motivo === '') {
        return [null, null];
    }
    if (mb_strlen($motivo) > MOTIVO_CANCELAMENTO_MAXIMO) {
        return [null, 'O motivo pode ter até ' . MOTIVO_CANCELAMENTO_MAXIMO . ' caracteres.'];
    }
    return [$motivo, null];
}

/**
 * O que muda no registro, resumido para o histórico de auditoria (a partir
 * do JSON da linha): quando foi o registro e o que dizia. Função pura.
 *
 * @return array{momento: ?string, motivo: ?string, detalhe: ?string, cancelado: bool, motivo_cancelamento: ?string}
 */
function resumoDoRegistroAuditado(string $tipo, array $linha): array {
    $momento = $linha[REGISTROS_CANCELAVEIS[$tipo]['data'] ?? ''] ?? null;
    $detalhe = match ($tipo) {
        'atraso'   => match ($linha['origem'] ?? 'manual') {
            'presenca'   => 'Pela presença do app',
            'ocorrencia' => 'Enviada pelo celular do aluno',
            default      => 'Lançada pela coordenação',
        },
        'saida'    => isset($linha['retirado_por']) && $linha['retirado_por'] !== '' ? 'Buscou: ' . $linha['retirado_por'] : null,
        'presenca' => match ($linha['metodo'] ?? '') {
            'facial'  => 'Reconhecimento facial',
            'digital' => 'Digital',
            default   => 'Registro manual',
        },
        default    => null,
    };
    return [
        'momento'             => is_string($momento) ? $momento : null,
        'motivo'              => $linha['motivo_atraso'] ?? $linha['motivo_saida'] ?? null,
        'detalhe'             => $detalhe,
        'cancelado'           => !empty($linha['cancelado_em']),
        'motivo_cancelamento' => $linha['motivo_cancelamento'] ?? null,
    ];
}

// ---------------------------------------------------------------- banco

/** O registro da escola, travado para a alteração (com a linha em JSON); null se não for desta escola. */
function registroParaAlterar(PDO $conexao, string $tipo, int $id, int $idEscola): ?array {
    $c = REGISTROS_CANCELAVEIS[$tipo];
    $stmt = $conexao->prepare("
        SELECT r.{$c['id']} AS id, r.id_aluno, r.id_coordenacao, r.cancelado_em,
               to_char(r.{$c['criado']}, 'YYYY-MM-DD HH24:MI:SS') AS criado_em,
               r.{$c['data']} AS momento, to_jsonb(r) AS linha, a.nome_aluno
        FROM {$c['tabela']} r
        JOIN tb_aluno a ON a.id_aluno = r.id_aluno
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE r.{$c['id']} = ? AND s.id_escola = ?
        FOR UPDATE OF r
    ");
    $stmt->execute([$id, $idEscola]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function auditarRegistro(
    PDO $conexao, int $idEscola, string $tipo, int $id, int $idAluno, string $acao,
    string $antes, string $depois, ?string $motivo, int $idCoordenacao
): void {
    $conexao->prepare("
        INSERT INTO tb_auditoria_registro (id_escola, tipo, id_registro, id_aluno, acao, antes, depois, motivo, id_coordenacao)
        VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?)
    ")->execute([$idEscola, $tipo, $id, $idAluno, $acao, $antes, $depois, $motivo, $idCoordenacao]);
}

/** Marca como cancelado e audita. Precisa estar numa transação, com a linha travada. */
function marcarCancelado(PDO $conexao, int $idEscola, string $tipo, array $registro, ?string $motivo, int $idCoordenacao): void {
    $c = REGISTROS_CANCELAVEIS[$tipo];
    $atualiza = $conexao->prepare("
        UPDATE {$c['tabela']} r SET cancelado_em = NOW(), cancelado_por = ?, motivo_cancelamento = ?
        WHERE r.{$c['id']} = ?
        RETURNING to_jsonb(r) AS linha
    ");
    $atualiza->execute([$idCoordenacao, $motivo, $registro['id']]);
    $depois = (string) $atualiza->fetchColumn();
    auditarRegistro($conexao, $idEscola, $tipo, (int) $registro['id'], (int) $registro['id_aluno'], 'cancelar',
        (string) $registro['linha'], $depois, $motivo, $idCoordenacao);
}

/**
 * Cancela o registro (e, numa presença, a entrada atrasada que ela gerou).
 *
 * @return array{ok: bool, status?: int, erro?: string, cancelados?: list<array{tipo: string, id: int}>, nome_aluno?: string}
 */
function cancelarRegistro(PDO $conexao, array $conta, string $tipo, int $id, ?string $motivo): array {
    $idEscola = (int) $conta['id_escola'];
    $idCoordenacao = (int) $conta['id_coordenacao'];
    $conexao->beginTransaction();
    try {
        $registro = registroParaAlterar($conexao, $tipo, $id, $idEscola);
        if ($registro === null) {
            $conexao->rollBack();
            return ['ok' => false, 'status' => 404, 'erro' => 'Registro não encontrado nesta escola.'];
        }
        if ($registro['cancelado_em'] !== null) {
            $conexao->rollBack();
            return ['ok' => false, 'status' => 409, 'erro' => 'Este registro já está cancelado.'];
        }
        if (!podeCancelarRegistro($conta, $registro, agoraUtc())) {
            $conexao->rollBack();
            return ['ok' => false, 'status' => 403, 'erro' => (int) $registro['id_coordenacao'] === $idCoordenacao
                ? 'O prazo para você desfazer este registro (o dia em que ele foi feito) já passou. Peça à gestão da escola.'
                : 'Só a gestão da escola cancela registros feitos por outra pessoa.'];
        }
        marcarCancelado($conexao, $idEscola, $tipo, $registro, $motivo, $idCoordenacao);
        $cancelados = [['tipo' => $tipo, 'id' => (int) $registro['id']]];

        if ($tipo === 'presenca') {
            // A entrada atrasada que esta presença gerou cai junto.
            $derivados = $conexao->prepare("
                SELECT t.id_atraso FROM tb_atraso t
                WHERE t.id_aluno = ? AND t.origem = 'presenca' AND t.cancelado_em IS NULL
                  AND " . sqlDataNaEscola('t.dta_atraso') . " = " . sqlDataNaEscola('?::timestamp') . "
            ");
            $derivados->execute([$registro['id_aluno'], $registro['momento']]);
            foreach ($derivados->fetchAll(PDO::FETCH_COLUMN) as $idAtraso) {
                $atraso = registroParaAlterar($conexao, 'atraso', (int) $idAtraso, $idEscola);
                if ($atraso === null) {
                    continue;
                }
                $motivoAtraso = 'Cancelada junto com a presença que a gerou' . ($motivo !== null ? ": $motivo" : '.');
                marcarCancelado($conexao, $idEscola, 'atraso', $atraso, mb_substr($motivoAtraso, 0, MOTIVO_CANCELAMENTO_MAXIMO), $idCoordenacao);
                $cancelados[] = ['tipo' => 'atraso', 'id' => (int) $idAtraso];
            }
        }
        $conexao->commit();
        return ['ok' => true, 'cancelados' => $cancelados, 'nome_aluno' => $registro['nome_aluno']];
    } catch (PDOException $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        throw $erro;
    }
}

/**
 * Desfaz um cancelamento (só a gestão - quem chama confere). Recusa quando
 * o aluno já tem outro registro ativo do mesmo tipo no mesmo dia.
 *
 * @return array{ok: bool, status?: int, erro?: string, nome_aluno?: string}
 */
function restaurarRegistro(PDO $conexao, array $conta, string $tipo, int $id, ?string $motivo): array {
    $c = REGISTROS_CANCELAVEIS[$tipo];
    $idEscola = (int) $conta['id_escola'];
    $conexao->beginTransaction();
    try {
        $registro = registroParaAlterar($conexao, $tipo, $id, $idEscola);
        if ($registro === null) {
            $conexao->rollBack();
            return ['ok' => false, 'status' => 404, 'erro' => 'Registro não encontrado nesta escola.'];
        }
        if ($registro['cancelado_em'] === null) {
            $conexao->rollBack();
            return ['ok' => false, 'status' => 409, 'erro' => 'Este registro não está cancelado.'];
        }
        // A mesma trava por aluno do registro (helpers/registroEvento.php):
        // ninguém lança outro do mesmo tipo enquanto este volta.
        $conexao->prepare("SELECT pg_advisory_xact_lock(?, ?)")->execute([$c['trava'], $registro['id_aluno']]);
        $outro = $conexao->prepare("
            SELECT 1 FROM {$c['tabela']} r
            WHERE r.id_aluno = ? AND r.{$c['id']} <> ? AND r.cancelado_em IS NULL
              AND " . sqlDataNaEscola("r.{$c['data']}") . " = " . sqlDataNaEscola('?::timestamp') . "
            LIMIT 1
        ");
        $outro->execute([$registro['id_aluno'], $registro['id'], $registro['momento']]);
        if ($outro->fetchColumn()) {
            $conexao->rollBack();
            $nome = ['atraso' => 'entrada atrasada', 'saida' => 'saída antecipada', 'presenca' => 'presença'][$tipo];
            return ['ok' => false, 'status' => 409, 'erro' => "O aluno já tem outra $nome ativa neste dia. Cancele aquela antes de restaurar esta."];
        }
        $atualiza = $conexao->prepare("
            UPDATE {$c['tabela']} r SET cancelado_em = NULL, cancelado_por = NULL, motivo_cancelamento = NULL
            WHERE r.{$c['id']} = ?
            RETURNING to_jsonb(r) AS linha
        ");
        $atualiza->execute([$registro['id']]);
        auditarRegistro($conexao, $idEscola, $tipo, (int) $registro['id'], (int) $registro['id_aluno'], 'restaurar',
            (string) $registro['linha'], (string) $atualiza->fetchColumn(), $motivo, (int) $conta['id_coordenacao']);
        $conexao->commit();
        return ['ok' => true, 'nome_aluno' => $registro['nome_aluno']];
    } catch (PDOException $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        throw $erro;
    }
}
