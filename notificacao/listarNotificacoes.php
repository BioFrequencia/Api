<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Fila de notificações da escola de quem está logado. Antes listava as
 * notificações de todas as escolas - com nome, telefone e email dos
 * responsáveis.
 *
 * status (opcional): "pendente" | "enviada" | "erro". O banco guarda
 * 'Pendente'/'Enviado'/'Erro' (ver helpers/notificacao.php); a resposta
 * continua no formato que o site e o desktop já usam.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$status = $dados["status"] ?? null;
$pagina = max(1, (int) ($dados["pagina"] ?? 1));
$porPagina = max(1, min(100, (int) ($dados["por_pagina"] ?? 20)));
$offset = ($pagina - 1) * $porPagina;

$statusBanco = null;
if (campoPreenchido($status)) {
    $statusBanco = statusNotificacaoParaBanco($status);
    if ($statusBanco === null) {
        enviarErro("Situação inválida (use pendente, enviada ou erro).", 422);
    }
}

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $where = "WHERE s.id_escola = ?";
    $params = [$idEscola];
    if ($statusBanco !== null) {
        $where .= " AND n.status_notificacao = ?";
        $params[] = $statusBanco;
    }
    $origem = "
        FROM tb_notificacao n
        JOIN tb_aluno al ON al.id_aluno = n.id_aluno
        JOIN tb_sala  s  ON s.id_sala   = al.id_sala
        JOIN tb_responsavel r ON r.id_responsavel = n.id_responsavel
        $where
    ";

    $stmtTotal = $conexao->prepare("SELECT COUNT(*) $origem");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $stmt = $conexao->prepare("
        SELECT n.id_notificacao, n.id_aluno, al.nome_aluno, n.id_responsavel, r.nome_responsavel,
               r.telefone_responsavel, r.email_responsavel, n.mensagem, n.status_notificacao,
               n.data_envio_notificacao, n.canal, n.nivel_alerta
        $origem
        ORDER BY n.id_notificacao DESC
        LIMIT ? OFFSET ?
    ");
    $indice = 1;
    foreach ($params as $valor) {
        $stmt->bindValue($indice++, $valor);
    }
    $stmt->bindValue($indice++, $porPagina, PDO::PARAM_INT);
    $stmt->bindValue($indice++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notificacoes = array_map(function ($n) {
        $n['status_notificacao'] = statusNotificacaoParaApi($n['status_notificacao']);
        $n['nivel_alerta'] = $n['nivel_alerta'] !== null ? (int) $n['nivel_alerta'] : null;
        return $n;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Notificações carregadas com sucesso.", [
        "notificacoes"    => $notificacoes,
        "pagina_atual"    => $pagina,
        "total_paginas"   => (int) ceil($total / $porPagina),
        "total_registros" => $total,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as notificações.", 500, $erro);
}
