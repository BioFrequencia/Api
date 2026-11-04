<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/emailConta.php';

/**
 * "Esqueci minha senha": manda o link para criar uma senha nova
 * (conta/redefinirSenha.php).
 *
 *   { email }
 *
 * A resposta é a mesma com ou sem conta para o e-mail: ela não revela
 * quem está cadastrado. O link vale por 1 hora e segue o limite de envios
 * por conta (1 por minuto, 5 por dia). Conta recusada ou desativada não
 * recebe o link: quem reativa é a gestão da escola.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$email = $dados['email'] ?? null;

if (!emailValido($email)) {
    enviarErro("Informe um e-mail válido.", 422);
}
// Sem provedor de e-mail não há como provar que a pessoa é dona do endereço.
// (A recusa vale para qualquer e-mail, com conta ou não.)
if (!emailConfigurado()) {
    enviarErro("O envio de e-mails não está configurado neste servidor. Peça ajuda à gestão da escola ou à equipe do BioFrequência para recuperar o acesso.", 503);
}

$resposta = "Se houver uma conta com esse e-mail, enviamos um link para criar uma senha nova. Ele vale por 1 hora; confira também a caixa de spam.";

try {
    $stmt = $conexao->prepare("
        SELECT id_coordenacao, nome_coordenacao, email_coordenacao, situacao
        FROM tb_coordenacao
        WHERE lower(email_coordenacao) = lower(?)
        ORDER BY (email_coordenacao = ?) DESC, id_coordenacao
        LIMIT 1
    ");
    $stmt->execute([trim($email), trim($email)]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($conta && !in_array($conta['situacao'], ['recusado', 'desativado'], true)
        && motivoParaEsperarLink($conexao, (int) $conta['id_coordenacao']) === null) {
        $envio = enviarLinkDeNovaSenha($conexao, $conta);
        if ($envio === ENVIO_FALHOU) {
            error_log('[BioFrequenciaApi] link de senha nova não enviado (provedor recusou)');
        }
    }
    enviarSucesso($resposta);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao pedir a senha nova.", 500, $erro);
}
