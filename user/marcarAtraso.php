<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Endpoint criado nesta rodada: filtroAluno.php já contava atrasos a partir
 * de tb_atraso, mas não havia nenhum endpoint que gravasse um atraso - só
 * marcarFalta.php existia. Estrutura de tb_atraso confirmada no schema do
 * Supabase (id_atraso, id_aluno, id_coordenacao, dta_atraso, motivo_atraso,
 * justificado_atraso, observacao_atraso) - é literalmente um espelho de
 * tb_falta, então este arquivo segue exatamente o mesmo padrão de
 * marcarFalta.php, inclusive puxando o id_coordenacao do token e não do
 * corpo da requisição.
 */

$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}

$idAluno     = $dados["id_aluno"];
$dtaAtraso   = $dados["dta_atraso"] ?? null; // se não vier, o banco assume o momento atual
$motivoAtraso = $dados["motivo_atraso"] ?? null;
$observacao   = $dados["observacao_atraso"] ?? null;
$justificado  = (isset($dados["justificado_atraso"]) && $dados["justificado_atraso"]) ? 'true' : 'false';
// bindado como string 'true'/'false' pelo mesmo motivo documentado em
// marcarFalta.php: PDO::PARAM_BOOL tem um bug conhecido no driver pgsql.

try {
    $verificaAluno = $conexao->prepare("SELECT nome_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindParam(1, $idAluno);
    $verificaAluno->execute();
    $aluno = $verificaAluno->fetch(PDO::FETCH_ASSOC);

    if (!$aluno) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_atraso (id_aluno, id_coordenacao, dta_atraso, motivo_atraso, justificado_atraso, observacao_atraso)
        VALUES (?, ?, COALESCE(?, NOW()), ?, ?, ?)
        RETURNING id_atraso, dta_atraso
    ");
    $insert->bindParam(1, $idAluno);
    $insert->bindParam(2, $idCoordenacao);
    $insert->bindValue(3, $dtaAtraso, $dtaAtraso === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $insert->bindParam(4, $motivoAtraso);
    $insert->bindParam(5, $justificado);
    $insert->bindParam(6, $observacao);
    $insert->execute();

    $novoAtraso = $insert->fetch(PDO::FETCH_ASSOC);

    $mensagem = "Atraso registrado para {$aluno['nome_aluno']} em " .
        date('d/m/Y', strtotime($novoAtraso['dta_atraso'])) .
        ($motivoAtraso ? ". Motivo: $motivoAtraso" : ".");
    criarNotificacoesParaEvento($conexao, $idAluno, $mensagem);

    enviarSucesso("Atraso registrado com sucesso.", [
        "id_atraso"  => $novoAtraso["id_atraso"],
        "dta_atraso" => $novoAtraso["dta_atraso"],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao registrar o atraso.", 500, $erro);
}
