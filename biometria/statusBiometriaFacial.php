<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/** Situação da biometria de um aluno: consentimento, facial e digitais. */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $stmt = $conexao->prepare("
        SELECT bf.modelo, bf.data_cadastro, bf.atualizado_em, bf.amostras,
               c.nome_responsavel, c.registrado_em AS consentimento_em,
               (SELECT count(*) FROM tb_biometria_digital bd WHERE bd.id_aluno = a.id_aluno) AS digitais
        FROM tb_aluno a
        LEFT JOIN tb_biometria_facial bf ON bf.id_aluno = a.id_aluno
        LEFT JOIN tb_consentimento_biometria c ON c.id_aluno = a.id_aluno
        WHERE a.id_aluno = ?
    ");
    $stmt->bindValue(1, $idAluno);
    $stmt->execute();
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Status carregado com sucesso.", [
        "cadastrado" => $registro['modelo'] !== null,
        "modelo" => $registro['modelo'],
        "data_cadastro" => $registro['data_cadastro'],
        "atualizado_em" => $registro['atualizado_em'],
        "amostras" => $registro['amostras'],
        "digitais" => $registro['digitais'],
        "consentimento" => $registro['nome_responsavel'] === null ? null : [
            "nome_responsavel" => $registro['nome_responsavel'],
            "registrado_em" => $registro['consentimento_em'],
        ],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao consultar a biometria.", 500, $erro);
}
