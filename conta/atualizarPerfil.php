<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../helpers/perfil.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Atualiza os dados da conta. Trocar o e-mail - que é o login - pede a
 * senha atual: quem pegou o computador destravado não consegue tomar a
 * conta trocando o e-mail. O nome da escola vale para todas as contas
 * da mesma escola.
 *
 *   { nome_coordenacao, email_coordenacao, nome_escola, senha_atual? }
 */
$idCoordenacao = (int) autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$texto = fn(string $campo) => is_string($dados[$campo] ?? null) ? trim($dados[$campo]) : '';
$nome = $texto("nome_coordenacao");
$email = mb_strtolower($texto("email_coordenacao"));
$nomeEscola = $texto("nome_escola");
$senhaAtual = $dados["senha_atual"] ?? null;

if ($nome === '' || mb_strlen($nome) > 150) {
    enviarErro("Informe o nome da coordenação (até 150 caracteres).", 422);
}
if (!emailValido($email) || mb_strlen($email) > 150) {
    enviarErro("Informe um e-mail válido.", 422);
}
if ($nomeEscola === '' || mb_strlen($nomeEscola) > 150) {
    enviarErro("Informe o nome da escola (até 150 caracteres).", 422);
}

try {
    $stmt = $conexao->prepare("SELECT email_coordenacao, senha_coordenacao, id_escola FROM tb_coordenacao WHERE id_coordenacao = ?");
    $stmt->execute([$idCoordenacao]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conta) {
        enviarErro("Conta de coordenação não encontrada.", 404);
    }

    if (mb_strtolower($conta['email_coordenacao']) !== $email) {
        // 403 e não 401: o site trata 401 como sessão vencida e sairia da conta.
        if (!is_string($senhaAtual) || !verificarSenhaComMigracao($senhaAtual, $conta['senha_coordenacao'])['valida']) {
            enviarErro("Para trocar o e-mail, confirme a senha atual.", 403);
        }
        $existe = $conexao->prepare("SELECT 1 FROM tb_coordenacao WHERE lower(email_coordenacao) = ? AND id_coordenacao <> ?");
        $existe->execute([$email, $idCoordenacao]);
        if ($existe->fetch()) {
            enviarErro("Já existe uma conta com este e-mail.", 409);
        }
    }

    $conexao->beginTransaction();
    $conexao->prepare("UPDATE tb_coordenacao SET nome_coordenacao = ?, email_coordenacao = ? WHERE id_coordenacao = ?")
        ->execute([$nome, $email, $idCoordenacao]);
    $conexao->prepare("UPDATE tb_escola SET nome_escola = ? WHERE id_escola = ?")
        ->execute([$nomeEscola, $conta['id_escola']]);
    $conexao->commit();

    enviarSucesso("Dados da conta salvos.", perfilDaCoordenacao($conexao, $idCoordenacao));
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    // Duas contas trocando para o mesmo e-mail ao mesmo tempo: a trava
    // única do banco (uq_email_coordenacao) decide.
    if ($erro->getCode() === '23505') {
        enviarErro("Já existe uma conta com este e-mail.", 409);
    }
    enviarErro("Erro no servidor ao salvar os dados da conta.", 500, $erro);
}
