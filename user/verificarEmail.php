<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$email = $dados["email"] ?? null;

if (!campoPreenchido($email)) {
    enviarErro("Informe um email.", 422);
}

try {
    $select = $conexao->prepare("SELECT email_coordenacao FROM tb_coordenacao WHERE email_coordenacao = ?");
    $select->bindValue(1, $email);
    $select->execute();
    $coordenacao = $select->fetch(PDO::FETCH_ASSOC);

    // Observação: este endpoint só verifica disponibilidade de email;
    // ele NUNCA deve emitir token de autenticação (fazer isso seria dar
    // acesso a qualquer pessoa que soubesse/adivinhasse um email cadastrado,
    // sem precisar da senha).
    if ($coordenacao) {
        enviarSucesso("Email já cadastrado.", $coordenacao);
    }

    enviarSucesso("Disponível.", null);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao verificar o email.", 500, $erro);
}
