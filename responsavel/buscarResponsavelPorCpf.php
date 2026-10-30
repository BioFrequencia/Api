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
 * Busca um responsável já cadastrado pelo CPF - usado antes de
 * vincularResponsavel.php, para o caso de irmãos (o mesmo responsável já
 * cadastrado para um aluno sendo vinculado a outro), sem precisar
 * cadastrar os dados dele de novo.
 *
 * Só encontra responsáveis de alunos da escola de quem chamou. Antes
 * qualquer conta consultava nome, telefone e email de qualquer pessoa
 * cadastrada no sistema digitando um CPF.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$cpf = $dados["cpf_responsavel"] ?? null;

if (!cpfValido($cpf)) {
    enviarErro("Informe um CPF válido.", 422);
}

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $stmt = $conexao->prepare("
        SELECT DISTINCT r.id_responsavel, r.nome_responsavel, r.cpf_responsavel, r.telefone_responsavel,
               r.email_responsavel, r.genero_responsavel
        FROM tb_responsavel r
        JOIN tb_aluno_responsavel ar ON ar.id_responsavel = r.id_responsavel
        JOIN tb_aluno a ON a.id_aluno = ar.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE r.cpf_responsavel = ? AND s.id_escola = ?
    ");
    $stmt->bindValue(1, somenteDigitos($cpf));
    $stmt->bindValue(2, $idEscola);
    $stmt->execute();
    $responsavel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$responsavel) {
        enviarErro("Nenhum responsável cadastrado com esse CPF nesta escola.", 404);
    }

    enviarSucesso("Responsável encontrado.", $responsavel);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao buscar o responsável.", 500, $erro);
}
