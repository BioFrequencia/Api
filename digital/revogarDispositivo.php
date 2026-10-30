<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

// Desconecta o(s) celular(es) de um aluno - ex.: celular perdido ou
// trocado. A conta do app continua: o aluno entra de novo (e-mail e senha)
// no aparelho novo. Só para alunos da escola de quem chama.
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $update = $conexao->prepare("
        UPDATE tb_dispositivo_aluno SET ativo = FALSE, revogado_em = NOW()
        WHERE id_aluno = ? AND ativo = TRUE
    ");
    $update->bindValue(1, $idAluno);
    $update->execute();

    enviarSucesso("Dispositivo(s) revogado(s).", ["quantidade" => $update->rowCount()]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao revogar o dispositivo.", 500, $erro);
}
