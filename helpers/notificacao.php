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
