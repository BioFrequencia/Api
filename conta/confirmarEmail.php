<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/sessao.php';
require_once __DIR__ . '/../helpers/emailConta.php';

/**
 * Abre o link de confirmação do e-mail (página /confirmar-email do site).
 * Público: quem tem o código é quem recebeu o e-mail.
 *
 *   { codigo }
 *
 * Cadastro: o e-mail fica confirmado e o pedido de acesso chega a quem
 * aprova (equipe do sistema ou gestão da escola). A conta da equipe do
 * sistema (ADMIN_EMAILS) entra direto - o e-mail confirmado é a prova de
 * que é ela.
 * Troca de e-mail: o login passa a ser o endereço novo.
 *
 * Abrir o mesmo link de novo (recarregar a página) responde que já está
 * confirmado, em vez de erro.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$codigo = $dados['codigo'] ?? null;

if (!codigoDeConfirmacaoValido($codigo)) {
    enviarErro("Link de confirmação inválido. Confira se o endereço foi copiado inteiro do e-mail.", 422);
}

try {
    $conexao->beginTransaction();
    $stmt = $conexao->prepare("
        SELECT v.id_verificacao, v.id_coordenacao, v.email, v.finalidade,
               v.usado_em IS NOT NULL AS usado, v.expira_em <= NOW() AS vencido
        FROM tb_verificacao_email v
        WHERE v.token_hash = ?
        FOR UPDATE
    ");
    $stmt->execute([hashDoCodigo($codigo)]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        $conexao->rollBack();
        enviarErro("Link de confirmação inválido. Confira se o endereço foi copiado inteiro do e-mail.", 404);
    }

    $stmt = $conexao->prepare("
        SELECT c.id_coordenacao, c.nome_coordenacao, c.email_coordenacao, c.id_escola, c.papel, c.situacao,
               c.email_verificado_em IS NOT NULL AS confirmado, e.nome_escola, e.municipio
        FROM tb_coordenacao c JOIN tb_escola e ON e.id_escola = c.id_escola
        WHERE c.id_coordenacao = ?
        FOR UPDATE OF c
    ");
    $stmt->execute([(int) $link['id_coordenacao']]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conta) {
        $conexao->rollBack();
        enviarErro("Conta não encontrada.", 404);
    }
    $mesmoEmail = mb_strtolower($conta['email_coordenacao']) === mb_strtolower($link['email']);

    if (valorBooleano($link['usado'])) {
        $conexao->rollBack();
        if ($mesmoEmail && valorBooleano($conta['confirmado'])) {
            enviarSucesso("Este e-mail já estava confirmado.", [
                "finalidade" => $link['finalidade'],
                "email"      => $conta['email_coordenacao'],
                "situacao"   => $conta['situacao'],
                "aprovacao"  => $conta['situacao'] === 'pendente' ? quemAprova($conexao, (int) $conta['id_escola'], (int) $conta['id_coordenacao']) : null,
            ]);
        }
        enviarErro("Este link já foi usado.", 410);
    }
    if (valorBooleano($link['vencido'])) {
        $conexao->rollBack();
        enviarErro($link['finalidade'] === 'troca_email'
            ? "Este link venceu ou foi trocado por um mais novo. Peça a troca do e-mail de novo em Minha conta."
            : "Este link venceu ou foi trocado por um mais novo. Entre com o seu e-mail e senha e peça outro em \"Reenviar o link\".", 410);
    }

    $idConta = (int) $conta['id_coordenacao'];
    if ($link['finalidade'] === 'troca_email') {
        $existe = $conexao->prepare("SELECT 1 FROM tb_coordenacao WHERE lower(email_coordenacao) = lower(?) AND id_coordenacao <> ?");
        $existe->execute([$link['email'], $idConta]);
        if ($existe->fetch()) {
            $conexao->rollBack();
            enviarErro("Este e-mail passou a ser de outra conta. Peça a troca de novo com outro endereço.", 409);
        }
        $conexao->prepare("UPDATE tb_coordenacao SET email_coordenacao = ?, email_verificado_em = NOW() WHERE id_coordenacao = ?")
            ->execute([mb_strtolower($link['email']), $idConta]);
        $conta['email_coordenacao'] = mb_strtolower($link['email']);
    } else {
        if (!$mesmoEmail) {
            $conexao->rollBack();
            enviarErro("Este link é de um e-mail que a conta não usa mais.", 409);
        }
        $conexao->prepare("UPDATE tb_coordenacao SET email_verificado_em = COALESCE(email_verificado_em, NOW()) WHERE id_coordenacao = ?")
            ->execute([$idConta]);
    }
    $conexao->prepare("UPDATE tb_verificacao_email SET usado_em = NOW() WHERE id_verificacao = ?")
        ->execute([(int) $link['id_verificacao']]);

    // A equipe do sistema não espera aprovação: é ela quem aprova.
    $situacao = $conta['situacao'];
    if ($link['finalidade'] === 'cadastro' && $situacao === 'pendente' && ehAdministrador($conta['email_coordenacao'])) {
        $papel = escolaTemGestor($conexao, (int) $conta['id_escola'], $idConta) ? 'coordenador' : 'gestor';
        $conexao->prepare("UPDATE tb_coordenacao SET situacao = 'ativo', papel = ?, aprovado_em = NOW() WHERE id_coordenacao = ?")
            ->execute([$papel, $idConta]);
        $situacao = 'ativo';
    }
    $conexao->commit();

    $aprovacao = null;
    if ($link['finalidade'] === 'cadastro' && $situacao === 'pendente') {
        $aprovacao = quemAprova($conexao, (int) $conta['id_escola'], $idConta);
        avisarQuemAprova($conexao, $conta);
    }

    if ($link['finalidade'] === 'troca_email') {
        $mensagem = "E-mail confirmado. A partir de agora, entre com {$conta['email_coordenacao']}.";
    } elseif ($situacao === 'ativo') {
        $mensagem = "E-mail confirmado. Já pode entrar.";
    } elseif ($situacao === 'pendente') {
        $mensagem = "E-mail confirmado. Agora " . ($aprovacao === 'gestao' ? "a gestão da {$conta['nome_escola']}" : "a equipe do BioFrequência")
            . " precisa aprovar o seu acesso - você recebe um e-mail quando isso acontecer.";
    } else {
        $mensagem = "E-mail confirmado.";
    }

    enviarSucesso($mensagem, [
        "finalidade" => $link['finalidade'],
        "email"      => $conta['email_coordenacao'],
        "situacao"   => $situacao,
        "aprovacao"  => $aprovacao,
    ]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    if (($erro->errorInfo[0] ?? $erro->getCode()) === '23505') {
        enviarErro("Este e-mail passou a ser de outra conta. Peça a troca de novo com outro endereço.", 409, $erro);
    }
    enviarErro("Erro no servidor ao confirmar o e-mail.", 500, $erro);
}
