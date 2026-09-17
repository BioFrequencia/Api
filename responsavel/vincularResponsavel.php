<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;
$idResponsavel = $dados["id_responsavel"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!inteiroValido($idResponsavel)) {
    enviarErro("Informe um responsável válido.", 422);
}

try {
    $verificaAluno = $conexao->prepare("SELECT id_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindValue(1, $idAluno);
    $verificaAluno->execute();
    if ($verificaAluno->rowCount() === 0) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $verificaResponsavel = $conexao->prepare("SELECT id_responsavel FROM tb_responsavel WHERE id_responsavel = ?");
    $verificaResponsavel->bindValue(1, $idResponsavel);
    $verificaResponsavel->execute();
    if ($verificaResponsavel->rowCount() === 0) {
        enviarErro("Responsável não encontrado.", 404);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_aluno_responsavel (id_aluno, id_responsavel) VALUES (?, ?)
        ON CONFLICT (id_aluno, id_responsavel) DO NOTHING
    ");
    $insert->bindValue(1, $idAluno);
    $insert->bindValue(2, $idResponsavel);
    $insert->execute();

    enviarSucesso("Responsável vinculado ao aluno.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao vincular o responsável.", 500, $erro);
}
