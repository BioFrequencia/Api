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
 * Cadastro de uma nova conta de coordenação. Aberto: o código de acesso
 * saiu na migração 009.
 *
 *   { nome, email, senha, cargo, id_escola }                 escola do catálogo
 *   { nome, email, senha, cargo, nome_escola, municipio? }   escola fora do catálogo
 *
 * cargo: direcao | vice_direcao | coordenacao | secretaria | outro - a
 * função na escola, só descritiva; a permissão (Gestão ou Coordenação) é
 * escolhida por quem aprova, com a sugerida pelo cargo já marcada.
 * A senha segue a regra de senha forte (helpers/validacao.php).
 *
 * A conta nasce "pendente", com o e-mail por confirmar. Até entrar:
 *   1. a pessoa abre o link que chega por e-mail (conta/confirmarEmail.php);
 *   2. quem aprova libera o acesso: a gestão da escola, se ela já usa o
 *      BioFrequência, ou a equipe do sistema (tela /admin). A primeira
 *      conta aprovada de uma escola vira a gestão dela, qualquer que seja
 *      o cargo.
 * Assim ninguém vê os alunos e os telefones dos responsáveis de uma
 * escola sem que alguém de confiança tenha liberado.
 *
 * Uma escola por conta (migração 008): do catálogo oficial
 * (escola/buscar.php) ou, se não estiver nele (escola municipal,
 * particular), criada com o nome informado.
 *
 * E-mail já cadastrado, nunca confirmado e com o link vencido: o cadastro
 * novo toma o lugar do antigo - senão um erro de digitação, ou alguém
 * usando o e-mail de outra pessoa, prenderia o endereço para sempre.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$nome   = $dados["nome"] ?? null;
$email  = $dados["email"] ?? null;
$senha  = $dados["senha"] ?? null;
$idEscola = $dados["id_escola"] ?? null;
$nomeEscola = $dados["nome_escola"] ?? null;
$municipio = $dados["municipio"] ?? null;
$cargo = $dados["cargo"] ?? null;

if (!campoPreenchido($nome) || !campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Preencha nome, email e senha.", 422);
}

if (!emailValido($email) || mb_strlen(trim($email)) > 150) {
    enviarErro("Informe um email válido.", 422);
}

exigirSenhaForte($senha);

if (!cargoValido($cargo)) {
    enviarErro("Informe o seu cargo na escola: direção, vice-direção, coordenação pedagógica, secretaria ou outro.", 422);
}

if (mb_strlen(trim($nome)) > 150) {
    enviarErro("O nome deve ter no máximo 150 caracteres.", 422);
}
if ($idEscola !== null && !inteiroValido($idEscola)) {
    enviarErro("Escola inválida.", 422);
}
if ($nomeEscola !== null && (!is_string($nomeEscola) || mb_strlen(trim($nomeEscola)) > 150)) {
    enviarErro("O nome da escola deve ter no máximo 150 caracteres.", 422);
}
if ($municipio !== null && (!is_string($municipio) || mb_strlen(trim($municipio)) > 60)) {
    enviarErro("O município deve ter no máximo 60 caracteres.", 422);
}

$nome  = trim($nome);
// Email sempre em minúsculas: o teclado do celular coloca a primeira
// letra em maiúscula, e "Coord@escola" e "coord@escola" viravam contas
// diferentes (ou um login que "não existe").
$email = mb_strtolower(trim($email));

try {
    // Checagem prévia: a validação real de unicidade é a constraint no
    // banco (ver tratamento do SQLSTATE 23505 abaixo); esta consulta
    // só existe para devolver uma mensagem amigável no caso comum.
    $verifica = $conexao->prepare("
        SELECT c.id_coordenacao, c.email_verificado_em IS NOT NULL AS confirmado,
               EXISTS (
                   SELECT 1 FROM tb_verificacao_email v
                   WHERE v.id_coordenacao = c.id_coordenacao AND v.usado_em IS NULL AND v.expira_em > NOW()
               ) AS link_valido
        FROM tb_coordenacao c WHERE lower(c.email_coordenacao) = ?
    ");
    $verifica->execute([$email]);
    $existente = $verifica->fetch(PDO::FETCH_ASSOC);

    $substituir = null;
    if ($existente) {
        if (valorBooleano($existente['confirmado'])) {
            enviarErro("Este email já está cadastrado.", 409);
        }
        if (valorBooleano($existente['link_valido'])) {
            enviarErro("Este email já foi cadastrado e aguarda a confirmação: abra o link que enviamos (confira também o spam) ou peça outro em \"Reenviar o link\".", 409);
        }
        $substituir = (int) $existente['id_coordenacao'];
    }

    if ($substituir === null) {
        $recentes = (int) $conexao->query("
            SELECT COUNT(*) FROM tb_coordenacao WHERE email_verificado_em IS NULL AND criado_em > NOW() - INTERVAL '1 hour'
        ")->fetchColumn();
        if ($recentes >= CADASTROS_POR_HORA) {
            enviarErro("Muitos cadastros ao mesmo tempo agora. Tente de novo em alguns minutos.", 429);
        }
    }

    $senhaHash = gerarHashSenha($senha);

    $conexao->beginTransaction();

    if ($idEscola !== null) {
        $escola = $conexao->prepare("SELECT id_escola, nome_escola FROM tb_escola WHERE id_escola = ? AND codigo_cie IS NOT NULL");
        $escola->execute([(int) $idEscola]);
        $linhaEscola = $escola->fetch(PDO::FETCH_ASSOC);
        if (!$linhaEscola) {
            $conexao->rollBack();
            enviarErro("Escola não encontrada no catálogo. Busque de novo pelo nome ou pelo município.", 404);
        }
        $idEscola = (int) $linhaEscola['id_escola'];
        $nomeEscola = $linhaEscola['nome_escola'];
    } else {
        $nomeEscola = campoPreenchido($nomeEscola) ? trim($nomeEscola) : mb_substr("Escola de $nome", 0, 150);
        $escola = $conexao->prepare("INSERT INTO tb_escola (nome_escola, municipio) VALUES (?, ?) RETURNING id_escola");
        $escola->execute([$nomeEscola, campoPreenchido($municipio) ? mb_strtoupper(trim($municipio)) : null]);
        $idEscola = (int) $escola->fetchColumn();
    }

    // O papel aqui é o previsto; o definitivo sai na aprovação, escolhido por
    // quem aprova (helpers/equipe.php). Numa escola sem gestão ativa, quem
    // entra vira a gestão dela.
    $aprovacao = escolaTemGestor($conexao, $idEscola) ? 'gestao' : 'equipe';
    $papel = $aprovacao === 'gestao' ? papelSugeridoPeloCargo($cargo) : 'gestor';

    if ($substituir !== null) {
        $conexao->prepare("
            UPDATE tb_coordenacao
            SET nome_coordenacao = ?, email_coordenacao = ?, senha_coordenacao = ?, id_escola = ?, papel = ?, cargo = ?,
                situacao = 'pendente', criado_em = NOW(), aprovado_em = NULL, aprovado_por = NULL
            WHERE id_coordenacao = ?
        ")->execute([$nome, $email, $senhaHash, $idEscola, $papel, $cargo, $substituir]);
        $idNova = $substituir;
    } else {
        $insert = $conexao->prepare("
            INSERT INTO tb_coordenacao (nome_coordenacao, email_coordenacao, senha_coordenacao, id_escola, papel, cargo, situacao)
            VALUES (?, ?, ?, ?, ?, ?, 'pendente')
            RETURNING id_coordenacao
        ");
        $insert->execute([$nome, $email, $senhaHash, $idEscola, $papel, $cargo]);
        $idNova = (int) $insert->fetchColumn();
    }

    $conexao->commit();

    $envio = enviarLinkDeConfirmacao($conexao, [
        'id_coordenacao'   => $idNova,
        'nome_coordenacao' => $nome,
        'nome_escola'      => $nomeEscola,
    ], $email, 'cadastro');

    enviarSucesso(mensagemDoCadastro($email, $nomeEscola, $aprovacao, $envio), [
        "nome_escola"      => $nomeEscola,
        "papel"            => $papel,
        "cargo"            => $cargo,
        "situacao"         => 'pendente',
        "email_verificado" => false,
        "email_enviado"    => $envio === ENVIO_OK,
        "aprovacao"        => $aprovacao,
    ]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    $sqlState = $erro->errorInfo[0] ?? $erro->getCode();
    if ($sqlState === '23505') {
        // Corrida entre a checagem acima e o insert, ou constraint de unicidade no banco.
        enviarErro("Este email já está cadastrado.", 409, $erro);
    }
    enviarErro("Erro no servidor ao cadastrar. Tente novamente em instantes.", 500, $erro);
}
