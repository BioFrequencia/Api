<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/cancelamento.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Histórico de correções da escola (tb_auditoria_registro): cada
 * cancelamento e restauração de entrada atrasada, saída antecipada ou
 * presença - quem fez, quando, o motivo e como o registro estava.
 *
 *   { id_aluno?, pagina? }  (20 por página, mais recentes primeiro)
 *
 * Só a gestão da escola vê.
 */
const CORRECOES_POR_PAGINA = 20;

$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados['id_aluno'] ?? null;
if ($idAluno !== null && !inteiroValido($idAluno)) {
    enviarErro("Aluno inválido.", 422);
}
$idAluno = $idAluno === null ? null : (int) $idAluno;
$pagina = inteiroValido($dados['pagina'] ?? null) ? max(1, (int) $dados['pagina']) : 1;

try {
    $total = $conexao->prepare("
        SELECT COUNT(*) FROM tb_auditoria_registro
        WHERE id_escola = ? AND (?::int IS NULL OR id_aluno = ?::int)
    ");
    $total->execute([$conta['id_escola'], $idAluno, $idAluno]);
    $totalRegistros = (int) $total->fetchColumn();

    $stmt = $conexao->prepare("
        SELECT au.id_auditoria, au.tipo, au.id_registro, au.id_aluno, a.nome_aluno, s.nome_sala,
               au.acao, au.motivo, au.criado_em, c.nome_coordenacao AS feito_por,
               au.antes::text AS antes, au.depois::text AS depois, autor.nome_coordenacao AS registrado_por
        FROM tb_auditoria_registro au
        JOIN tb_aluno a ON a.id_aluno = au.id_aluno
        LEFT JOIN tb_sala s ON s.id_sala = a.id_sala
        JOIN tb_coordenacao c ON c.id_coordenacao = au.id_coordenacao
        LEFT JOIN tb_coordenacao autor ON autor.id_coordenacao = (au.antes->>'id_coordenacao')::int
        WHERE au.id_escola = ? AND (?::int IS NULL OR au.id_aluno = ?::int)
        ORDER BY au.criado_em DESC, au.id_auditoria DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $conta['id_escola']);
    $stmt->bindValue(2, $idAluno);
    $stmt->bindValue(3, $idAluno);
    $stmt->bindValue(4, CORRECOES_POR_PAGINA, PDO::PARAM_INT);
    $stmt->bindValue(5, ($pagina - 1) * CORRECOES_POR_PAGINA, PDO::PARAM_INT);
    $stmt->execute();
    $correcoes = array_map(function (array $linha): array {
        $antes = json_decode($linha['antes'], true) ?: [];
        $depois = json_decode($linha['depois'], true) ?: [];
        return [
            'id_auditoria'   => (int) $linha['id_auditoria'],
            'tipo'           => $linha['tipo'],
            'id_registro'    => (int) $linha['id_registro'],
            'id_aluno'       => (int) $linha['id_aluno'],
            'nome_aluno'     => $linha['nome_aluno'],
            'nome_sala'      => $linha['nome_sala'],
            'acao'           => $linha['acao'],
            'motivo'         => $linha['motivo'],
            'feito_em'       => $linha['criado_em'],
            'feito_por'      => $linha['feito_por'],
            'registrado_por' => $linha['registrado_por'],
            'antes'          => resumoDoRegistroAuditado($linha['tipo'], $antes),
            'depois'         => resumoDoRegistroAuditado($linha['tipo'], $depois),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Histórico de correções carregado.", [
        'correcoes'       => $correcoes,
        'pagina'          => $pagina,
        'total_registros' => $totalRegistros,
        'total_paginas'   => max(1, (int) ceil($totalRegistros / CORRECOES_POR_PAGINA)),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar o histórico de correções.", 500, $erro);
}
