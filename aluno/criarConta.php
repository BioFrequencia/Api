<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/dispositivo.php';
require_once __DIR__ . '/../helpers/contaAluno.php';

/**
 * Cadastro do aluno pelo próprio app - sem token, porque quem chama ainda
 * não tem conta.
 *
 * Não cria aluno: a conta só é criada para um aluno que a escola já
 * cadastrou, encontrado pelo nome e pela data de nascimento. Quem decide
 * quem é aluno continua sendo a coordenação (antes ela fazia isso gerando
 * o código de vínculo). Uma conta errada - criada por outra pessoa com os
 * dados do aluno - é removida pela coordenação no site
 * (aluno/removerConta.php), e todo check-in do app ainda passa pela
 * confirmação dela.
 *
 * Em caso de sucesso já conecta o celular e devolve o mesmo que o login
 * (aluno/entrar.php): o aluno vai do cadastro direto para o check-in.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$nome       = $dados["nome"] ?? null;
$nascimento = $dados["data_nascimento"] ?? null;
$email      = $dados["email"] ?? null;
$senha      = $dados["senha"] ?? null;
$deviceUuid = $dados["device_uuid"] ?? null;

if (!campoPreenchido($nome) || !campoPreenchido($nascimento) || !campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Preencha nome, data de nascimento, e-mail e senha.", 422);
}
if (mb_strlen(trim($nome)) > 150) {
    enviarErro("O nome deve ter no máximo 150 caracteres.", 422);
}
if (!dataNascimentoValida($nascimento)) {
    enviarErro("Informe uma data de nascimento válida.", 422);
}
if (!emailValido($email) || mb_strlen(trim($email)) > 150) {
    enviarErro("Informe um e-mail válido.", 422);
}
if (!senhaForte($senha)) {
    enviarErro("A senha deve ter pelo menos 6 caracteres.", 422);
}
if (!identificadorDispositivoValido($deviceUuid)) {
    enviarErro("Identificador do celular ausente ou inválido.", 422);
}

$email = mb_strtolower(trim($email));

try {
    // Checagens só para a mensagem certa; quem garante a unicidade são os
    // índices do banco (SQLSTATE 23505 abaixo).
    $stmt = $conexao->prepare("SELECT 1 FROM tb_conta_aluno WHERE lower(email_conta) = ?");
    $stmt->bindValue(1, $email);
    $stmt->execute();
    if ($stmt->fetch()) {
        enviarErro("Já existe uma conta com este e-mail. Toque em \"Entrar\" e use a sua senha.", 409);
    }

    // Um e-mail, uma conta: com o mesmo e-mail na coordenação e num aluno,
    // o login não teria como saber qual das duas a pessoa quis usar.
    $stmt = $conexao->prepare("SELECT 1 FROM tb_coordenacao WHERE lower(email_coordenacao) = ?");
    $stmt->bindValue(1, $email);
    $stmt->execute();
    if ($stmt->fetch()) {
        enviarErro("Este e-mail é de uma conta da coordenação. Use outro e-mail para a conta de aluno.", 409);
    }

    $stmt = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, c.id_conta
        FROM tb_aluno a
        LEFT JOIN tb_conta_aluno c ON c.id_aluno = a.id_aluno
        WHERE a.dta_nasc_aluno = ?
    ");
    $stmt->bindValue(1, $nascimento);
    $stmt->execute();
    $candidatos = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        fn($aluno) => nomesConferem($nome, $aluno['nome_aluno'])
    ));

    // Nome completo igual vence quem só bateu no primeiro e no último nome.
    if (count($candidatos) > 1) {
        $exatos = array_values(array_filter(
            $candidatos,
            fn($aluno) => normalizarNome($aluno['nome_aluno']) === normalizarNome($nome)
        ));
        if (count($exatos) === 1) {
            $candidatos = $exatos;
        }
    }

    if (count($candidatos) === 0) {
        enviarErro("Não encontramos um aluno com esse nome e essa data de nascimento. Use os mesmos dados do cadastro da escola ou peça à coordenação para cadastrar você.", 404);
    }
    if (count($candidatos) > 1) {
        enviarErro("Mais de um aluno tem esses dados. Digite o nome completo, como está no cadastro da escola, ou peça ajuda à coordenação.", 409);
    }

    $aluno = $candidatos[0];
    if ($aluno['id_conta'] !== null) {
        enviarErro("Este aluno já tem uma conta no app. Entre com o e-mail dela; se não lembrar qual é, peça à coordenação para remover a conta antiga.", 409);
    }

    $conexao->beginTransaction();

    $insere = $conexao->prepare("
        INSERT INTO tb_conta_aluno (id_aluno, email_conta, senha_conta)
        VALUES (?, ?, ?)
        RETURNING id_conta
    ");
    $insere->bindValue(1, (int) $aluno['id_aluno']);
    $insere->bindValue(2, $email);
    $insere->bindValue(3, password_hash($senha, PASSWORD_DEFAULT));
    $insere->execute();
    $idConta = (int) $insere->fetchColumn();

    $acesso = conectarCelularDoAluno($conexao, $idConta, (int) $aluno['id_aluno'], $deviceUuid);

    $conexao->commit();

    enviarSucesso("Conta criada.", $acesso, 201);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    $sqlState = $erro->errorInfo[0] ?? $erro->getCode();
    if ($sqlState === '23505') {
        // Dois cadastros ao mesmo tempo para o mesmo aluno ou e-mail.
        enviarErro("Este e-mail ou este aluno já tem uma conta no app.", 409, $erro);
    }
    enviarErro("Erro no servidor ao criar a conta.", 500, $erro);
}
