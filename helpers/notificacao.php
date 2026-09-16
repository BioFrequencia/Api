<?php
/**
 * Cria um registro de notificação PENDENTE para cada responsável
 * vinculado ao aluno. IMPORTANTE: isto só grava a INTENÇÃO de notificar
 * (linha em tb_notificacao, status "pendente") - não existe nenhum
 * provedor de email/SMS configurado nesta rodada, então nenhuma mensagem
 * é de fato enviada a ninguém. A coordenação vê a fila em
 * notificacao/listarNotificacoes.php e confirma manualmente o envio via
 * notificacao/marcarEnviada.php (ex.: depois de ligar/mandar mensagem por
 * fora do sistema). Ver AUDITORIA.md sobre integrar um provedor real.
 *
 * Chamada como um efeito colateral best-effort a partir de
 * marcarFalta.php/marcarAtraso.php: se um aluno não tiver nenhum
 * responsável cadastrado (caso comum nos dados de teste), não faz nada -
 * a falta/atraso em si já foi gravada com sucesso antes desta função ser
 * chamada, então uma falha aqui NUNCA deve derrubar essa resposta.
 */
function criarNotificacoesParaEvento(PDO $conexao, int $idAluno, string $mensagem): void {
    try {
        $stmt = $conexao->prepare("SELECT id_responsavel FROM tb_aluno_responsavel WHERE id_aluno = ?");
        $stmt->bindParam(1, $idAluno);
        $stmt->execute();
        $responsaveis = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($responsaveis)) {
            return;
        }

        $insert = $conexao->prepare("
            INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao)
            VALUES (?, ?, ?, 'pendente')
        ");
        foreach ($responsaveis as $idResponsavel) {
            $insert->execute([$idAluno, $idResponsavel, $mensagem]);
        }
    } catch (PDOException $erro) {
        // Best-effort: registra no log, mas não interrompe o fluxo
        // principal (a falta/atraso já foi gravada com sucesso).
        error_log('[BioFrequenciaApi] Falha ao criar notificação: ' . $erro->getMessage());
    }
}
