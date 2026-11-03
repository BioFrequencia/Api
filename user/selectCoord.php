<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../helpers/sessao.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/tokenLogin.php';

/**
 * Login da coordenação - o mesmo no site e no app de biometria.
 *
 * "cliente": "app" pede uma sessão de 12 horas: o app fica num tablet da
 * escola o turno inteiro, e com as 2 horas do site a coordenação teria de
 * entrar de novo no meio da chegada dos alunos. "lembrar": true ("Lembrar
 * de mim" no site) pede 30 dias.
 *
 * Cada login vira uma sessão (tb_sessao), que aparece em Minha conta >
 * Dispositivos conectados e pode ser encerrada de lá.
 *
 * Só entra conta com o e-mail confirmado (migração 009) e ativa (008):
 * a conta nova espera a aprovação da gestão da escola ou da equipe do
 * sistema, e a gestão pode recusar ou desativar uma conta. A senha é
 * conferida antes, para a mensagem de situação não revelar a quem não
 * sabe a senha que o email existe.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$email = $dados["email"] ?? null;
$senha = $dados["senha"] ?? null;
$cliente = ($dados["cliente"] ?? null) === 'app' ? 'app' : 'site';
$lembrar = $cliente === 'site' && ($dados["lembrar"] ?? false) === true;
$duracaoSessao = duracaoDaSessao($cliente, $lembrar);

if (!campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Informe email e senha.", 422);
}

try {
    // Sem diferenciar maiúsculas: o teclado do celular capitaliza a
    // primeira letra e o login falhava com a senha certa. Se existirem
    // duas contas que só diferem na caixa (cadastros antigos), vale a
    // que bate exatamente com o que foi digitado.
    $select = $conexao->prepare(
        "SELECT c.id_coordenacao, c.nome_coordenacao, c.email_coordenacao, c.senha_coordenacao, c.papel, c.situacao,
                c.id_escola, c.email_verificado_em IS NOT NULL AS email_confirmado, e.nome_escola, e.municipio
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

    if (!valorBooleano($coordenacao['email_confirmado'])) {
        enviarErro(
            "Confirme o seu e-mail para entrar: abra o link que enviamos para {$coordenacao['email_coordenacao']} (confira também o spam). Se ele não chegou, peça outro em \"Reenviar o link\".",
            403, null, ["situacao" => "email_nao_confirmado"]
        );
    }

    $aprovaGestao = escolaTemGestor($conexao, (int) $coordenacao['id_escola'], (int) $coordenacao['id_coordenacao']);
    $situacoes = [
        'pendente'   => $aprovaGestao
            ? "Sua conta aguarda a aprovação da gestão da {$coordenacao['nome_escola']}. Você recebe um e-mail quando ela aprovar."
            : "Sua conta aguarda a aprovação da equipe do BioFrequência. Você recebe um e-mail quando ela for aprovada.",
        'recusado'   => "A gestão da {$coordenacao['nome_escola']} não aprovou o acesso desta conta. Fale com a coordenação da escola.",
        'desativado' => "Esta conta foi desativada pela gestão da escola. Fale com a coordenação da escola.",
    ];
    if (isset($situacoes[$coordenacao['situacao']])) {
        enviarErro($situacoes[$coordenacao['situacao']], 403, null, ["situacao" => $coordenacao['situacao']]);
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

    $idSessao = criarSessao($conexao, (int) $coordenacao['id_coordenacao'], $cliente, $lembrar, $duracaoSessao, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $token = criarToken($coordenacao['id_coordenacao'], $duracaoSessao, $idSessao);

    enviarSucesso("Login realizado com sucesso.", [
        "id_coordenacao"    => $coordenacao['id_coordenacao'],
        "nome_coordenacao"  => $coordenacao['nome_coordenacao'],
        "email_coordenacao" => $coordenacao['email_coordenacao'],
        "nome_escola"       => $coordenacao['nome_escola'],
        "municipio"         => $coordenacao['municipio'],
        "papel"             => $coordenacao['papel'],
        "administrador"     => ehAdministrador($coordenacao['email_coordenacao']),
        "token"             => $token,
        "expira_em"         => time() + $duracaoSessao,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao tentar fazer login.", 500, $erro);
}
