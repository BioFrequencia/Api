<?php
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/planilha.php';
require_once __DIR__ . '/../helpers/importacaoOcorrenciasEscola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * O modelo (.xlsx) para registrar entradas atrasadas e saídas
 * antecipadas sem internet: Instruções, Ocorrências (para preencher) e
 * Alunos (código, nome, turma e período de todos os alunos da escola).
 * Pede login: a lista de alunos é da escola de quem baixa.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $planilha = modeloDeOcorrencias(alunosDaEscolaParaOcorrencias($conexao, $idEscola), hojeNaEscola());
    $escritor = IOFactory::createWriter($planilha, 'Xlsx');
    // Gera em memória antes de mandar os cabeçalhos: se falhar, a resposta ainda pode ser o erro em JSON.
    ob_start();
    $escritor->save('php://output');
    $conteudo = (string) ob_get_clean();
} catch (Throwable $erro) {
    if (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    enviarErro("Não foi possível gerar o modelo da planilha.", 500, $erro);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="modelo-entradas-e-saidas.xlsx"');
header('Content-Length: ' . strlen($conteudo));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $conteudo;
