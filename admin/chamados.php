<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Chamados de suporte de todas as escolas (suporte/abrirChamado.php),
 * para a equipe do sistema responder. Antes a resposta só podia ser
 * escrita direto no banco.
 *
 *   { status?: aberto | em_andamento | respondido | fechado, pagina? }
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$status = $dados['status'] ?? null;
$pagina = max(1, (int) ($dados['pagina'] ?? 1));
$porPagina = 25;
if ($status !== null && !in_array($status, ['aberto', 'em_andamento', 'respondido', 'fechado'], true)) {
    enviarErro("Status inválido.", 422);
}

try {
    $onde = $status === null ? 'TRUE' : 'ch.status = ?';
    $parametros = $status === null ? [] : [$status];

    $total = $conexao->prepare("SELECT COUNT(*) FROM tb_chamado_suporte ch WHERE $onde");
    $total->execute($parametros);
    $totalRegistros = (int) $total->fetchColumn();

    $stmt = $conexao->prepare("
        SELECT ch.id_chamado, ch.categoria, ch.assunto, ch.mensagem, ch.status, ch.resposta, ch.respondido_em,
               ch.criado_em, ch.atualizado_em, c.nome_coordenacao, c.email_coordenacao, e.nome_escola
        FROM tb_chamado_suporte ch
        JOIN tb_coordenacao c ON c.id_coordenacao = ch.id_coordenacao
        JOIN tb_escola e ON e.id_escola = c.id_escola
        WHERE $onde
        ORDER BY (ch.status IN ('aberto', 'em_andamento')) DESC, ch.criado_em DESC
        LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina)
    );
    $stmt->execute($parametros);
    $chamados = array_map(function ($ch) {
        $ch['id_chamado'] = (int) $ch['id_chamado'];
        return $ch;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Chamados carregados.", [
        "chamados"        => $chamados,
        "pagina_atual"    => $pagina,
        "total_paginas"   => (int) ceil($totalRegistros / $porPagina),
        "total_registros" => $totalRegistros,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os chamados.", 500, $erro);
}
