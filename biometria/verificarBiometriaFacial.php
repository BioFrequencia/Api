<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/biometriaFacial.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Recebe o embedding "ao vivo" (calculado no desktop a partir da foto
 * tirada na hora) e compara com o embedding cadastrado do aluno. A
 * comparação acontece aqui (servidor), não no desktop, para o embedding
 * de referência nunca precisar sair do banco - só o resultado
 * (bateu/não bateu + similaridade) volta para o cliente.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
$embeddingVivo = $dados["embedding"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!embeddingValido($embeddingVivo)) {
    enviarErro("Embedding facial inválido ou ausente.", 422);
}

try {
    $stmt = $conexao->prepare("SELECT embedding FROM tb_biometria_facial WHERE id_aluno = ?");
    $stmt->bindParam(1, $idAluno);
    $stmt->execute();
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        enviarErro("Este aluno ainda não tem biometria facial cadastrada.", 404);
    }

    $embeddingCadastrado = json_decode($registro['embedding'], true);
    if (!is_array($embeddingCadastrado) || count($embeddingCadastrado) !== count($embeddingVivo)) {
        enviarErro("O embedding cadastrado é incompatível com o modelo usado agora (dimensões diferentes). Recadastre a biometria facial deste aluno.", 422);
    }

    $similaridade = similaridadeCosseno($embeddingCadastrado, array_map('floatval', $embeddingVivo));
    $correspondeu = $similaridade >= LIMIAR_SIMILARIDADE_FACIAL;

    $insert = $conexao->prepare("
        INSERT INTO tb_verificacao_facial (id_aluno, id_coordenacao, similaridade, correspondeu)
        VALUES (?, ?, ?, ?)
    ");
    $insert->bindParam(1, $idAluno);
    $insert->bindParam(2, $idCoordenacao);
    $insert->bindParam(3, $similaridade);
    $insert->bindValue(4, $correspondeu ? 'true' : 'false');
    $insert->execute();

    enviarSucesso($correspondeu ? "Identidade confirmada." : "Rosto não corresponde ao cadastro.", [
        "correspondeu" => $correspondeu,
        "similaridade" => round($similaridade, 4),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao verificar a biometria facial.", 500, $erro);
}
