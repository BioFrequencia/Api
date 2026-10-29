<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/biometriaFacial.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
$embedding = $dados["embedding"] ?? null;
$modelo = $dados["modelo"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!embeddingValido($embedding)) {
    enviarErro("Embedding facial inválido ou ausente.", 422);
}
if (!campoPreenchido($modelo)) {
    enviarErro("Informe o identificador do modelo usado para gerar o embedding.", 422);
}

// Dado biométrico: só para alunos da escola de quem cadastra (antes
// bastava o id existir).
exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $embeddingJson = json_encode(array_map('floatval', $embedding));

    $upsert = $conexao->prepare("
        INSERT INTO tb_biometria_facial (id_aluno, embedding, modelo, id_coordenacao)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (id_aluno) DO UPDATE SET
            embedding = EXCLUDED.embedding,
            modelo = EXCLUDED.modelo,
            id_coordenacao = EXCLUDED.id_coordenacao,
            atualizado_em = NOW()
    ");
    $upsert->bindValue(1, $idAluno);
    $upsert->bindValue(2, $embeddingJson);
    $upsert->bindValue(3, $modelo);
    $upsert->bindValue(4, $idCoordenacao);
    $upsert->execute();

    enviarSucesso("Biometria facial cadastrada com sucesso.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao cadastrar a biometria facial.", 500, $erro);
}
