<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}
$idAluno = $dados["id_aluno"];

try {
    $verificaAluno = $conexao->prepare("SELECT id_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindParam(1, $idAluno);
    $verificaAluno->execute();
    if ($verificaAluno->rowCount() === 0) {
        enviarErro("Aluno não encontrado.", 404);
    }

    // upsert: um encerramento por aluno; encerrar de novo só atualiza o
    // carimbo de data/hora (empurra a data a partir da qual um novo
    // evento faz o alerta reaparecer).
    $upsert = $conexao->prepare("
        INSERT INTO tb_alerta_encerrado (id_aluno, id_coordenacao, encerrado_em)
        VALUES (?, ?, NOW())
        ON CONFLICT (id_aluno) DO UPDATE SET id_coordenacao = EXCLUDED.id_coordenacao, encerrado_em = NOW()
    ");
    $upsert->bindParam(1, $idAluno);
    $upsert->bindParam(2, $idCoordenacao);
    $upsert->execute();

    enviarSucesso("Alerta encerrado.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao encerrar o alerta.", 500, $erro);
}
