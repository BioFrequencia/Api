<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * NOVO ENDPOINT (não existia antes).
 *
 * filtroAluno.php já contava atrasos a partir de tb_atraso, mas não havia
 * nenhum endpoint que efetivamente gravasse um atraso — só marcarFalta.php
 * existia. Este arquivo espelha o padrão de marcarFalta.php.
 *
 * IMPORTANTE — colunas assumidas: id_aluno e dta_atraso são as únicas
 * colunas de tb_atraso confirmadas pelo JOIN existente em filtroAluno.php.
 * Se a tabela tiver colunas adicionais (motivo, observação, id_coordenacao
 * de quem registrou etc.), me avise a estrutura real para eu estender
 * este endpoint da mesma forma que tb_falta.
 */

autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}

$idAluno   = $dados["id_aluno"];
$dtaAtraso = $dados["dta_atraso"] ?? null; // se não vier, o banco assume o momento atual

try {
    $verificaAluno = $conexao->prepare("SELECT id_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindParam(1, $idAluno);
    $verificaAluno->execute();

    if ($verificaAluno->rowCount() === 0) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_atraso (id_aluno, dta_atraso)
        VALUES (?, COALESCE(?, NOW()))
        RETURNING id_atraso, dta_atraso
    ");
    $insert->bindParam(1, $idAluno);
    $insert->bindValue(2, $dtaAtraso, $dtaAtraso === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $insert->execute();

    $novoAtraso = $insert->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Atraso registrado com sucesso.", [
        "id_atraso"  => $novoAtraso["id_atraso"],
        "dta_atraso" => $novoAtraso["dta_atraso"],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao registrar o atraso.", 500, $erro);
}
