<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/indicadores.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Lista de alunos da escola com as entradas atrasadas e saídas
 * antecipadas da janela de alerta (padrão: últimos 30 dias), as taxas e a
 * situação de cada um (helpers/indicadores.php).
 *
 *   { busca?: nome ou id, id_sala?, status?: Regular | Perto do limite |
 *     Acima do limite | Crítico, pagina?, por_pagina? }
 *
 * Antes a situação vinha das faltas ("Em risco" abaixo de 85% de
 * frequência) - que o BioFrequência não registra mais.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$busca     = isset($dados["busca"]) && is_string($dados["busca"]) ? trim($dados["busca"]) : '';
$idSala    = isset($dados["id_sala"]) && $dados["id_sala"] !== '' && $dados["id_sala"] !== null ? (int) $dados["id_sala"] : null;
$status    = $dados["status"] ?? null;
$pagina    = max(1, (int) ($dados["pagina"] ?? 1));
$porPagina = min(500, max(1, (int) ($dados["por_pagina"] ?? 10)));

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $config = configuracaoDaEscola($conexao, $idEscola);
    $janela = indicadoresDaJanela($conexao, $idEscola, $config, null, $idSala);

    $buscaNormalizada = mb_strtolower($busca);
    $lista = [];
    foreach ($janela['alunos'] as $indicador) {
        if ($busca !== '' && !str_contains(mb_strtolower($indicador['nome_aluno']), $buscaNormalizada)
            && (string) $indicador['id_aluno'] !== $busca) {
            continue;
        }
        $situacao = situacaoDoAluno($indicador['prioridade_atrasos'], $indicador['prioridade_saidas'], $indicador['dias_letivos']);
        if ($status !== null && $status !== '' && $situacao !== $status) {
            continue;
        }
        $lista[] = [
            "id_aluno"     => $indicador['id_aluno'],
            "nome_aluno"   => $indicador['nome_aluno'],
            "id_sala"      => $indicador['id_sala'],
            "turma"        => $indicador['nome_sala'],
            "atrasos"      => $indicador['atrasos'],
            "saidas"       => $indicador['saidas'],
            "dias_letivos" => $indicador['dias_letivos'],
            "taxa_atrasos" => $indicador['taxa_atrasos'],
            "taxa_saidas"  => $indicador['taxa_saidas'],
            "status"       => $situacao,
        ];
    }

    $totalRegistros = count($lista);
    enviarSucesso("Alunos carregados com sucesso.", [
        "alunos"          => array_slice($lista, ($pagina - 1) * $porPagina, $porPagina),
        "pagina_atual"    => $pagina,
        "total_paginas"   => (int) ceil($totalRegistros / $porPagina),
        "total_registros" => $totalRegistros,
        "janela"          => ["inicio" => $janela['inicio'], "fim" => $janela['fim'], "dias" => $config['janela_alerta_dias']],
        "limite"          => $config['limite_alerta_percentual'],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao buscar alunos.", 500, $erro);
}
