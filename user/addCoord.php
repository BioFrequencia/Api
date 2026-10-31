<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';

/**
 * Cadastro de uma nova conta de coordenação.
 *
 * Exige o código de acesso da escola (variável de ambiente
 * CADASTRO_CODIGO_ACESSO). Antes o cadastro era aberto: qualquer pessoa
 * na internet criava uma conta pelo site e, como toda conta nova cai na
 * escola padrão (tb_coordenacao.id_escola DEFAULT 1), passava a ver
 * nomes, turmas, faltas e os dados dos responsáveis (CPF, telefone) de
 * todos os alunos. Sem a variável definida, o cadastro fica desligado -
 * a escolha segura quando ninguém configurou nada.
 *
 * Quem administra o sistema repassa o código só para quem deve ter
 * acesso; trocar a variável invalida o código antigo na hora.
 *
 * Cada coordenação é uma escola independente: a conta nova ganha uma
 * escola própria (nome_escola, ou "Escola de <nome>"), com turmas e
 * alunos que nenhuma outra conta enxerga. Antes toda conta nova caía na
 * escola padrão (id 1) e via os alunos das outras.
 */
// Variável de ambiente (Render/Docker) ou, no XAMPP, a constante de
// mesmo nome em config/config.php.
$codigoConfigurado = getenv('CADASTRO_CODIGO_ACESSO');
if ($codigoConfigurado === false && defined('CADASTRO_CODIGO_ACESSO')) {
    $codigoConfigurado = CADASTRO_CODIGO_ACESSO;
}
$codigoConfigurado = $codigoConfigurado === false ? '' : trim((string) $codigoConfigurado);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$nome   = $dados["nome"] ?? null;
$email  = $dados["email"] ?? null;
$senha  = $dados["senha"] ?? null;
$codigo = $dados["codigo_acesso"] ?? null;
$nomeEscola = $dados["nome_escola"] ?? null;

if ($codigoConfigurado === '') {
    enviarErro("O cadastro de novas contas está desativado. Peça acesso à coordenação responsável pelo sistema.", 403);
}
if (!is_string($codigo) || !hash_equals($codigoConfigurado, trim($codigo))) {
    enviarErro("Código de acesso da escola inválido.", 403);
}

if (!campoPreenchido($nome) || !campoPreenchido($email) || !campoPreenchido($senha)) {
    enviarErro("Preencha nome, email e senha.", 422);
}

if (!emailValido($email) || mb_strlen(trim($email)) > 150) {
    enviarErro("Informe um email válido.", 422);
}

if (!senhaForte($senha)) {
    enviarErro("A senha deve ter pelo menos 6 caracteres.", 422);
}

if (mb_strlen(trim($nome)) > 150) {
    enviarErro("O nome deve ter no máximo 150 caracteres.", 422);
}
if ($nomeEscola !== null && (!is_string($nomeEscola) || mb_strlen(trim($nomeEscola)) > 150)) {
    enviarErro("O nome da escola deve ter no máximo 150 caracteres.", 422);
}

$nome  = trim($nome);
$nomeEscola = campoPreenchido($nomeEscola) ? trim($nomeEscola) : mb_substr("Escola de $nome", 0, 150);
// Email sempre em minúsculas: o teclado do celular coloca a primeira
// letra em maiúscula, e "Coord@escola" e "coord@escola" viravam contas
// diferentes (ou um login que "não existe").
$email = mb_strtolower(trim($email));

try {
    // Checagem prévia: a validação real de unicidade é a constraint no
    // banco (ver tratamento do SQLSTATE 23505 abaixo); esta consulta
    // só existe para devolver uma mensagem amigável no caso comum.
    $verifica = $conexao->prepare("SELECT 1 FROM tb_coordenacao WHERE lower(email_coordenacao) = ?");
    $verifica->bindValue(1, $email);
    $verifica->execute();

    if ($verifica->fetch()) {
        enviarErro("Este email já está cadastrado.", 409);
    }

    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    $conexao->beginTransaction();

    $escola = $conexao->prepare("INSERT INTO tb_escola (nome_escola) VALUES (?) RETURNING id_escola");
    $escola->bindValue(1, $nomeEscola);
    $escola->execute();
    $idEscola = (int) $escola->fetchColumn();

    $insert = $conexao->prepare("INSERT INTO tb_coordenacao(nome_coordenacao,email_coordenacao,senha_coordenacao,id_escola) VALUES(?,?,?,?)");
    $insert->bindValue(1, $nome);
    $insert->bindValue(2, $email);
    $insert->bindValue(3, $senhaHash);
    $insert->bindValue(4, $idEscola);
    $insert->execute();

    $conexao->commit();

    enviarSucesso("Usuário cadastrado com sucesso.", ["nome_escola" => $nomeEscola]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    $sqlState = $erro->errorInfo[0] ?? $erro->getCode();
    if ($sqlState === '23505') {
        // Corrida entre a checagem acima e o insert, ou constraint de unicidade no banco.
        enviarErro("Este email já está cadastrado.", 409, $erro);
    }
    enviarErro("Erro no servidor ao cadastrar. Tente novamente mais tarde.", 500, $erro);
}
