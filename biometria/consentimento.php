<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Consentimento do responsável para o uso da biometria do aluno (LGPD:
 * dado biométrico de criança ou adolescente só com consentimento
 * específico de um dos pais ou responsável - arts. 11 e 14).
 *
 *   { id_aluno, nome_responsavel }  registra (ou atualiza) o consentimento
 *   { id_aluno, revogar: true }     revoga e APAGA a biometria do aluno
 *
 * A coordenação registra depois de colher o termo assinado; a API guarda
 * quem autorizou, quem registrou e quando.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;
$nomeResponsavel = $dados["nome_responsavel"] ?? null;
$revogar = ($dados["revogar"] ?? false) === true;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!$revogar && (!campoPreenchido($nomeResponsavel) || mb_strlen(trim($nomeResponsavel)) > 150)) {
    enviarErro("Informe o nome do responsável que autorizou o uso da biometria.", 422);
}

exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    if ($revogar) {
        $conexao->beginTransaction();
        foreach (['tb_biometria_facial', 'tb_biometria_digital', 'tb_consentimento_biometria'] as $tabela) {
            $apaga = $conexao->prepare("DELETE FROM $tabela WHERE id_aluno = ?");
            $apaga->bindValue(1, $idAluno);
            $apaga->execute();
        }
        $conexao->commit();
        enviarSucesso("Consentimento revogado e biometria do aluno apagada.");
    }

    $grava = $conexao->prepare("
        INSERT INTO tb_consentimento_biometria (id_aluno, nome_responsavel, id_coordenacao)
        VALUES (?, ?, ?)
        ON CONFLICT (id_aluno) DO UPDATE SET
            nome_responsavel = EXCLUDED.nome_responsavel,
            id_coordenacao = EXCLUDED.id_coordenacao,
            registrado_em = NOW()
        RETURNING registrado_em
    ");
    $grava->bindValue(1, $idAluno);
    $grava->bindValue(2, trim($nomeResponsavel));
    $grava->bindValue(3, $idCoordenacao);
    $grava->execute();

    enviarSucesso("Consentimento registrado.", ["registrado_em" => $grava->fetchColumn()]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao registrar o consentimento.", 500, $erro);
}
