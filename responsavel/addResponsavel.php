<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/validacaoResponsavel.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Cadastra um responsável e já vincula ao aluno informado (tb_responsavel
 * + tb_aluno_responsavel). tb_responsavel.id_aluno é preenchido com o
 * mesmo aluno do vínculo inicial - mantido por compatibilidade com o
 * schema existente, mas tb_aluno_responsavel é que decide de fato quem
 * está vinculado a quem (um responsável pode ser vinculado a mais de um
 * aluno depois, via vincularResponsavel.php - útil para irmãos).
 *
 * O banco de produção tem UNIQUE em cpf, telefone E email de
 * tb_responsavel, e CHECK no gênero ('Masculino','Feminino','Indefinido').
 * O código aceitava "Outro" (recusado pelo banco: erro 500) e respondia
 * "já vinculado a este aluno" para qualquer duplicidade - inclusive
 * telefone repetido, que é outra coisa.
 *
 * CPF já cadastrado:
 *  - responsável de aluno desta escola: 409 com o id, para o site
 *    oferecer o vínculo (vincularResponsavel.php);
 *  - sem vínculo nenhum (todos os vínculos foram removidos): o cadastro
 *    é reaproveitado com os dados novos - ninguém mais o enxerga;
 *  - responsável só de alunos de outra escola: 409 sem dados - os dados
 *    pessoais de uma escola não aparecem para outra.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
$nome = $dados["nome_responsavel"] ?? null;
$cpf = $dados["cpf_responsavel"] ?? null;
$telefone = $dados["telefone_responsavel"] ?? null;
$email = $dados["email_responsavel"] ?? null;
$genero = normalizarGeneroResponsavel($dados["genero_responsavel"] ?? null);

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!campoPreenchido($nome) || mb_strlen(trim($nome)) > 150) {
    enviarErro("Informe o nome do responsável (até 150 caracteres).", 422);
}
if (!cpfValido($cpf)) {
    enviarErro("Informe um CPF válido (11 dígitos).", 422);
}
if (!telefoneValido($telefone)) {
    enviarErro("Informe um telefone válido (10 ou 11 dígitos, com DDD).", 422);
}
if (campoPreenchido($email) && (!emailValido($email) || mb_strlen(trim($email)) > 150)) {
    enviarErro("Informe um email válido (ou deixe em branco).", 422);
}
if ($genero === null) {
    enviarErro("Informe um gênero válido.", 422);
}

$nome = trim($nome);
$cpfDigitos = somenteDigitos($cpf);
$telefoneDigitos = somenteDigitos($telefone);
$email = campoPreenchido($email) ? mb_strtolower(trim($email)) : null;

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);

try {
    $busca = $conexao->prepare("
        SELECT r.id_responsavel,
               (SELECT COUNT(*) FROM tb_aluno_responsavel ar WHERE ar.id_responsavel = r.id_responsavel) AS vinculos
        FROM tb_responsavel r WHERE r.cpf_responsavel = ?
    ");
    $busca->bindValue(1, $cpfDigitos);
    $busca->execute();
    $existente = $busca->fetch(PDO::FETCH_ASSOC);

    if ($existente && responsavelVisivelNaEscola($conexao, $existente['id_responsavel'], $idEscola)) {
        enviarErro(
            "Já existe um responsável com este CPF nesta escola. Use \"Buscar\" pelo CPF para vinculá-lo a este aluno.",
            409, null, ["id_responsavel" => (int) $existente['id_responsavel']]
        );
    }
    if ($existente && (int) $existente['vinculos'] > 0) {
        enviarErro("Este CPF já está cadastrado como responsável em outra escola. Procure o suporte do sistema.", 409);
    }

    $conexao->beginTransaction();

    if ($existente) {
        $idResponsavel = (int) $existente['id_responsavel'];
        $grava = $conexao->prepare("
            UPDATE tb_responsavel
            SET id_aluno = ?, nome_responsavel = ?, telefone_responsavel = ?, email_responsavel = ?, genero_responsavel = ?
            WHERE id_responsavel = ?
        ");
        $grava->execute([$idAluno, $nome, $telefoneDigitos, $email, $genero, $idResponsavel]);
    } else {
        $grava = $conexao->prepare("
            INSERT INTO tb_responsavel (id_aluno, nome_responsavel, cpf_responsavel, telefone_responsavel, email_responsavel, genero_responsavel)
            VALUES (?, ?, ?, ?, ?, ?)
            RETURNING id_responsavel
        ");
        $grava->bindValue(1, $idAluno);
        $grava->bindValue(2, $nome);
        $grava->bindValue(3, $cpfDigitos);
        $grava->bindValue(4, $telefoneDigitos);
        $grava->bindValue(5, $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $grava->bindValue(6, $genero);
        $grava->execute();
        $idResponsavel = (int) $grava->fetchColumn();
    }

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
        enviarErro(mensagemDuplicidadeResponsavel($erro->getMessage()), 409, $erro);
    }
    enviarErro("Erro no servidor ao cadastrar o responsável.", 500, $erro);
}
