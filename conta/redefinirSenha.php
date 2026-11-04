<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../helpers/sessao.php';
require_once __DIR__ . '/../helpers/tentativas.php';
require_once __DIR__ . '/../helpers/emailConta.php';

/**
 * Cria a senha nova pelo link de "Esqueci minha senha" (conta/esqueciSenha.php).
 *
 *   { codigo, senha_nova }
 *
 * O link vale uma vez e por 1 hora; a senha segue a regra de senha forte.
 * Todos os aparelhos conectados saem da conta: quem esqueceu a senha pode
 * tê-la aberta em outro lugar - e quem a descobriu também. Os erros de
 * login acumulados do e-mail são zerados.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$codigo = $dados['codigo'] ?? null;
$senhaNova = $dados['senha_nova'] ?? null;

$linkInvalido = "Link inválido. Confira se o endereço foi copiado inteiro do e-mail, ou peça outro em \"Esqueci minha senha\".";
if (!codigoDeConfirmacaoValido($codigo)) {
    enviarErro($linkInvalido, 422);
}
exigirSenhaForte($senhaNova);

try {
    $conexao->beginTransaction();
    $stmt = $conexao->prepare("
        SELECT id_verificacao, id_coordenacao, email,
               usado_em IS NOT NULL AS usado, expira_em <= NOW() AS vencido
        FROM tb_verificacao_email
        WHERE token_hash = ? AND finalidade = 'redefinir_senha'
        FOR UPDATE
    ");
    $stmt->execute([hashDoCodigo($codigo)]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        $conexao->rollBack();
        enviarErro($linkInvalido, 404);
    }
    if (valorBooleano($link['usado'])) {
        $conexao->rollBack();
        enviarErro("Este link já foi usado. Se precisar, peça outro em \"Esqueci minha senha\".", 410);
    }
    if (valorBooleano($link['vencido'])) {
        $conexao->rollBack();
        enviarErro("Este link venceu (vale por " . VALIDADE_LINK_SENHA_HORAS . " hora) ou foi trocado por um mais novo. Peça outro em \"Esqueci minha senha\".", 410);
    }

    $stmt = $conexao->prepare("SELECT id_coordenacao, email_coordenacao, situacao FROM tb_coordenacao WHERE id_coordenacao = ? FOR UPDATE");
    $stmt->execute([(int) $link['id_coordenacao']]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conta || mb_strtolower($conta['email_coordenacao']) !== mb_strtolower($link['email'])) {
        $conexao->rollBack();
        enviarErro("Este link é de um e-mail que a conta não usa mais. Peça outro em \"Esqueci minha senha\".", 409);
    }
    if (in_array($conta['situacao'], ['recusado', 'desativado'], true)) {
        $conexao->rollBack();
        enviarErro("Esta conta não está ativa. Fale com a gestão da escola.", 403);
    }

    $idConta = (int) $conta['id_coordenacao'];
    $conexao->prepare("UPDATE tb_coordenacao SET senha_coordenacao = ? WHERE id_coordenacao = ?")
        ->execute([gerarHashSenha($senhaNova), $idConta]);
    $conexao->prepare("UPDATE tb_verificacao_email SET usado_em = NOW() WHERE id_verificacao = ?")
        ->execute([(int) $link['id_verificacao']]);
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao criar a senha nova.", 500, $erro);
}

// A senha já mudou: uma falha daqui em diante não pode virar "erro ao criar a senha".
$encerradas = 0;
try {
    $encerradas = encerrarSessoes($conexao, $idConta, null);
    limparFalhasDoEmail($conexao, $conta['email_coordenacao']);
} catch (PDOException $erroDepois) {
    error_log('[BioFrequenciaApi] após a senha nova: ' . $erroDepois->getMessage());
}
enviarSucesso("Senha nova criada. Entre com o seu e-mail e a senha nova.", ['sessoes_encerradas' => $encerradas]);
