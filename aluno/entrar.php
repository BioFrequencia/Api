<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../helpers/dispositivo.php';
require_once __DIR__ . '/../helpers/contaAluno.php';

/**
 * Login do aluno no app, com o e-mail e a senha criados em
 * aluno/criarConta.php. Sem token: o celular do aluno não tem (nem deve
 * ter) um token de coordenação.
 *
 * O login conecta o celular e devolve o segredo dele - o papel que o
 * código de vínculo tinha antes. Depois disso o app não usa mais a senha:
 * cada check-in vai assinado com esse segredo, depois da biometria.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$email      = $dados["email"] ?? null;
$senha      = $dados["senha"] ?? null;
$deviceUuid = $dados["device_uuid"] ?? null;

if (!campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Informe e-mail e senha.", 422);
}
if (!identificadorDispositivoValido($deviceUuid)) {
    enviarErro("Identificador do celular ausente ou inválido.", 422);
}

// Mensagem genérica: não revela se o e-mail tem conta.
$credenciaisInvalidas = "E-mail ou senha incorretos.";

try {
    $stmt = $conexao->prepare("SELECT id_conta, id_aluno, senha_conta FROM tb_conta_aluno WHERE lower(email_conta) = lower(?)");
    $stmt->bindValue(1, trim($email));
    $stmt->execute();
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conta) {
        // Conta da coordenação digitada no app: explica, em vez de
        // "senha incorreta" para quem acertou a senha. Só com a senha
        // certa, para a resposta não servir de teste de e-mail.
        $stmt = $conexao->prepare("SELECT senha_coordenacao FROM tb_coordenacao WHERE lower(email_coordenacao) = lower(?) LIMIT 1");
        $stmt->bindValue(1, trim($email));
        $stmt->execute();
        $hashCoordenacao = $stmt->fetchColumn();
        if (is_string($hashCoordenacao) && verificarSenhaComMigracao($senha, $hashCoordenacao)['valida']) {
            enviarErro("Esta é uma conta da coordenação, que entra só pelo site. No app, use a conta do aluno - se ainda não tiver, toque em \"Criar conta\".", 403);
        }
        enviarErro($credenciaisInvalidas, 401);
    }

    if (!password_verify($senha, $conta['senha_conta'])) {
        enviarErro($credenciaisInvalidas, 401);
    }

    $conexao->beginTransaction();
    $acesso = conectarCelularDoAluno($conexao, (int) $conta['id_conta'], (int) $conta['id_aluno'], $deviceUuid);
    $conexao->commit();

    enviarSucesso("Login realizado.", $acesso);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao entrar.", 500, $erro);
}
