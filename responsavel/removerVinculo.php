<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Remove só o VÍNCULO entre aluno e responsável (tb_aluno_responsavel) -
 * o cadastro do responsável em si (tb_responsavel) não é apagado, pois
 * ele pode estar vinculado a outros alunos (irmãos).
 */
autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;
$idResponsavel = $dados["id_responsavel"] ?? null;

if (!inteiroValido($idAluno) || !inteiroValido($idResponsavel)) {
    enviarErro("Informe aluno e responsável válidos.", 422);
}

try {
    $delete = $conexao->prepare("DELETE FROM tb_aluno_responsavel WHERE id_aluno = ? AND id_responsavel = ?");
    $delete->bindValue(1, $idAluno);
    $delete->bindValue(2, $idResponsavel);
    $delete->execute();

    if ($delete->rowCount() === 0) {
        enviarErro("Vínculo não encontrado.", 404);
    }

    enviarSucesso("Vínculo removido.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao remover o vínculo.", 500, $erro);
}
