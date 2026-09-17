<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * tb_acompanhamento não existia no schema original - foi adicionada
 * nesta rodada (ver database/migrations/002_acompanhamento_biometria.sql)
 * porque o design previa a tela "Acompanhamento" (histórico de contatos
 * com o responsável) e não havia como persistir isso em nenhuma tabela
 * existente.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
$canal = $dados["canal"] ?? null;
$observacao = $dados["observacao"] ?? null;

$canaisAceitos = ["Ligação para responsável", "Mensagem pelo aplicativo", "Reunião presencial", "Comunicado por escrito"];

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!campoPreenchido($canal) || !in_array($canal, $canaisAceitos, true)) {
    enviarErro("Selecione um canal de contato válido.", 422);
}

try {
    $verificaAluno = $conexao->prepare("SELECT id_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindValue(1, $idAluno);
    $verificaAluno->execute();
    if ($verificaAluno->rowCount() === 0) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_acompanhamento (id_aluno, id_coordenacao, canal, observacao)
        VALUES (?, ?, ?, ?)
        RETURNING id_acompanhamento, data_registro
    ");
    $insert->bindValue(1, $idAluno);
    $insert->bindValue(2, $idCoordenacao);
    $insert->bindValue(3, $canal);
    $insert->bindValue(4, $observacao);
    $insert->execute();
    $novo = $insert->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Registro de acompanhamento salvo.", [
        "id_acompanhamento" => $novo["id_acompanhamento"],
        "data_registro"     => $novo["data_registro"],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao salvar o acompanhamento.", 500, $erro);
}
