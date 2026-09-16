<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

try {
    $stmt = $conexao->prepare("SELECT modelo, data_cadastro, atualizado_em FROM tb_biometria_facial WHERE id_aluno = ?");
    $stmt->bindParam(1, $idAluno);
    $stmt->execute();
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Status carregado com sucesso.", [
        "cadastrado" => $registro !== false,
        "modelo" => $registro['modelo'] ?? null,
        "data_cadastro" => $registro['data_cadastro'] ?? null,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao consultar a biometria facial.", 500, $erro);
}
