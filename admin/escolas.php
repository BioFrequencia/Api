<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/catalogoEscolas.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Escolas para a equipe do sistema: busca no catálogo inteiro (ou só nas
 * que usam o sistema), com quantas contas, turmas e alunos cada uma tem.
 *
 *   { busca?, em_uso?: bool, pagina? }   25 por página
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$busca = normalizarTextoBusca(is_string($dados['busca'] ?? null) ? $dados['busca'] : '');
$emUso = ($dados['em_uso'] ?? false) === true;
$pagina = max(1, (int) ($dados['pagina'] ?? 1));
$porPagina = 25;

$condicoes = ['TRUE'];
$parametros = [];
foreach ($busca === '' ? [] : array_slice(explode(' ', $busca), 0, 6) as $termo) {
    $condicoes[] = "(upper(e.nome_escola) LIKE ? OR COALESCE(e.nome_oficial, '') LIKE ? OR COALESCE(e.municipio, '') LIKE ? OR e.codigo_cie::text = ?)";
    array_push($parametros, "%$termo%", "%$termo%", "%$termo%", $termo);
}
if ($emUso) {
    $condicoes[] = "EXISTS (SELECT 1 FROM tb_coordenacao c WHERE c.id_escola = e.id_escola)";
}
$onde = implode(' AND ', $condicoes);

try {
    $total = $conexao->prepare("SELECT COUNT(*) FROM tb_escola e WHERE $onde");
    $total->execute($parametros);
    $totalRegistros = (int) $total->fetchColumn();

    $stmt = $conexao->prepare("
        SELECT e.id_escola, e.nome_escola, e.codigo_cie, e.municipio, e.diretoria_ensino, e.bairro,
               (SELECT COUNT(*) FROM tb_coordenacao c WHERE c.id_escola = e.id_escola AND c.situacao = 'ativo') AS contas_ativas,
               (SELECT COUNT(*) FROM tb_coordenacao c WHERE c.id_escola = e.id_escola AND c.situacao = 'pendente') AS contas_pendentes,
               (SELECT COUNT(*) FROM tb_sala s WHERE s.id_escola = e.id_escola) AS turmas,
               (SELECT COUNT(*) FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala WHERE s.id_escola = e.id_escola) AS alunos
        FROM tb_escola e
        WHERE $onde
        ORDER BY (SELECT COUNT(*) FROM tb_coordenacao c WHERE c.id_escola = e.id_escola) DESC, e.nome_escola
        LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina)
    );
    $stmt->execute($parametros);
    $escolas = array_map(function ($e) {
        foreach (['id_escola', 'contas_ativas', 'contas_pendentes', 'turmas', 'alunos'] as $campo) {
            $e[$campo] = (int) $e[$campo];
        }
        $e['codigo_cie'] = $e['codigo_cie'] === null ? null : (int) $e['codigo_cie'];
        return $e;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Escolas carregadas.", [
        "escolas"         => $escolas,
        "pagina_atual"    => $pagina,
        "total_paginas"   => (int) ceil($totalRegistros / $porPagina),
        "total_registros" => $totalRegistros,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as escolas.", 500, $erro);
}
