<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';

/**
 * Cadastro de uma nova conta de coordenação.
 *
 *   { nome, email, senha, codigo_acesso, id_escola }        escola do catálogo
 *   { nome, email, senha, codigo_acesso, nome_escola, municipio? }
 *                                                           escola fora do catálogo
 *
 * Exige o código de acesso (variável de ambiente CADASTRO_CODIGO_ACESSO).
 * Sem a variável, o cadastro fica desligado - a escolha segura quando
 * ninguém configurou nada. Quem administra o sistema repassa o código só
 * para quem deve ter acesso; trocar a variável invalida o antigo na hora.
 *
 * Uma escola por conta (migração 008). A escola vem do catálogo oficial
 * (escola/buscar.php) ou, se não estiver nele (escola municipal,
 * particular), é criada com o nome informado.
 *   - Escola sem gestão ativa: a conta entra ativa, como gestora.
 *   - Escola que já tem gestão: a conta entra "pendente" e só acessa
 *     depois que a gestão aprovar. Sem isso, o código de acesso bastava
 *     para ver os alunos e os telefones dos responsáveis de qualquer
 *     escola.
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
$idEscola = $dados["id_escola"] ?? null;
$nomeEscola = $dados["nome_escola"] ?? null;
$municipio = $dados["municipio"] ?? null;

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
    $verifica = $conexao->prepare("SELECT 1 FROM tb_coordenacao WHERE lower(email_coordenacao) = ?");
    $verifica->bindValue(1, $email);
    $verifica->execute();

    if ($verifica->fetch()) {
        enviarErro("Este email já está cadastrado.", 409);
    }

    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

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
        // Dois primeiros cadastros da mesma escola ao mesmo tempo: a trava
        // faz o segundo enxergar o primeiro (e entrar como pendente).
        $conexao->prepare("SELECT pg_advisory_xact_lock(7401, ?)")->execute([$idEscola]);
        $gestora = !escolaTemGestor($conexao, $idEscola);
    } else {
        $nomeEscola = campoPreenchido($nomeEscola) ? trim($nomeEscola) : mb_substr("Escola de $nome", 0, 150);
        $escola = $conexao->prepare("INSERT INTO tb_escola (nome_escola, municipio) VALUES (?, ?) RETURNING id_escola");
        $escola->execute([$nomeEscola, campoPreenchido($municipio) ? mb_strtoupper(trim($municipio)) : null]);
        $idEscola = (int) $escola->fetchColumn();
        $gestora = true;
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_coordenacao (nome_coordenacao, email_coordenacao, senha_coordenacao, id_escola, papel, situacao)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$nome, $email, $senhaHash, $idEscola, $gestora ? 'gestor' : 'coordenador', $gestora ? 'ativo' : 'pendente']);

    $conexao->commit();

    enviarSucesso(
        $gestora
            ? "Conta criada. Você é a gestão da $nomeEscola no BioFrequência."
            : "Conta criada. A $nomeEscola já usa o BioFrequência: a gestão da escola precisa aprovar o seu acesso.",
        [
            "nome_escola" => $nomeEscola,
            "papel"       => $gestora ? 'gestor' : 'coordenador',
            "situacao"    => $gestora ? 'ativo' : 'pendente',
        ]
    );
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
