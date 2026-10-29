<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

// Histórico de contatos com as famílias: só da escola de quem está
// logado (antes devolvia os registros de todas as escolas, com as
// observações sobre cada aluno).
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno   = $dados["id_aluno"] ?? null;   // opcional: filtra por aluno
$pagina    = max(1, (int) ($dados["pagina"] ?? 1));
$porPagina = max(1, min(100, (int) ($dados["por_pagina"] ?? 20)));
$offset    = ($pagina - 1) * $porPagina;

try {
    $params = [$idEscola];
    $where = "WHERE s.id_escola = ?";
    if ($idAluno !== null) {
        if (!inteiroValido($idAluno)) {
            enviarErro("Aluno inválido.", 422);
        }
        $where .= " AND ac.id_aluno = ?";
        $params[] = $idAluno;
    }

    $sqlTotal = "
        SELECT COUNT(*) FROM tb_acompanhamento ac
        JOIN tb_aluno al ON al.id_aluno = ac.id_aluno
        JOIN tb_sala s ON s.id_sala = al.id_sala
        $where
    ";
    $stmtTotal = $conexao->prepare($sqlTotal);
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $sql = "
        SELECT ac.id_acompanhamento, ac.id_aluno, al.nome_aluno, s.nome_sala,
               ac.canal, ac.observacao, ac.data_registro,
               co.nome_coordenacao
        FROM tb_acompanhamento ac
        JOIN tb_aluno al ON al.id_aluno = ac.id_aluno
        JOIN tb_sala s ON s.id_sala = al.id_sala
        JOIN tb_coordenacao co ON co.id_coordenacao = ac.id_coordenacao
        $where
        ORDER BY ac.data_registro DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conexao->prepare($sql);
    $indice = 1;
    foreach ($params as $valor) {
        $stmt->bindValue($indice++, $valor);
    }
    $stmt->bindValue($indice++, $porPagina, PDO::PARAM_INT);
    $stmt->bindValue($indice++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    enviarSucesso("Acompanhamentos carregados com sucesso.", [
        "registros"       => $registros,
        "pagina_atual"    => $pagina,
        "total_paginas"   => (int) ceil($total / $porPagina),
        "total_registros" => $total,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os acompanhamentos.", 500, $erro);
}
