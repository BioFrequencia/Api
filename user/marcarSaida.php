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
 * Saída antecipada: o aluno saiu antes do fim da aula (migração 008).
 * Mesmas regras da entrada atrasada (user/marcarAtraso.php): só num dia
 * de aula, dentro do horário da turma, uma por aluno por dia.
 *
 *   { id_aluno, dta_saida?: "AAAA-MM-DD HH:MM" (padrão: agora),
 *     motivo_saida?, observacao_saida?, justificado_saida?, retirado_por? }
 *
 * retirado_por: quem buscou o aluno (a escola só libera menor com um
 * adulto autorizado).
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}

$idAluno     = (int) $dados["id_aluno"];
$motivo      = $dados["motivo_saida"] ?? null;
$observacao  = $dados["observacao_saida"] ?? null;
$justificado = !empty($dados["justificado_saida"]);
$retiradoPor = $dados["retirado_por"] ?? null;

if ($motivo !== null && (!is_string($motivo) || mb_strlen(trim($motivo)) > 150)) {
    enviarErro("O motivo deve ter no máximo 150 caracteres.", 422);
}
if ($observacao !== null && !is_string($observacao)) {
    enviarErro("Observação inválida.", 422);
}
if ($retiradoPor !== null && (!is_string($retiradoPor) || mb_strlen(trim($retiradoPor)) > 150)) {
    enviarErro("O nome de quem buscou o aluno deve ter no máximo 150 caracteres.", 422);
}
$retiradoPor = is_string($retiradoPor) && trim($retiradoPor) !== '' ? trim($retiradoPor) : null;
$momentoUtc = lerMomentoDoEvento($dados["dta_saida"] ?? null) ?? agoraUtc()->format('Y-m-d H:i:s');

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);
$contexto = exigirHorarioDoRegistro($conexao, 'saida', $idAluno, $idEscola, $momentoUtc);

try {
    $conexao->beginTransaction();
    $saida = registrarEventoFrequencia(
        $conexao, 'saida', $idAluno, (int) $idCoordenacao, $momentoUtc, $motivo, $observacao, $justificado,
        ['retirado_por' => $retiradoPor]
    );
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao registrar a saída antecipada.", 500, $erro);
}

if ($saida['ja_existia']) {
    $dataLocal = dataBr(dataNaEscola($saida['data']));
    enviarErro("Este aluno já tem saída antecipada registrada em $dataLocal. Nada foi lançado em duplicidade.", 409);
}

$avisos = avisosDepoisDoRegistro(
    $conexao, $idEscola, $idAluno, $contexto['turma']['nome_aluno'], 'saida', $saida['data'],
    is_string($motivo) ? $motivo : null, $retiradoPor
);

enviarSucesso("Saída antecipada registrada.", [
    "id_saida"  => $saida["id"],
    "dta_saida" => $saida["data"],
    "avisos"    => $avisos,
]);
