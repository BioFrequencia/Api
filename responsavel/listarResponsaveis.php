<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

// CPF, telefone e email dos responsáveis: só para a escola do aluno.
exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $stmt = $conexao->prepare("
        SELECT r.id_responsavel, r.nome_responsavel, r.cpf_responsavel, r.telefone_responsavel,
               r.email_responsavel, r.genero_responsavel
        FROM tb_aluno_responsavel ar
        JOIN tb_responsavel r ON r.id_responsavel = ar.id_responsavel
        WHERE ar.id_aluno = ?
        ORDER BY r.nome_responsavel
    ");
    $stmt->bindValue(1, $idAluno);
    $stmt->execute();
    $responsaveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    enviarSucesso("Responsáveis carregados com sucesso.", $responsaveis);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os responsáveis.", 500, $erro);
}
