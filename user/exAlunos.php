<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/tempo.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Quem já saiu da escola (concluiu ou foi transferido, migração 014), do
 * mais recente para o mais antigo. A ficha de cada um continua aberta.
 *
 *   { busca?: "nome", pagina?: 1 } -> { alunos: [{ id_aluno, nome_aluno, turma, situacao, saiu_em }], pagina_atual, total_paginas, total_registros }
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$busca = is_string($dados['busca'] ?? null) ? trim($dados['busca']) : '';
$pagina = max(1, (int) ($dados['pagina'] ?? 1));
$porPagina = 20;

try {
    $filtro = "FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala
               WHERE s.id_escola = ? AND a.situacao_aluno <> 'ativo' AND (? = '' OR a.nome_aluno ILIKE ?)";
    $parametros = [$idEscola, $busca, '%' . addcslashes($busca, '%_\\') . '%'];
    $contagem = $conexao->prepare("SELECT COUNT(*) $filtro");
    $contagem->execute($parametros);
    $total = (int) $contagem->fetchColumn();

    $stmt = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, s.nome_sala AS turma, a.situacao_aluno AS situacao,
               to_char(a.saiu_em AT TIME ZONE 'UTC' AT TIME ZONE '" . FUSO_ESCOLA . "', 'YYYY-MM-DD') AS saiu_em
        $filtro
        ORDER BY a.saiu_em DESC NULLS LAST, a.nome_aluno
        LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina)
    );
    $stmt->execute($parametros);
    $alunos = array_map(function ($linha) {
        $linha['id_aluno'] = (int) $linha['id_aluno'];
        return $linha;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao listar quem saiu.", 500, $erro);
}
enviarSucesso("Ex-alunos carregados.", [
    "alunos" => $alunos,
    "pagina_atual" => $pagina,
    "total_paginas" => max(1, (int) ceil($total / $porPagina)),
    "total_registros" => $total,
]);
