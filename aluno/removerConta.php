<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Remove a conta do app de um aluno e desconecta os celulares dele - para
 * quando a conta foi criada por outra pessoa com os dados do aluno, ou o
 * aluno perdeu o acesso ao e-mail. Depois disso ele pode criar uma conta
 * nova no app. Só a coordenação, e só para alunos da própria escola.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $conexao->beginTransaction();

    $remove = $conexao->prepare("DELETE FROM tb_conta_aluno WHERE id_aluno = ?");
    $remove->bindValue(1, $idAluno);
    $remove->execute();
    $contaRemovida = $remove->rowCount() > 0;

    $desconecta = $conexao->prepare("
        UPDATE tb_dispositivo_aluno SET ativo = FALSE, revogado_em = NOW()
        WHERE id_aluno = ? AND ativo = TRUE
    ");
    $desconecta->bindValue(1, $idAluno);
    $desconecta->execute();

    $conexao->commit();

    if (!$contaRemovida) {
        enviarErro("Este aluno não tem conta no app.", 404);
    }

    enviarSucesso("Conta do app removida.", ["celulares_desconectados" => $desconecta->rowCount()]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao remover a conta do app.", 500, $erro);
}
