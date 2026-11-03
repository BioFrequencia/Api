<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Contas de coordenação de todas as escolas, para a equipe do sistema.
 *
 *   { busca?: nome, email ou escola, situacao?: pendente | ativo | recusado | desativado, pagina? }
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$busca = is_string($dados['busca'] ?? null) ? mb_strtolower(trim($dados['busca'])) : '';
$situacao = $dados['situacao'] ?? null;
$pagina = max(1, (int) ($dados['pagina'] ?? 1));
$porPagina = 25;

if ($situacao !== null && !in_array($situacao, ['pendente', 'ativo', 'recusado', 'desativado'], true)) {
    enviarErro("Situação inválida.", 422);
}

$condicoes = ['TRUE'];
$parametros = [];
if ($busca !== '') {
    $condicoes[] = "(lower(c.nome_coordenacao) LIKE ? OR lower(c.email_coordenacao) LIKE ? OR lower(e.nome_escola) LIKE ?)";
    array_push($parametros, "%$busca%", "%$busca%", "%$busca%");
}
if ($situacao !== null) {
    $condicoes[] = "c.situacao = ?";
    $parametros[] = $situacao;
}
$onde = implode(' AND ', $condicoes);

try {
    $total = $conexao->prepare("SELECT COUNT(*) FROM tb_coordenacao c JOIN tb_escola e ON e.id_escola = c.id_escola WHERE $onde");
    $total->execute($parametros);
    $totalRegistros = (int) $total->fetchColumn();

    $stmt = $conexao->prepare("
        SELECT c.id_coordenacao, c.nome_coordenacao, c.email_coordenacao, c.papel, c.situacao, c.criado_em,
               c.email_verificado_em IS NOT NULL AS email_verificado, e.id_escola, e.nome_escola, e.municipio,
               (SELECT MAX(s.ultimo_acesso) FROM tb_sessao s WHERE s.id_coordenacao = c.id_coordenacao) AS ultimo_acesso
        FROM tb_coordenacao c
        JOIN tb_escola e ON e.id_escola = c.id_escola
        WHERE $onde
        ORDER BY (c.situacao = 'pendente') DESC, (c.email_verificado_em IS NOT NULL) DESC, c.criado_em DESC NULLS LAST, c.id_coordenacao DESC
        LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina)
    );
    $stmt->execute($parametros);
    $contas = array_map(function ($c) {
        $c['id_coordenacao'] = (int) $c['id_coordenacao'];
        $c['id_escola'] = (int) $c['id_escola'];
        $c['administrador'] = ehAdministrador($c['email_coordenacao']);
        $c['email_verificado'] = valorBooleano($c['email_verificado']);
        return $c;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Contas carregadas.", [
        "contas"          => $contas,
        "pagina_atual"    => $pagina,
        "total_paginas"   => (int) ceil($totalRegistros / $porPagina),
        "total_registros" => $totalRegistros,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as contas.", 500, $erro);
}
