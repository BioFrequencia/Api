<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/** Chamados de suporte abertos por esta conta, do mais recente ao mais antigo. */
$idCoordenacao = (int) autenticar();

try {
    $stmt = $conexao->prepare("
        SELECT id_chamado, categoria, assunto, mensagem, status, resposta, respondido_em, criado_em, atualizado_em
        FROM tb_chamado_suporte
        WHERE id_coordenacao = ?
        ORDER BY criado_em DESC
        LIMIT 100
    ");
    $stmt->execute([$idCoordenacao]);
    enviarSucesso("Chamados de suporte.", $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao listar os chamados.", 500, $erro);
}
