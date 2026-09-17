<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/validacaoResponsavel.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Cadastra um responsável e já vincula ao aluno informado (tb_responsavel
 * + tb_aluno_responsavel). tb_responsavel.id_aluno é preenchido com o
 * mesmo aluno do vínculo inicial - mantido por compatibilidade com o
 * schema existente, mas tb_aluno_responsavel é que decide de fato quem
 * está vinculado a quem (um responsável pode ser vinculado a mais de um
 * aluno depois, via vincularResponsavel.php - útil para irmãos).
 */
autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
$nome = $dados["nome_responsavel"] ?? null;
$cpf = $dados["cpf_responsavel"] ?? null;
$telefone = $dados["telefone_responsavel"] ?? null;
$email = $dados["email_responsavel"] ?? null;
$genero = $dados["genero_responsavel"] ?? null;

$generosAceitos = ["Feminino", "Masculino", "Outro"];

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!campoPreenchido($nome)) {
    enviarErro("Informe o nome do responsável.", 422);
}
if (!cpfValido($cpf)) {
    enviarErro("Informe um CPF válido (11 dígitos).", 422);
}
if (!telefoneValido($telefone)) {
    enviarErro("Informe um telefone válido (10 ou 11 dígitos, com DDD).", 422);
}
if (campoPreenchido($email) && !emailValido($email)) {
    enviarErro("Informe um email válido (ou deixe em branco).", 422);
}
if (!campoPreenchido($genero) || !in_array($genero, $generosAceitos, true)) {
    enviarErro("Informe um gênero válido.", 422);
}

$nome = trim($nome);
$cpfDigitos = somenteDigitos($cpf);
$telefoneDigitos = somenteDigitos($telefone);
$email = campoPreenchido($email) ? trim($email) : null;

try {
    $verificaAluno = $conexao->prepare("SELECT id_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindValue(1, $idAluno);
    $verificaAluno->execute();
    if ($verificaAluno->rowCount() === 0) {
        enviarErro("Aluno não encontrado.", 404);
    }

    // Não há constraint UNIQUE em cpf_responsavel no banco - sem esta
    // checagem, cadastrar duas vezes o mesmo CPF (comum quando o mesmo
    // responsável já existe para outro aluno/irmão) criava uma segunda
    // linha duplicada de pessoa em vez de reaproveitar o cadastro.
    $verificaCpf = $conexao->prepare("SELECT id_responsavel FROM tb_responsavel WHERE cpf_responsavel = ?");
    $verificaCpf->bindValue(1, $cpfDigitos);
    $verificaCpf->execute();
    if ($verificaCpf->fetch()) {
        enviarErro("Já existe um responsável cadastrado com este CPF. Use vincularResponsavel.php para vinculá-lo a este aluno.", 409);
    }

    $conexao->beginTransaction();

    $insert = $conexao->prepare("
        INSERT INTO tb_responsavel (id_aluno, nome_responsavel, cpf_responsavel, telefone_responsavel, email_responsavel, genero_responsavel)
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING id_responsavel
    ");
    $insert->bindValue(1, $idAluno);
    $insert->bindValue(2, $nome);
    $insert->bindValue(3, $cpfDigitos);
    $insert->bindValue(4, $telefoneDigitos);
    $insert->bindValue(5, $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $insert->bindValue(6, $genero);
    $insert->execute();
    $novo = $insert->fetch(PDO::FETCH_ASSOC);
    $idResponsavel = $novo["id_responsavel"];

    $vinculo = $conexao->prepare("INSERT INTO tb_aluno_responsavel (id_aluno, id_responsavel) VALUES (?, ?)");
    $vinculo->bindValue(1, $idAluno);
    $vinculo->bindValue(2, $idResponsavel);
    $vinculo->execute();

    $conexao->commit();

    enviarSucesso("Responsável cadastrado com sucesso.", ["id_responsavel" => $idResponsavel]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    $sqlState = $erro->errorInfo[0] ?? $erro->getCode();
    if ($sqlState === '23505') {
        enviarErro("Este responsável já está vinculado a este aluno.", 409, $erro);
    }
    enviarErro("Erro no servidor ao cadastrar o responsável.", 500, $erro);
}
