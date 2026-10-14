<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Decisão da coordenação sobre uma ocorrência enviada pelo app.
 *
 * Aprovar cria a falta ou o atraso de verdade (em tb_falta/tb_atraso,
 * exatamente as mesmas tabelas do lançamento manual pelo site) e gera as
 * notificações aos responsáveis, igual ao fluxo manual. Rejeitar só
 * registra a decisão - o pedido não é apagado, para o histórico do caso
 * continuar completo.
 *
 * Aprovação e criação da falta acontecem na mesma transação: sem isso,
 * uma falha no meio deixaria a ocorrência marcada como aprovada sem a
 * falta correspondente existir.
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
        SELECT o.id_ocorrencia, o.id_aluno, o.tipo, o.motivo, o.observacao, o.status
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
        enviarSucesso("Ocorrência rejeitada. O registro foi mantido no histórico.", [
            "id_ocorrencia" => (int) $idOcorrencia,
            "status" => "rejeitado",
        ]);
    }

    $conexao->beginTransaction();

    if ($ocorrencia['tipo'] === 'falta') {
        $ins = $conexao->prepare("
            INSERT INTO tb_falta (id_aluno, id_coordenacao, motivo_falta, justificado_falta, observacao_falta)
            VALUES (?, ?, ?, ?, ?) RETURNING id_falta
        ");
    } else {
        $ins = $conexao->prepare("
            INSERT INTO tb_atraso (id_aluno, id_coordenacao, motivo_atraso, justificado_atraso, observacao_atraso)
            VALUES (?, ?, ?, ?, ?) RETURNING id_atraso
        ");
    }
    $ins->bindValue(1, $ocorrencia['id_aluno']);
    $ins->bindValue(2, $idCoordenacao);
    $ins->bindValue(3, $ocorrencia['motivo']);
    $ins->bindValue(4, $justificado ? 'true' : 'false');
    $ins->bindValue(5, $ocorrencia['observacao']);
    $ins->execute();
    $idCriado = (int) $ins->fetchColumn();

    $colunaCriada = $ocorrencia['tipo'] === 'falta' ? 'id_falta_criada' : 'id_atraso_criado';
    $upd = $conexao->prepare("
        UPDATE tb_ocorrencia_pendente
        SET status = 'aprovado', revisado_em = NOW(),
            id_coordenacao_revisao = ?, observacao_revisao = ?, $colunaCriada = ?
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

    // Mesmas notificações do lançamento manual (helpers/notificacao.php),
    // fora da transação de propósito: falhar ao enfileirar um aviso não
    // pode desfazer a falta que já foi gravada.
    $rotulo = $ocorrencia['tipo'] === 'falta' ? 'Falta registrada' : 'Atraso registrado';
    $mensagem = "$rotulo em " . date('d/m/Y')
        . ($ocorrencia['motivo'] ? ". Motivo: {$ocorrencia['motivo']}" : ".")
        . " (enviado pelo aplicativo e aprovado pela coordenação)";
    criarNotificacoesParaEvento($conexao, (int) $ocorrencia['id_aluno'], $mensagem);

    enviarSucesso("Ocorrência aprovada e lançada.", [
        "id_ocorrencia"   => (int) $idOcorrencia,
        "status"          => "aprovado",
        "tipo"            => $ocorrencia['tipo'],
        "id_registro"     => $idCriado,
    ]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao analisar a ocorrência.", 500, $erro);
}
