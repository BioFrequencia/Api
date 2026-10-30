<?php
/**
 * Fila de notificações aos responsáveis (tb_notificacao).
 *
 * Status: o banco de produção tem a restrição
 *   chk_status_notificacao CHECK (status_notificacao IN ('Enviado','Erro','Pendente'))
 * criada junto com o schema original. O código gravava 'pendente' e
 * 'enviada' (minúsculo, outro gênero) - toda inserção era recusada pelo
 * banco, o erro ia só para o log (a criação é best-effort) e NENHUMA
 * notificação chegou a existir em produção. Os valores do banco ficam
 * aqui, num lugar só; para os clientes (site/desktop) a API continua
 * respondendo 'pendente' / 'enviada' / 'erro', como sempre respondeu.
 */

const NOTIFICACAO_PENDENTE = 'Pendente';
const NOTIFICACAO_ENVIADA  = 'Enviado';
const NOTIFICACAO_ERRO     = 'Erro';

/** Valor do cliente ('pendente', 'enviada', 'erro'...) -> valor do banco, ou null se inválido. */
function statusNotificacaoParaBanco(?string $status): ?string {
    return match (mb_strtolower(trim((string) $status))) {
        'pendente'            => NOTIFICACAO_PENDENTE,
        'enviada', 'enviado'  => NOTIFICACAO_ENVIADA,
        'erro'                => NOTIFICACAO_ERRO,
        default               => null,
    };
}

/** Valor do banco -> valor que a API devolve aos clientes. */
function statusNotificacaoParaApi(?string $statusBanco): string {
    return match ($statusBanco) {
        NOTIFICACAO_ENVIADA => 'enviada',
        NOTIFICACAO_ERRO    => 'erro',
        default             => 'pendente',
    };
}

/**
 * Cria um registro de notificação PENDENTE para cada responsável
 * vinculado ao aluno. Isto só grava a INTENÇÃO de notificar: sem
 * provedor de email/WhatsApp configurado, nenhuma mensagem é enviada
 * automaticamente. A coordenação vê a fila em
 * notificacao/listarNotificacoes.php e confirma o envio em
 * notificacao/marcarEnviada.php depois de avisar por fora do sistema.
 * (O contato automático do nível máximo de alerta fica em
 * helpers/contatoResponsavel.php.)
 *
 * Chamada como efeito colateral best-effort depois que a falta/atraso
 * já foi gravada: se o aluno não tiver responsável, não faz nada, e uma
 * falha aqui NUNCA deve derrubar a resposta principal.
 *
 * Devolve quantas notificações foram criadas (útil para os testes).
 */
function criarNotificacoesParaEvento(PDO $conexao, int $idAluno, string $mensagem): int {
    try {
        $stmt = $conexao->prepare("SELECT id_responsavel FROM tb_aluno_responsavel WHERE id_aluno = ?");
        $stmt->bindValue(1, $idAluno);
        $stmt->execute();
        $responsaveis = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($responsaveis)) {
            return 0;
        }

        $insert = $conexao->prepare("
            INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao, canal)
            VALUES (?, ?, ?, ?, 'manual')
        ");
        foreach ($responsaveis as $idResponsavel) {
            $insert->execute([$idAluno, $idResponsavel, $mensagem, NOTIFICACAO_PENDENTE]);
        }
        return count($responsaveis);
    } catch (PDOException $erro) {
        // Best-effort: registra no log, mas não interrompe o fluxo
        // principal (a falta/atraso já foi gravada com sucesso).
        error_log('[BioFrequenciaApi] Falha ao criar notificação: ' . $erro->getMessage());
        return 0;
    }
}
