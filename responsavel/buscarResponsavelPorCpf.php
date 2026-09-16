<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/validacaoResponsavel.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Busca um responsável já cadastrado pelo CPF - usado antes de
 * vincularResponsavel.php, para o caso de irmãos (o mesmo responsável já
 * cadastrado para um aluno sendo vinculado a outro), sem precisar
 * cadastrar os dados dele de novo.
 */
autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$cpf = $dados["cpf_responsavel"] ?? null;

if (!cpfValido($cpf)) {
    enviarErro("Informe um CPF válido (11 dígitos).", 422);
}

try {
    $stmt = $conexao->prepare("
        SELECT id_responsavel, nome_responsavel, cpf_responsavel, telefone_responsavel, email_responsavel, genero_responsavel
        FROM tb_responsavel WHERE cpf_responsavel = ?
    ");
    $stmt->bindValue(1, somenteDigitos($cpf));
    $stmt->execute();
    $responsavel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$responsavel) {
        enviarErro("Nenhum responsável cadastrado com esse CPF.", 404);
    }

    enviarSucesso("Responsável encontrado.", $responsavel);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao buscar o responsável.", 500, $erro);
}
