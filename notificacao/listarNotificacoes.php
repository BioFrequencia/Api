<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$status = $dados["status"] ?? null; // "pendente" | "enviada" (opcional)
$pagina = max(1, (int) ($dados["pagina"] ?? 1));
$porPagina = max(1, min(100, (int) ($dados["por_pagina"] ?? 20)));
$offset = ($pagina - 1) * $porPagina;

try {
    $where = "";
    $params = [];
    if (campoPreenchido($status)) {
        $where = "WHERE n.status_notificacao = ?";
        $params[] = $status;
    }

    $stmtTotal = $conexao->prepare("SELECT COUNT(*) FROM tb_notificacao n $where");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $sql = "
        SELECT n.id_notificacao, n.id_aluno, al.nome_aluno, n.id_responsavel, r.nome_responsavel,
               r.telefone_responsavel, r.email_responsavel, n.mensagem, n.status_notificacao,
               n.data_envio_notificacao
        FROM tb_notificacao n
        JOIN tb_aluno al ON al.id_aluno = n.id_aluno
        JOIN tb_responsavel r ON r.id_responsavel = n.id_responsavel
        $where
        ORDER BY n.id_notificacao DESC
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
    $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    enviarSucesso("Notificações carregadas com sucesso.", [
        "notificacoes"    => $notificacoes,
        "pagina_atual"    => $pagina,
        "total_paginas"   => (int) ceil($total / $porPagina),
        "total_registros" => $total,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as notificações.", 500, $erro);
}
