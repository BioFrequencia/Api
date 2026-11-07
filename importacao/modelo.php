<?php
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/planilha.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * O modelo da importação de turmas e alunos (.xlsx), para download: as
 * abas Instruções, Turmas e Alunos, com as listas de período, gênero e
 * canal, e as turmas que a escola já tem (na lista da coluna Turma e nas
 * instruções). Por isso pede login: o modelo é da escola de quem baixa.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $busca = $conexao->prepare("SELECT nome_sala FROM tb_sala WHERE id_escola = ? ORDER BY nome_sala");
    $busca->execute([$idEscola]);
    $planilha = modeloDaImportacao($busca->fetchAll(PDO::FETCH_COLUMN));
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
header('Content-Disposition: attachment; filename="modelo-turmas-e-alunos.xlsx"');
header('Content-Length: ' . strlen($conteudo));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $conteudo;
