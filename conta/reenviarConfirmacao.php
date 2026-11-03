<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../helpers/sessao.php';
require_once __DIR__ . '/../helpers/emailConta.php';

/**
 * Manda de novo o link de confirmação do cadastro (botão "Reenviar o
 * link" no login e no fim do cadastro).
 *
 *   { email, senha }
 *
 * Pede a senha: sem ela, qualquer pessoa encheria a caixa de entrada de
 * alguém com links. E-mail inexistente e senha errada dão a mesma resposta
 * do login. Um minuto entre um pedido e outro, até cinco por dia.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$email = $dados["email"] ?? null;
$senha = $dados["senha"] ?? null;

if (!campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Informe email e senha.", 422);
}

try {
    $stmt = $conexao->prepare("
        SELECT c.id_coordenacao, c.nome_coordenacao, c.email_coordenacao, c.senha_coordenacao,
               c.email_verificado_em IS NOT NULL AS confirmado, e.nome_escola
        FROM tb_coordenacao c JOIN tb_escola e ON e.id_escola = c.id_escola
        WHERE lower(c.email_coordenacao) = lower(?)
        ORDER BY (c.email_coordenacao = ?) DESC, c.id_coordenacao
        LIMIT 1
    ");
    $stmt->execute([trim($email), trim($email)]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conta || !verificarSenhaComMigracao($senha, $conta['senha_coordenacao'])['valida']) {
        enviarErro("Email ou senha incorretos.", 401);
    }
    if (valorBooleano($conta['confirmado'])) {
        enviarErro("Este e-mail já está confirmado. É só entrar.", 409);
    }
    if (!emailConfigurado()) {
        enviarErro("O envio de e-mails ainda não foi configurado no sistema. Avise a equipe do BioFrequência: ela pode confirmar o seu e-mail.", 503);
    }
    $espera = motivoParaEsperarLink($conexao, (int) $conta['id_coordenacao']);
    if ($espera !== null) {
        enviarErro($espera, 429);
    }

    $envio = enviarLinkDeConfirmacao($conexao, $conta, $conta['email_coordenacao'], 'cadastro');
    if ($envio !== ENVIO_OK) {
        enviarErro("Não conseguimos enviar o e-mail agora. Tente de novo em alguns minutos.", 502);
    }
    enviarSucesso("Enviamos um novo link para {$conta['email_coordenacao']}. Ele vale por " . VALIDADE_LINK_HORAS . " horas.", [
        "email_enviado" => true,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao reenviar o link.", 500, $erro);
}
