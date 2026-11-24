<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/planilha.php';
require_once __DIR__ . '/../helpers/importacaoOcorrenciasEscola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Prévia da importação de entradas atrasadas e saídas antecipadas
 * registradas sem internet: lê a planilha (multipart/form-data, campo
 * "arquivo"), confere cada linha com as regras do lançamento manual e com
 * o que já está registrado, e devolve o que seria feito - nada é gravado.
 * A confirmação (confirmarImportacao.php) recebe o mesmo arquivo e o
 * "hash" daqui.
 *
 * Resposta: { arquivo: {nome, hash}, avisos: [...],
 *   resumo: {novas, existentes, com_erro, atrasos_novos, saidas_novas, alunos_novos, turmas_novas, ja_cadastrados},
 *   alunos_novos: [{chave, nome, turma}], turmas_novas: [{chave, nome, periodo}],
 *   linhas: [{linha, aba, situacao: nova|cadastro|existente|erro, id_aluno, aluno, turma, aluno_novo, turma_nova,
 *             data, hora, tipo: atraso|saida|null, motivo, justificado, observacao, retirado_por, erros, avisos}] }
 * "cadastro": linha só com o nome e a turma de um aluno que a escola ainda não tem.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $arquivo = arquivoDaImportacao($_FILES['arquivo'] ?? null);
    $lido = lerOcorrenciasDaImportacao($arquivo['caminho'], $arquivo['extensao']);
} catch (PlanilhaInvalida $erro) {
    enviarErro($erro->getMessage(), 422);
}

try {
    $escola = dadosDaEscolaParaOcorrencias($conexao, $idEscola, $lido['linhas']);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao conferir a planilha.", 500, $erro);
}
$previa = montarPreviaDeOcorrencias($lido['linhas'], $escola, agoraNaEscolaParaPrevia());
$resumo = $previa['resumo'];
if ($previa['linhas'] === []) {
    // Só alunos que a escola já tem e nenhuma entrada/saída (ex.: o modelo baixado e não preenchido).
    enviarErro("Nada para importar: a aba Ocorrências está vazia e os {$resumo['ja_cadastrados']} alunos da planilha já estão cadastrados. "
        . "Preencha uma linha por entrada atrasada ou saída antecipada.", 422, null, ['resumo' => $resumo]);
}
$mensagem = $resumo['novas'] === 0 && $resumo['alunos_novos'] === 0
    ? "Nada novo para registrar nesta planilha."
    : "Prévia pronta: confira e confirme para registrar.";
enviarSucesso($mensagem, [
    'arquivo' => ['nome' => $arquivo['nome'], 'hash' => $arquivo['hash']],
    'avisos' => $lido['avisos'],
] + $previa);
