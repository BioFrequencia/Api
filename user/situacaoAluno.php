<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/anoLetivo.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão tira UM aluno da escola fora do fim do ano (transferência) ou
 * traz de volta quem saiu por engano.
 *
 *   { id_aluno, situacao: "transferido" | "concluiu" | "ativo" }
 *
 * Quem sai some das listas e do app, com o histórico guardado; a
 * biometria é apagada (quem volta cadastra o rosto de novo).
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados['id_aluno'] ?? null;
$situacao = $dados['situacao'] ?? null;
if (!inteiroValido($idAluno)) {
    enviarErro("Aluno inválido.", 400);
}
if (!in_array($situacao, SITUACOES_ALUNO, true)) {
    enviarErro("Situação inválida: use transferido, concluiu ou ativo.", 422);
}
exigirAlunoDaEscola($conexao, (int) $idAluno, $conta['id_escola']);

try {
    $conexao->beginTransaction();
    $stmt = $conexao->prepare("
        UPDATE tb_aluno
        SET situacao_aluno = ?, saiu_em = CASE WHEN ? = 'ativo' THEN NULL ELSE NOW() END
        WHERE id_aluno = ? AND situacao_aluno <> ?
    ");
    $stmt->execute([$situacao, $situacao, (int) $idAluno, $situacao]);
    $mudou = $stmt->rowCount() > 0;
    $biometrias = $mudou && $situacao !== 'ativo' ? apagarBiometriaDe($conexao, [(int) $idAluno]) : 0;
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao mudar a situação do aluno.", 500, $erro);
}
if (!$mudou) {
    enviarErro("O aluno já está nessa situação.", 409);
}
$mensagem = $situacao === 'ativo'
    ? "Aluno de volta à turma. Se ele usava a biometria, cadastre o rosto de novo."
    : "Aluno retirado da escola. O histórico continua guardado.";
enviarSucesso($mensagem, ["situacao" => $situacao, "biometrias_apagadas" => $biometrias]);
