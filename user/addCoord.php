<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$nome  = $dados["nome"] ?? null;
$email = $dados["email"] ?? null;
$senha = $dados["senha"] ?? null;

if (!campoPreenchido($nome) || !campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Preencha nome, email e senha.", 422);
}

if (!emailValido($email)) {
    enviarErro("Informe um email válido.", 422);
}

if (!senhaForte($senha)) {
    enviarErro("A senha deve ter pelo menos 6 caracteres.", 422);
}

$nome  = trim($nome);
$email = trim($email);

try {
    // Checagem prévia: a validação real de unicidade é o índice/constraint
    // no banco (ver tratamento do SQLSTATE 23505 abaixo); esta consulta
    // só existe para devolver uma mensagem amigável no caso comum.
    $verifica = $conexao->prepare("SELECT 1 FROM tb_coordenacao WHERE email_coordenacao = ?");
    $verifica->bindParam(1, $email);
    $verifica->execute();

    if ($verifica->fetch()) {
        enviarErro("Este email já está cadastrado.", 409);
    }

    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    $insert = $conexao->prepare("INSERT INTO tb_coordenacao(nome_coordenacao,email_coordenacao,senha_coordenacao) VALUES(?,?,?)");
    $insert->bindParam(1, $nome);
    $insert->bindParam(2, $email);
    $insert->bindParam(3, $senhaHash);
    $insert->execute();

    enviarSucesso("Usuário cadastrado com sucesso.");
} catch (PDOException $erro) {
    $sqlState = $erro->errorInfo[0] ?? $erro->getCode();
    if ($sqlState === '23505') {
        // Corrida entre a checagem acima e o insert, ou constraint de unicidade no banco.
        enviarErro("Este email já está cadastrado.", 409, $erro);
    }
    enviarErro("Erro no servidor ao cadastrar. Tente novamente mais tarde.", 500, $erro);
}
