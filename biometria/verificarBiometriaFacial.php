<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/biometriaFacial.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Verificação 1:1: "este rosto é do aluno X?" - sem registrar presença.
 * Para identificar QUEM é o aluno e registrar a presença, o app usa
 * identificarFacial.php (1:N). A comparação acontece aqui, no servidor:
 * o cadastro do aluno nunca sai do banco, só o resultado.
 */
$idCoordenacao = autenticar();
$chave = exigirChaveBiometria();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
$amostra = $dados["embedding"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!embeddingValido($amostra)) {
    enviarErro("Amostra facial inválida ou ausente.", 422);
}

exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $stmt = $conexao->prepare("SELECT id_aluno, embedding, cifrado FROM tb_biometria_facial WHERE id_aluno = ?");
    $stmt->bindValue(1, $idAluno);
    $stmt->execute();
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        enviarErro("Este aluno ainda não tem biometria facial cadastrada.", 404);
    }

    $cadastradas = lerAmostrasFaciais($registro, $chave);
    if ($cadastradas === null || count($cadastradas[0]) !== count($amostra)) {
        enviarErro("O cadastro facial deste aluno não é compatível com o modelo usado agora. Recadastre a biometria facial.", 422);
    }

    $distancia = INF;
    foreach ($cadastradas as $cadastrada) {
        $distancia = min($distancia, distanciaEuclidiana(normalizarVetor($cadastrada), normalizarVetor($amostra)));
    }
    $correspondeu = $distancia <= DISTANCIA_MAXIMA_FACIAL;

    // tb_verificacao_facial (003) guarda "similaridade": aqui vai 1 - distância.
    $insert = $conexao->prepare("
        INSERT INTO tb_verificacao_facial (id_aluno, id_coordenacao, similaridade, correspondeu)
        VALUES (?, ?, ?, ?)
    ");
    $insert->bindValue(1, $idAluno);
    $insert->bindValue(2, $idCoordenacao);
    $insert->bindValue(3, round(max(-9.99999, min(9.99999, 1 - $distancia)), 5));
    $insert->bindValue(4, $correspondeu ? 'true' : 'false');
    $insert->execute();

    enviarSucesso($correspondeu ? "Identidade confirmada." : "Rosto não corresponde ao cadastro.", [
        "correspondeu" => $correspondeu,
        "distancia" => round($distancia, 4),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao verificar a biometria facial.", 500, $erro);
}
