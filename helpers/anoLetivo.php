<?php
require_once __DIR__ . '/tempo.php';

/**
 * Fim do ano letivo (migração 014). A gestão diz, para cada turma, o que
 * acontece com os alunos dela: passam para outra turma, saem da escola
 * (concluíram) ou ficam onde estão - e pode abrir exceções aluno a aluno
 * (quem repete, quem foi transferido).
 *
 * Quem sai NÃO é apagado: fica com situacao_aluno 'concluiu' ou
 * 'transferido' e some das listas, do app de biometria, dos alertas e do
 * resumo às famílias; o histórico (entradas, saídas, presenças, avisos)
 * continua nos relatórios. A biometria de quem sai é apagada na hora: a
 * escola não tem mais por que guardar um dado sensível (LGPD, art. 15 e 16).
 */

const SITUACOES_ALUNO = ['ativo', 'concluiu', 'transferido'];

/** Destinos aceitos para uma turma ou um aluno: 'manter', 'concluiu', 'transferido' ou o id de uma turma. */
function destinoValido($destino, array $idsTurmas): bool {
    if (in_array($destino, ['manter', 'concluiu', 'transferido'], true)) {
        return true;
    }
    return is_int($destino) && in_array($destino, $idsTurmas, true);
}

/**
 * O que acontece com cada aluno ativo, a partir das escolhas por turma e
 * das exceções por aluno. Função pura.
 *
 * @param array<int, int[]> $alunosPorTurma id_sala => ids dos alunos ATIVOS
 * @param array $turmas  [{id_sala: int, destino: 'manter'|'concluiu'|'transferido'|int}]
 * @param array $excecoes [{id_aluno: int, destino: ...}]
 * @return array{0: ?array{mover: array<int,int>, sair: array<int,string>}, 1: ?string} [plano, erro]
 */
function planoDoEncerramento(array $alunosPorTurma, array $turmas, array $excecoes): array {
    $idsTurmas = array_map('intval', array_keys($alunosPorTurma));
    $turmaDoAluno = [];
    foreach ($alunosPorTurma as $idSala => $alunos) {
        foreach ($alunos as $idAluno) {
            $turmaDoAluno[(int) $idAluno] = (int) $idSala;
        }
    }

    $destinos = [];
    foreach ($turmas as $turma) {
        $idSala = $turma['id_sala'] ?? null;
        $destino = $turma['destino'] ?? null;
        if (!is_int($idSala) || !in_array($idSala, $idsTurmas, true)) {
            return [null, "Turma inválida na lista."];
        }
        if (!destinoValido($destino, $idsTurmas)) {
            return [null, "Destino inválido para uma das turmas."];
        }
        foreach ($alunosPorTurma[$idSala] as $idAluno) {
            $destinos[(int) $idAluno] = $destino;
        }
    }
    foreach ($excecoes as $excecao) {
        $idAluno = $excecao['id_aluno'] ?? null;
        $destino = $excecao['destino'] ?? null;
        if (!is_int($idAluno) || !isset($turmaDoAluno[$idAluno])) {
            return [null, "Aluno inválido nas exceções."];
        }
        if (!destinoValido($destino, $idsTurmas)) {
            return [null, "Destino inválido para um dos alunos."];
        }
        $destinos[$idAluno] = $destino;
    }

    $plano = ['mover' => [], 'sair' => []];
    foreach ($destinos as $idAluno => $destino) {
        if ($destino === 'manter' || $destino === $turmaDoAluno[$idAluno]) {
            continue;
        }
        if (is_int($destino)) {
            $plano['mover'][$idAluno] = $destino;
        } else {
            $plano['sair'][$idAluno] = $destino;
        }
    }
    if (empty($plano['mover']) && empty($plano['sair'])) {
        return [null, "Nada para mudar: todas as turmas ficaram como estão."];
    }
    ksort($plano['mover']);
    ksort($plano['sair']);
    return [$plano, null];
}

/**
 * Turmas da escola com os alunos ativos de cada uma (para a tela e para o
 * plano): [id_sala => ['id_sala', 'nome_sala', 'periodo_sala', 'alunos' => [{id_aluno, nome_aluno}]]].
 */
function turmasComAlunosAtivos(PDO $conexao, int $idEscola): array {
    $stmt = $conexao->prepare("
        SELECT s.id_sala, s.nome_sala, s.periodo_sala, a.id_aluno, a.nome_aluno
        FROM tb_sala s
        LEFT JOIN tb_aluno a ON a.id_sala = s.id_sala AND a.situacao_aluno = 'ativo'
        WHERE s.id_escola = ?
        ORDER BY s.nome_sala, s.id_sala, a.nome_aluno, a.id_aluno
    ");
    $stmt->execute([$idEscola]);
    $turmas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $idSala = (int) $linha['id_sala'];
        $turmas[$idSala] ??= ['id_sala' => $idSala, 'nome_sala' => $linha['nome_sala'], 'periodo_sala' => $linha['periodo_sala'], 'alunos' => []];
        if ($linha['id_aluno'] !== null) {
            $turmas[$idSala]['alunos'][] = ['id_aluno' => (int) $linha['id_aluno'], 'nome_aluno' => $linha['nome_aluno']];
        }
    }
    return $turmas;
}

/**
 * Apaga a biometria (rosto e digitais) dos alunos que saíram. O
 * consentimento fica registrado como histórico do que a família autorizou.
 */
function apagarBiometriaDe(PDO $conexao, array $idsAlunos): int {
    if (empty($idsAlunos)) {
        return 0;
    }
    $lista = '{' . implode(',', array_map('intval', $idsAlunos)) . '}';
    $apagadas = 0;
    foreach (['tb_biometria_facial', 'tb_biometria_digital'] as $tabela) {
        $stmt = $conexao->prepare("DELETE FROM $tabela WHERE id_aluno = ANY(?::int[])");
        $stmt->execute([$lista]);
        $apagadas += $stmt->rowCount();
    }
    return $apagadas;
}

/**
 * Aplica o plano numa transação só: muda as turmas, marca quem saiu e
 * apaga a biometria de quem saiu. Todos os movimentos partem da turma de
 * ANTES (1º A -> 2º A e 2º A -> 3º A ao mesmo tempo não empurram ninguém
 * duas vezes), porque o plano já tem o id de cada aluno.
 *
 * @return array{movidos: int, sairam: int, biometrias_apagadas: int}
 */
function aplicarEncerramento(PDO $conexao, array $plano): array {
    $conexao->beginTransaction();
    try {
        $mover = $conexao->prepare("UPDATE tb_aluno SET id_sala = ? WHERE id_aluno = ? AND situacao_aluno = 'ativo'");
        $movidos = 0;
        foreach ($plano['mover'] as $idAluno => $idSala) {
            $mover->execute([$idSala, $idAluno]);
            $movidos += $mover->rowCount();
        }
        $sair = $conexao->prepare("UPDATE tb_aluno SET situacao_aluno = ?, saiu_em = NOW() WHERE id_aluno = ? AND situacao_aluno = 'ativo'");
        $sairam = [];
        foreach ($plano['sair'] as $idAluno => $situacao) {
            $sair->execute([$situacao, $idAluno]);
            if ($sair->rowCount() > 0) {
                $sairam[] = $idAluno;
            }
        }
        $biometrias = apagarBiometriaDe($conexao, $sairam);
        $conexao->commit();
    } catch (PDOException $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        throw $erro;
    }
    return ['movidos' => $movidos, 'sairam' => count($sairam), 'biometrias_apagadas' => $biometrias];
}
