<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/registroEvento.php';
require_once __DIR__ . '/../helpers/avisos.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Entrada atrasada: o aluno chegou depois do início da aula mais a
 * tolerância da escola.
 *
 *   { id_aluno, dta_atraso?: "AAAA-MM-DD HH:MM" (padrão: agora),
 *     motivo_atraso?, observacao_atraso?, justificado_atraso? }
 *
 * 422 fora de dia de aula ou do horário da turma, ou dentro da
 * tolerância (helpers/calendario.php); 409 numa segunda entrada atrasada
 * no mesmo dia. Depois de gravar, os avisos aos responsáveis
 * (helpers/avisos.php) - a resposta diz o que saiu.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}

$idAluno     = (int) $dados["id_aluno"];
$motivo      = $dados["motivo_atraso"] ?? null;
$observacao  = $dados["observacao_atraso"] ?? null;
$justificado = !empty($dados["justificado_atraso"]);

if ($motivo !== null && (!is_string($motivo) || mb_strlen(trim($motivo)) > 150)) {
    enviarErro("O motivo deve ter no máximo 150 caracteres.", 422);
}
if ($observacao !== null && !is_string($observacao)) {
    enviarErro("Observação inválida.", 422);
}
$momentoUtc = lerMomentoDoEvento($dados["dta_atraso"] ?? null) ?? agoraUtc()->format('Y-m-d H:i:s');

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);
$contexto = exigirHorarioDoRegistro($conexao, 'atraso', $idAluno, $idEscola, $momentoUtc);

try {
    $conexao->beginTransaction();
    $atraso = registrarEventoFrequencia(
        $conexao, 'atraso', $idAluno, (int) $idCoordenacao, $momentoUtc, $motivo, $observacao, $justificado, ['origem' => 'manual']
    );
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao registrar a entrada atrasada.", 500, $erro);
}

if ($atraso['ja_existia']) {
    $dataLocal = dataBr(dataNaEscola($atraso['data']));
    enviarErro("Este aluno já tem entrada atrasada registrada em $dataLocal. Nada foi lançado em duplicidade.", 409);
}

$avisos = avisosDepoisDoRegistro(
    $conexao, $idEscola, $idAluno, $contexto['turma']['nome_aluno'], 'atraso', $atraso['data'], is_string($motivo) ? $motivo : null
);

enviarSucesso("Entrada atrasada registrada.", [
    "id_atraso"  => $atraso["id"],
    "dta_atraso" => $atraso["data"],
    "avisos"     => $avisos,
]);
