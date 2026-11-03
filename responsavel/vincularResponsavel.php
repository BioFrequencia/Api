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
 * Vincula um responsável que já é desta escola a outro aluno dela
 * (irmãos). As duas pontas precisam ser da escola de quem chama: sem
 * isso, bastava vincular o id de um responsável de outra escola a um
 * aluno próprio para depois ler CPF e telefone dele em
 * listarResponsaveis.php.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;
$idResponsavel = $dados["id_responsavel"] ?? null;
[$parentesco, $erroParentesco] = lerParentesco($dados["parentesco"] ?? null);

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!inteiroValido($idResponsavel)) {
    enviarErro("Informe um responsável válido.", 422);
}
if ($erroParentesco !== null) {
    enviarErro($erroParentesco, 422);
}

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);

try {
    if (!responsavelVisivelNaEscola($conexao, $idResponsavel, $idEscola)) {
        enviarErro("Responsável não encontrado.", 404);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_aluno_responsavel (id_aluno, id_responsavel, parentesco) VALUES (?, ?, ?)
        ON CONFLICT (id_aluno, id_responsavel) DO UPDATE SET parentesco = COALESCE(EXCLUDED.parentesco, tb_aluno_responsavel.parentesco)
    ");
    $insert->execute([$idAluno, $idResponsavel, $parentesco]);

    enviarSucesso("Responsável vinculado ao aluno.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao vincular o responsável.", 500, $erro);
}
