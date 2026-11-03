<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/registroEvento.php';
require_once __DIR__ . '/../helpers/avisos.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Decisão da coordenação sobre uma ocorrência enviada pelo app.
 *
 * Aprovar cria a entrada atrasada de verdade (em tb_atraso, a mesma
 * tabela do lançamento manual pelo site, com as mesmas regras de dia e
 * horário) e dispara os avisos aos responsáveis, igual ao fluxo manual.
 * Ocorrência de falta só pode ser rejeitada: faltas não são mais
 * registradas. Rejeitar só registra a decisão - o pedido não é apagado,
 * para o histórico do caso continuar completo.
 *
 * Aprovação e criação do registro acontecem na mesma transação: sem
 * isso, uma falha no meio deixaria a ocorrência marcada como aprovada sem
 * o registro correspondente existir.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idOcorrencia = $dados["id_ocorrencia"] ?? null;
$decisao      = $dados["decisao"] ?? null;
$observacao   = $dados["observacao_revisao"] ?? null;
$justificado  = !empty($dados["justificado"]);

if (!inteiroValido($idOcorrencia)) {
    enviarErro("Informe uma ocorrência válida.", 422);
}
if (!in_array($decisao, ["aprovar", "rejeitar"], true)) {
    enviarErro("Decisão inválida (use \"aprovar\" ou \"rejeitar\").", 422);
}

try {
    $busca = $conexao->prepare("
        SELECT o.id_ocorrencia, o.id_aluno, o.tipo, o.motivo, o.observacao, o.status, o.criado_em
        FROM tb_ocorrencia_pendente o
        JOIN tb_aluno a ON a.id_aluno = o.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE o.id_ocorrencia = ? AND s.id_escola = ?
    ");
    $busca->bindValue(1, $idOcorrencia);
    $busca->bindValue(2, $idEscola);
    $busca->execute();
    $ocorrencia = $busca->fetch(PDO::FETCH_ASSOC);

    if (!$ocorrencia) {
        enviarErro("Ocorrência não encontrada.", 404);
    }
    if ($ocorrencia['status'] !== 'pendente') {
        // Duas pessoas da coordenação abrindo a mesma fila ao mesmo tempo.
        enviarErro("Esta ocorrência já foi analisada por outra pessoa.", 409);
    }

    if ($decisao === 'rejeitar') {
        $upd = $conexao->prepare("
            UPDATE tb_ocorrencia_pendente
            SET status = 'rejeitado', revisado_em = NOW(),
                id_coordenacao_revisao = ?, observacao_revisao = ?
            WHERE id_ocorrencia = ? AND status = 'pendente'
        ");
        $upd->bindValue(1, $idCoordenacao);
        $upd->bindValue(2, $observacao !== null ? trim($observacao) : null);
        $upd->bindValue(3, $idOcorrencia);
        $upd->execute();
        if ($upd->rowCount() === 0) {
            enviarErro("Esta ocorrência já foi analisada por outra pessoa.", 409);
        }
        enviarSucesso("Ocorrência rejeitada. O registro foi mantido no histórico.", [
            "id_ocorrencia" => (int) $idOcorrencia,
            "status" => "rejeitado",
        ]);
    }

    // Faltas não são mais registradas (a frequência fica na Sala do
    // Futuro): uma ocorrência de falta, do fluxo anterior, só pode ser
    // rejeitada. O atraso segue as mesmas regras do lançamento manual.
    if ($ocorrencia['tipo'] === 'falta') {
        enviarErro("Faltas não são mais registradas pelo BioFrequência (ficam na Sala do Futuro): rejeite esta ocorrência.", 422);
    }
    exigirHorarioDoRegistro($conexao, 'atraso', (int) $ocorrencia['id_aluno'], $idEscola, $ocorrencia['criado_em']);

    $conexao->beginTransaction();

    // A falta/atraso é do dia em que o aluno ENVIOU, não do dia em que a
    // coordenação aprovou: antes o lançamento usava NOW(), e uma
    // ocorrência de sexta aprovada na segunda virava falta de segunda.
    // Se a coordenação já tinha lançado o mesmo dia à mão, a aprovação
    // aponta para esse lançamento em vez de criar uma duplicata.
    $registro = registrarEventoFrequencia(
        $conexao,
        $ocorrencia['tipo'],
        (int) $ocorrencia['id_aluno'],
        (int) $idCoordenacao,
        $ocorrencia['criado_em'],
        $ocorrencia['motivo'],
        $ocorrencia['observacao'],
        $justificado,
        ['origem' => 'ocorrencia']
    );
    $idCriado = $registro['id'];

    $upd = $conexao->prepare("
        UPDATE tb_ocorrencia_pendente
        SET status = 'aprovado', revisado_em = NOW(),
            id_coordenacao_revisao = ?, observacao_revisao = ?, id_atraso_criado = ?
        WHERE id_ocorrencia = ? AND status = 'pendente'
    ");
    $upd->bindValue(1, $idCoordenacao);
    $upd->bindValue(2, $observacao !== null ? trim($observacao) : null);
    $upd->bindValue(3, $idCriado);
    $upd->bindValue(4, $idOcorrencia);
    $upd->execute();

    if ($upd->rowCount() === 0) {
        // Outra pessoa decidiu entre a leitura e a escrita.
        $conexao->rollBack();
        enviarErro("Esta ocorrência já foi analisada por outra pessoa.", 409);
    }

    $conexao->commit();

    if ($registro['ja_existia']) {
        // Os responsáveis já foram avisados quando o dia foi lançado.
        enviarSucesso("Ocorrência aprovada. Este dia já estava lançado; nenhuma duplicata foi criada.", [
            "id_ocorrencia" => (int) $idOcorrencia,
            "status"        => "aprovado",
            "tipo"          => $ocorrencia['tipo'],
            "id_registro"   => $idCriado,
            "ja_existia"    => true,
        ]);
    }

    // Mesmos avisos do lançamento manual (helpers/avisos.php), fora da
    // transação de propósito: falhar ao avisar não pode desfazer o
    // registro que já foi gravado.
    $nomeAluno = (string) (turmaDoAluno($conexao, (int) $ocorrencia['id_aluno'])['nome_aluno'] ?? '');
    avisosDepoisDoRegistro($conexao, $idEscola, (int) $ocorrencia['id_aluno'], $nomeAluno, 'atraso', $registro['data'], $ocorrencia['motivo']);

    enviarSucesso("Ocorrência aprovada e lançada.", [
        "id_ocorrencia" => (int) $idOcorrencia,
        "status"        => "aprovado",
        "tipo"          => $ocorrencia['tipo'],
        "id_registro"   => $idCriado,
        "ja_existia"    => false,
    ]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao analisar a ocorrência.", 500, $erro);
}
