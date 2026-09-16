<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../Authentication/tokenLogin.php';

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$email = $dados["email"] ?? null;
$senha = $dados["senha"] ?? null;

if (!campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Informe email e senha.", 422);
}

try {
    $select = $conexao->prepare(
        "SELECT id_coordenacao, nome_coordenacao, email_coordenacao, senha_coordenacao
         FROM tb_coordenacao WHERE email_coordenacao = ?"
    );
    $select->bindValue(1, $email);
    $select->execute();
    $coordenacao = $select->fetch(PDO::FETCH_ASSOC);

    // Mensagem genérica de propósito: não revela se o email existe ou não.
    $mensagemCredenciaisInvalidas = "Email ou senha incorretos.";

    if (!$coordenacao) {
        enviarErro($mensagemCredenciaisInvalidas, 401);
    }

    $verificacao = verificarSenhaComMigracao($senha, $coordenacao['senha_coordenacao']);

    if (!$verificacao['valida']) {
        enviarErro($mensagemCredenciaisInvalidas, 401);
    }

    // Conta ainda tinha a senha em texto puro (formato antigo): migra
    // silenciosamente para hash agora que sabemos a senha correta.
    if ($verificacao['precisaAtualizar']) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $update = $conexao->prepare("UPDATE tb_coordenacao SET senha_coordenacao = ? WHERE id_coordenacao = ?");
        $update->bindValue(1, $novoHash);
        $update->bindValue(2, $coordenacao['id_coordenacao']);
        $update->execute();
    }

    $token = criarToken($coordenacao['id_coordenacao']);

    enviarSucesso("Login realizado com sucesso.", [
        "id_coordenacao"    => $coordenacao['id_coordenacao'],
        "nome_coordenacao"  => $coordenacao['nome_coordenacao'],
        "email_coordenacao" => $coordenacao['email_coordenacao'],
        "token"             => $token,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao tentar fazer login.", 500, $erro);
}
