<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Apaga a biometria de um aluno (LGPD: eliminação a pedido do titular ou
 * do responsável, ou cadastro errado).
 *
 *   { id_aluno, tipo: "facial" | "digital" | "todas" }
 *
 * O consentimento continua registrado - para revogá-lo também, use
 * consentimento.php com revogar: true. As presenças já registradas não
 * são apagadas: são o histórico de frequência, não dado biométrico.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;
$tipo = $dados["tipo"] ?? 'todas';

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
$tabelas = [
    'facial'  => ['tb_biometria_facial'],
    'digital' => ['tb_biometria_digital'],
    'todas'   => ['tb_biometria_facial', 'tb_biometria_digital'],
][$tipo] ?? null;
if ($tabelas === null) {
    enviarErro("Tipo inválido: use facial, digital ou todas.", 422);
}

exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $conexao->beginTransaction();
    $apagados = 0;
    foreach ($tabelas as $tabela) {
        $apaga = $conexao->prepare("DELETE FROM $tabela WHERE id_aluno = ?");
        $apaga->bindValue(1, $idAluno);
        $apaga->execute();
        $apagados += $apaga->rowCount();
    }
    $conexao->commit();

    if ($apagados === 0) {
        enviarErro("Este aluno não tinha biometria cadastrada.", 404);
    }
    enviarSucesso("Biometria apagada.", ["registros_apagados" => $apagados]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao apagar a biometria.", 500, $erro);
}
