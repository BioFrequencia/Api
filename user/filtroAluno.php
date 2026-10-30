<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/frequencia.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

// Faltava aqui: este endpoint devolve nome, turma, faltas e atrasos de
// todos os alunos e ficou de fora quando autenticação foi adicionada aos
// outros endpoints de aluno - dados de alunos não deveriam ficar públicos.
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$busca      = $dados["busca"] ?? null;          // nome ou id_aluno
$idSala     = $dados["id_sala"] ?? null;         // filtro de turma
$status     = $dados["status"] ?? null;          // filtro de status (aplicado após calcular)
$pagina     = max(1, (int)($dados["pagina"] ?? 1));
$porPagina  = max(1, (int)($dados["por_pagina"] ?? 10));

try {
    // Início do mês no fuso da escola (em UTC, como as colunas).
    $primeiroDiaMes = inicioDoMesUtc();

    // Faltas e atrasos são contados em subconsultas separadas e só depois
    // unidas ao aluno. Antes disso, um único LEFT JOIN em tb_atraso alimentava
    // as duas colunas (faltas E atrasos recebiam o mesmo número); juntar
    // tb_falta e tb_atraso num JOIN só, sem agregar antes, também geraria
    // contagem errada (produto cartesiano entre as duas tabelas).
    // Conta DIAS, não linhas: dois lançamentos no mesmo dia são uma falta.
    $sql = "
        SELECT al.id_aluno, al.nome_aluno, s.nome_sala,
               COALESCE(f.qtd_faltas, 0)  AS qtd_faltas,
               COALESCE(a.qtd_atrasos, 0) AS qtd_atrasos
        FROM tb_aluno al
        JOIN tb_sala s ON s.id_sala = al.id_sala
        LEFT JOIN (
            SELECT id_aluno, COUNT(DISTINCT " . sqlDataNaEscola('dta_falta') . ") AS qtd_faltas
            FROM tb_falta
            WHERE dta_falta >= ?
            GROUP BY id_aluno
        ) f ON f.id_aluno = al.id_aluno
        LEFT JOIN (
            SELECT id_aluno, COUNT(DISTINCT " . sqlDataNaEscola('dta_atraso') . ") AS qtd_atrasos
            FROM tb_atraso
            WHERE dta_atraso >= ?
            GROUP BY id_aluno
        ) a ON a.id_aluno = al.id_aluno
        WHERE s.id_escola = ?
    ";
    // O aluno pertence à escola pela turma: o JOIN com tb_sala deixou de
    // ser LEFT justamente para este filtro valer sempre.
    $idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
    $params = [$primeiroDiaMes, $primeiroDiaMes, $idEscola];

    if (!empty($busca)) {
        $sql .= " AND (al.nome_aluno ILIKE ? OR al.id_aluno::text = ?)";
        $params[] = "%$busca%";
        $params[] = $busca;
    }

    if (!empty($idSala)) {
        $sql .= " AND al.id_sala = ?";
        $params[] = $idSala;
    }

    $sql .= " ORDER BY al.nome_aluno";

    $stmt = $conexao->prepare($sql);
    $stmt->execute($params);
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcula frequência/status em PHP (mesma lógica de antes).
    // Observação/suposição: a frequência é calculada a partir das FALTAS
    // (ausências), não dos atrasos — são tratados como métricas distintas,
    // como no protótipo de tela. Se a intenção for outra fórmula, me avise.
    $listaCompleta = [];
    foreach ($alunos as $aluno) {
        $qtdFaltas  = (int)$aluno["qtd_faltas"];
        $qtdAtrasos = (int)$aluno["qtd_atrasos"];
        $frequencia = calcularFrequencia($qtdFaltas);
        $statusAluno = calcularStatusFrequencia($frequencia);

        if ($status !== null && $statusAluno !== $status) {
            continue; // aplica filtro de status
        }

        $listaCompleta[] = [
            "id_aluno"    => $aluno["id_aluno"],
            "nome_aluno"  => $aluno["nome_aluno"],
            "turma"       => $aluno["nome_sala"],
            "faltas"      => $qtdFaltas,
            "atrasos"     => $qtdAtrasos,
            "frequencia"  => $frequencia . "%",
            "status"      => $statusAluno
        ];
    }

    // Paginação feita em PHP, pois o status é calculado após a query
    $totalRegistros = count($listaCompleta);
    $totalPaginas = (int)ceil($totalRegistros / $porPagina);
    $offset = ($pagina - 1) * $porPagina;
    $paginaAtual = array_slice($listaCompleta, $offset, $porPagina);

    enviarSucesso("Alunos carregados com sucesso.", [
        "alunos" => $paginaAtual,
        "pagina_atual" => $pagina,
        "total_paginas" => $totalPaginas,
        "total_registros" => $totalRegistros
    ]);

} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao buscar alunos.", 500, $erro);
}
