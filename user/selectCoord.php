<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../Authentication/tokenLogin.php';

/**
 * Login da coordenação - o mesmo no site e no app de biometria.
 *
 * "cliente": "app" pede uma sessão de 12 horas: o app fica num tablet da
 * escola o turno inteiro, e com as 2 horas do site a coordenação teria de
 * entrar de novo no meio da chegada dos alunos.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$email = $dados["email"] ?? null;
$senha = $dados["senha"] ?? null;
$duracaoSessao = ($dados["cliente"] ?? null) === 'app' ? DURACAO_SESSAO_APP : DURACAO_SESSAO_SITE;

if (!campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Informe email e senha.", 422);
}

try {
    // Sem diferenciar maiúsculas: o teclado do celular capitaliza a
    // primeira letra e o login falhava com a senha certa. Se existirem
    // duas contas que só diferem na caixa (cadastros antigos), vale a
    // que bate exatamente com o que foi digitado.
    $select = $conexao->prepare(
        "SELECT c.id_coordenacao, c.nome_coordenacao, c.email_coordenacao, c.senha_coordenacao, e.nome_escola
         FROM tb_coordenacao c JOIN tb_escola e ON e.id_escola = c.id_escola
         WHERE lower(c.email_coordenacao) = lower(?)
         ORDER BY (c.email_coordenacao = ?) DESC, c.id_coordenacao
         LIMIT 1"
    );
    $select->bindValue(1, trim($email));
    $select->bindValue(2, trim($email));
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

    $token = criarToken($coordenacao['id_coordenacao'], $duracaoSessao);

    enviarSucesso("Login realizado com sucesso.", [
        "id_coordenacao"    => $coordenacao['id_coordenacao'],
        "nome_coordenacao"  => $coordenacao['nome_coordenacao'],
        "email_coordenacao" => $coordenacao['email_coordenacao'],
        "nome_escola"       => $coordenacao['nome_escola'],
        "token"             => $token,
        "expira_em"         => time() + $duracaoSessao,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao tentar fazer login.", 500, $erro);
}
