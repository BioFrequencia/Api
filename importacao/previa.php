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
 * Prévia da importação de turmas e alunos: lê a planilha enviada
 * (multipart/form-data, campo "arquivo"), confere cada linha contra o que
 * a escola já tem e devolve o que seria feito - nada é gravado. A
 * confirmação (confirmar.php) recebe o mesmo arquivo e o "hash" daqui,
 * para garantir que o que entra é o que a coordenação revisou.
 *
 * Resposta: { arquivo: {nome, hash}, avisos: [...], resumo: {...},
 *   turmas: [{linha, nome, periodo, inicio, fim, situacao: nova|existente|erro, erros, avisos}],
 *   alunos: [{linha, nome, turma, nascimento, genero, responsavel, situacao: novo|existente|erro, erros, avisos}] }
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $arquivo = arquivoDaImportacao($_FILES['arquivo'] ?? null);
    $abas = lerAbasDaImportacao($arquivo['caminho'], $arquivo['extensao']);
} catch (PlanilhaInvalida $erro) {
    enviarErro($erro->getMessage(), 422);
}

try {
    $escola = dadosDaEscolaParaImportacao($conexao, $idEscola, $abas);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao conferir a planilha.", 500, $erro);
}
$previa = montarPreviaDaImportacao($abas, $escola);
$resumo = $previa['resumo'];
$entram = $resumo['turmas_novas'] + $resumo['alunos_novos'];
$mensagem = $entram === 0
    ? "Nada novo para cadastrar nesta planilha."
    : "Prévia pronta: confira e confirme para cadastrar.";
enviarSucesso($mensagem, [
    'arquivo' => ['nome' => $arquivo['nome'], 'hash' => $arquivo['hash']],
    'avisos' => $abas['avisos'],
] + $previa);
