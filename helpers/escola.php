<?php
/**
 * Escopo por escola.
 *
 * Antes da migração 005 qualquer conta de coordenação enxergava os
 * alunos de todas as escolas: tb_aluno/tb_sala não tinham nenhum vínculo
 * com quem estava logado. Com várias escolas usando a mesma instalação,
 * os cadastros se misturavam.
 *
 * O aluno pertence à escola pela turma (tb_aluno.id_sala -> tb_sala.
 * id_escola), então todo filtro passa por esse caminho - não existe
 * coluna de escola repetida em tb_aluno.
 */

function escolaDaCoordenacao(PDO $conexao, $idCoordenacao): int {
    $stmt = $conexao->prepare("SELECT id_escola FROM tb_coordenacao WHERE id_coordenacao = ?");
    $stmt->bindValue(1, $idCoordenacao);
    $stmt->execute();
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        enviarErro("Conta de coordenação não encontrada.", 401);
    }
    return (int) $linha['id_escola'];
}

/**
 * Confirma que o aluno pertence à escola informada. Usado antes de
 * qualquer escrita que receba id_aluno do cliente - sem isso, bastaria
 * mandar um id_aluno de outra escola no corpo da requisição para
 * registrar falta no aluno dos outros.
 */
function alunoPertenceAEscola(PDO $conexao, $idAluno, int $idEscola): bool {
    $stmt = $conexao->prepare("
        SELECT 1
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE a.id_aluno = ? AND s.id_escola = ?
    ");
    $stmt->bindValue(1, $idAluno);
    $stmt->bindValue(2, $idEscola);
    $stmt->execute();
    return (bool) $stmt->fetch();
}

/** Encerra a requisição com 404 quando o aluno não é da escola de quem chamou. */
function exigirAlunoDaEscola(PDO $conexao, $idAluno, int $idEscola): void {
    if (!alunoPertenceAEscola($conexao, $idAluno, $idEscola)) {
        // 404 e não 403 de propósito: responder "existe, mas não é seu"
        // confirmaria para outra escola que aquele id existe.
        enviarErro("Aluno não encontrado.", 404);
    }
}
