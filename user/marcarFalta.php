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

// O id da coordenação vem do token validado, não do corpo da requisição
// - antes disso, qualquer requisição podia "assinar" a falta em nome de
// outra coordenação.
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}

$idAluno     = (int) $dados["id_aluno"];
$motivoFalta = $dados["motivo_falta"] ?? null;
$observacao  = $dados["observacao_falta"] ?? null;
$justificado = !empty($dados["justificado_falta"]);

if ($motivoFalta !== null && (!is_string($motivoFalta) || mb_strlen(trim($motivoFalta)) > 150)) {
    enviarErro("O motivo deve ter no máximo 150 caracteres.", 422);
}
if ($observacao !== null && !is_string($observacao)) {
    enviarErro("Observação inválida.", 422);
}
// Se não vier, vale o momento atual. Se vier, é horário local da escola.
$momentoUtc = lerMomentoDoEvento($dados["dta_falta"] ?? null);

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
// O aluno precisa ser da escola de quem está lançando: sem isso,
// bastaria mandar um id_aluno de outra escola no corpo da requisição.
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);

try {
    $conexao->beginTransaction();
    $falta = registrarEventoFrequencia(
        $conexao, 'falta', $idAluno, (int) $idCoordenacao, $momentoUtc, $motivoFalta, $observacao, $justificado
    );
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao registrar a falta.", 500, $erro);
}

$dataLocal = date('d/m/Y', strtotime(dataNaEscola($falta['data'])));
if ($falta['ja_existia']) {
    enviarErro("Este aluno já tem falta registrada em $dataLocal. Nada foi lançado em duplicidade.", 409);
}

$stmtNome = $conexao->prepare("SELECT nome_aluno FROM tb_aluno WHERE id_aluno = ?");
$stmtNome->execute([$idAluno]);
$nomeAluno = $stmtNome->fetchColumn();
$motivoTexto = $motivoFalta !== null && trim($motivoFalta) !== '' ? ". Motivo: " . trim($motivoFalta) : ".";
criarNotificacoesParaEvento($conexao, $idAluno, "Falta registrada para $nomeAluno em $dataLocal$motivoTexto");

enviarSucesso("Falta registrada com sucesso.", [
    "id_falta"  => $falta["id"],
    "dta_falta" => $falta["data"],
]);
