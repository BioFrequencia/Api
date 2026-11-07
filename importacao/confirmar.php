<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/planilha.php';
require_once __DIR__ . '/../helpers/importacaoEscola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Confirma a importação de turmas e alunos: recebe de novo a planilha
 * (multipart/form-data: "arquivo" e o "hash" que a prévia devolveu) e
 * cadastra as linhas novas numa transação só - turmas, alunos e
 * responsáveis entram juntos ou nada entra.
 *
 * - O hash garante que o arquivo é o mesmo que a coordenação revisou.
 * - A escola fica travada durante a gravação (pg_advisory_xact_lock):
 *   duas importações ao mesmo tempo não cadastram o mesmo aluno duas vezes.
 * - A conferência é refeita dentro da transação, com o banco de agora:
 *   o que outra pessoa cadastrou depois da prévia fica de fora.
 * - Linhas com erro e o que a escola já tem não entram (a prévia mostrou).
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $arquivo = arquivoDaImportacao($_FILES['arquivo'] ?? null);
} catch (PlanilhaInvalida $erro) {
    enviarErro($erro->getMessage(), 422);
}
$hashDaPrevia = $_POST['hash'] ?? null;
if (!is_string($hashDaPrevia) || !hash_equals($arquivo['hash'], $hashDaPrevia)) {
    enviarErro("O arquivo não é o mesmo da prévia. Gere a prévia de novo antes de confirmar.", 409);
}
try {
    $abas = lerAbasDaImportacao($arquivo['caminho'], $arquivo['extensao']);
} catch (PlanilhaInvalida $erro) {
    enviarErro($erro->getMessage(), 422);
}

try {
    $conexao->beginTransaction();
    // 0x42494f = "BIO": separa esta trava das outras travas consultivas.
    $conexao->prepare("SELECT pg_advisory_xact_lock(4344143, ?)")->execute([$idEscola]);
    $escola = dadosDaEscolaParaImportacao($conexao, $idEscola, $abas);
    $previa = montarPreviaDaImportacao($abas, $escola);
    if ($previa['resumo']['turmas_novas'] + $previa['resumo']['alunos_novos'] === 0) {
        $conexao->rollBack();
        enviarErro("Nada novo para cadastrar: tudo o que é válido nesta planilha a escola já tem.", 422, null, ['resumo' => $previa['resumo']]);
    }
    $resultado = gravarImportacao($conexao, $idEscola, $previa, $escola['turmas']);
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    $sqlState = $erro->errorInfo[0] ?? $erro->getCode();
    if ($sqlState === '23505') {
        enviarErro("Algum dado da planilha acabou de ser cadastrado por outra pessoa. Gere a prévia de novo.", 409, $erro);
    }
    enviarErro("Erro no servidor ao importar a planilha. Nada foi cadastrado.", 500, $erro);
}

$partes = [];
if ($resultado['turmas_criadas'] > 0) $partes[] = $resultado['turmas_criadas'] . ' turma(s)';
if ($resultado['alunos_criados'] > 0) $partes[] = $resultado['alunos_criados'] . ' aluno(s)';
if ($resultado['responsaveis_criados'] > 0) $partes[] = $resultado['responsaveis_criados'] . ' responsável(is)';
enviarSucesso("Importação concluída: " . implode(', ', $partes) . " cadastrado(s).", $resultado + ['resumo' => $previa['resumo']], 201);
