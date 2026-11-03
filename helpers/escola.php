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
 *
 * Regra para qualquer endpoint autenticado que receba um id do cliente
 * (aluno, responsável, notificação, check-in...): conferir aqui que o
 * registro é da escola de quem chamou ANTES de ler ou escrever. A
 * auditoria de outubro/2026 encontrou 20 endpoints que só conferiam se o
 * id existia - qualquer coordenação lia e alterava dados de outra escola
 * trocando o número no corpo da requisição.
 */

function escolaDaCoordenacao(PDO $conexao, $idCoordenacao): int {
    try {
        $stmt = $conexao->prepare("SELECT id_escola FROM tb_coordenacao WHERE id_coordenacao = ?");
        $stmt->bindValue(1, $idCoordenacao);
        $stmt->execute();
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $erro) {
        // Chamada fora do try de vários endpoints: sem isto, uma falha
        // aqui (ex.: migração 005 não aplicada, coluna id_escola
        // inexistente) virava um erro fatal do PHP, sem JSON nenhum.
        enviarErro("Erro no servidor ao identificar a escola da conta.", 500, $erro);
    }
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
    try {
        $pertence = alunoPertenceAEscola($conexao, $idAluno, $idEscola);
    } catch (PDOException $erro) {
        enviarErro("Erro no servidor ao localizar o aluno.", 500, $erro);
    }
    if (!$pertence) {
        // 404 e não 403 de propósito: responder "existe, mas não é seu"
        // confirmaria para outra escola que aquele id existe.
        enviarErro("Aluno não encontrado.", 404);
    }
}

/** A turma existe e é da escola informada. */
function salaPertenceAEscola(PDO $conexao, $idSala, int $idEscola): bool {
    $stmt = $conexao->prepare("SELECT 1 FROM tb_sala WHERE id_sala = ? AND id_escola = ?");
    $stmt->bindValue(1, $idSala);
    $stmt->bindValue(2, $idEscola);
    $stmt->execute();
    return (bool) $stmt->fetch();
}

/**
 * Um responsável "é da escola" quando está vinculado a pelo menos um
 * aluno dela. tb_responsavel não tem coluna de escola: a mesma pessoa
 * pode ser responsável por alunos de escolas diferentes, e cada escola
 * só deve enxergar os responsáveis dos próprios alunos.
 */
function responsavelVisivelNaEscola(PDO $conexao, $idResponsavel, int $idEscola): bool {
    $stmt = $conexao->prepare("
        SELECT 1
        FROM tb_aluno_responsavel ar
        JOIN tb_aluno a ON a.id_aluno = ar.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE ar.id_responsavel = ? AND s.id_escola = ?
        LIMIT 1
    ");
    $stmt->bindValue(1, $idResponsavel);
    $stmt->bindValue(2, $idEscola);
    $stmt->execute();
    return (bool) $stmt->fetch();
}

/**
 * Conta de coordenação com a escola, o papel e a situação (migração 008).
 * Encerra com 401 quando a conta não existe mais.
 */
function contaDaCoordenacao(PDO $conexao, $idCoordenacao): array {
    try {
        $stmt = $conexao->prepare("
            SELECT c.id_coordenacao, c.nome_coordenacao, c.email_coordenacao, c.id_escola, c.papel, c.situacao,
                   e.nome_escola, e.codigo_cie, e.municipio
            FROM tb_coordenacao c
            JOIN tb_escola e ON e.id_escola = c.id_escola
            WHERE c.id_coordenacao = ?
        ");
        $stmt->execute([$idCoordenacao]);
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $erro) {
        enviarErro("Erro no servidor ao identificar a conta.", 500, $erro);
    }
    if (!$conta) {
        enviarErro("Conta de coordenação não encontrada.", 401);
    }
    $conta['id_escola'] = (int) $conta['id_escola'];
    $conta['administrador'] = ehAdministrador($conta['email_coordenacao']);
    return $conta;
}

/**
 * Ações que mudam a escola para todo mundo (horários das turmas,
 * calendário, avisos, equipe) são da gestão. A primeira conta de uma
 * escola vira gestora; ela promove quem mais precisar.
 */
function exigirGestor(PDO $conexao, $idCoordenacao): array {
    $conta = contaDaCoordenacao($conexao, $idCoordenacao);
    if ($conta['papel'] !== 'gestor') {
        enviarErro("Só a gestão da escola pode fazer isso. Peça a quem é gestor(a) da escola no sistema.", 403);
    }
    return $conta;
}

/**
 * Equipe do sistema (tela /admin): os e-mails de ADMIN_EMAILS, separados
 * por vírgula, no ambiente - nenhum e-mail fica no código nem no banco.
 */
function emailsAdministradores(): array {
    $lista = getenv('ADMIN_EMAILS');
    if ($lista === false || trim($lista) === '') {
        return [];
    }
    return array_values(array_filter(array_map(fn($e) => mb_strtolower(trim($e)), explode(',', $lista))));
}

function ehAdministrador(?string $email): bool {
    return $email !== null && in_array(mb_strtolower(trim($email)), emailsAdministradores(), true);
}

function exigirAdministrador(PDO $conexao, $idCoordenacao): array {
    $conta = contaDaCoordenacao($conexao, $idCoordenacao);
    if (!$conta['administrador']) {
        // 404 e não 403: a área nem aparece para quem não é da equipe.
        enviarErro("Página não encontrada.", 404);
    }
    return $conta;
}

/** Há pelo menos uma conta gestora ativa na escola? */
function escolaTemGestor(PDO $conexao, int $idEscola, ?int $exceto = null): bool {
    $stmt = $conexao->prepare("
        SELECT 1 FROM tb_coordenacao
        WHERE id_escola = ? AND papel = 'gestor' AND situacao = 'ativo' AND id_coordenacao <> COALESCE(?::int, 0)
        LIMIT 1
    ");
    $stmt->bindValue(1, $idEscola);
    $stmt->bindValue(2, $exceto);
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
}
