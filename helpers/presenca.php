<?php
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
