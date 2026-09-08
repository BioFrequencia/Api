<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

// O id da coordenação agora vem do token validado, não mais do corpo da
// requisição — antes disso, qualquer requisição podia "assinar" a falta
// em nome de outra coordenação (isso estava documentado como pendência
// neste mesmo arquivo).
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!isset($dados["id_aluno"]) || !inteiroValido($dados["id_aluno"])) {
    enviarErro("Informe um aluno válido.", 422);
}

$idAluno     = $dados["id_aluno"];
$dtaFalta    = $dados["dta_falta"] ?? null; // se não vier, o banco assume o momento atual
$motivoFalta = $dados["motivo_falta"] ?? null;
$observacao  = $dados["observacao_falta"] ?? null;
$justificado = (isset($dados["justificado_falta"]) && $dados["justificado_falta"]) ? 'true' : 'false';
// bindado como string 'true'/'false': PDO::PARAM_BOOL tem um bug conhecido
// no driver pgsql que costuma virar '' e quebra o insert numa coluna boolean

try {
    // Confere se o aluno existe antes de tentar gravar a falta
    $verificaAluno = $conexao->prepare("SELECT id_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindParam(1, $idAluno);
    $verificaAluno->execute();

    if ($verificaAluno->rowCount() === 0) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_falta (id_aluno, id_coordenacao, dta_falta, motivo_falta, justificado_falta, observacao_falta)
        VALUES (?, ?, COALESCE(?, NOW()), ?, ?, ?)
        RETURNING id_falta, dta_falta
    ");
    $insert->bindParam(1, $idAluno);
    $insert->bindParam(2, $idCoordenacao);
    $insert->bindValue(3, $dtaFalta, $dtaFalta === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $insert->bindParam(4, $motivoFalta);
    $insert->bindParam(5, $justificado);
    $insert->bindParam(6, $observacao);
    $insert->execute();

    $novaFalta = $insert->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Falta registrada com sucesso.", [
        "id_falta"  => $novaFalta["id_falta"],
        "dta_falta" => $novaFalta["dta_falta"],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao registrar a falta.", 500, $erro);
}
