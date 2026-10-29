<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/registroEvento.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Espelho de marcarFalta.php para tb_atraso (mesma estrutura de tabela,
 * confirmada no schema do Supabase). A regra de lançamento - um por
 * aluno por dia, trava contra envio simultâneo - fica em
 * helpers/registroEvento.php, compartilhada com a falta e com a
 * aprovação de ocorrências do app.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}

$idAluno      = (int) $dados["id_aluno"];
$motivoAtraso = $dados["motivo_atraso"] ?? null;
$observacao   = $dados["observacao_atraso"] ?? null;
$justificado  = !empty($dados["justificado_atraso"]);

if ($motivoAtraso !== null && (!is_string($motivoAtraso) || mb_strlen(trim($motivoAtraso)) > 150)) {
    enviarErro("O motivo deve ter no máximo 150 caracteres.", 422);
}
if ($observacao !== null && !is_string($observacao)) {
    enviarErro("Observação inválida.", 422);
}
$momentoUtc = lerMomentoDoEvento($dados["dta_atraso"] ?? null);

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);

try {
    $conexao->beginTransaction();
    $atraso = registrarEventoFrequencia(
        $conexao, 'atraso', $idAluno, (int) $idCoordenacao, $momentoUtc, $motivoAtraso, $observacao, $justificado
    );
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao registrar o atraso.", 500, $erro);
}

$dataLocal = date('d/m/Y', strtotime(dataNaEscola($atraso['data'])));
if ($atraso['ja_existia']) {
    enviarErro("Este aluno já tem atraso registrado em $dataLocal. Nada foi lançado em duplicidade.", 409);
}

$stmtNome = $conexao->prepare("SELECT nome_aluno FROM tb_aluno WHERE id_aluno = ?");
$stmtNome->execute([$idAluno]);
$nomeAluno = $stmtNome->fetchColumn();
$motivoTexto = $motivoAtraso !== null && trim($motivoAtraso) !== '' ? ". Motivo: " . trim($motivoAtraso) : ".";
criarNotificacoesParaEvento($conexao, $idAluno, "Atraso registrado para $nomeAluno em $dataLocal$motivoTexto");

enviarSucesso("Atraso registrado com sucesso.", [
    "id_atraso"  => $atraso["id"],
    "dta_atraso" => $atraso["data"],
]);
