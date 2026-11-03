<?php
require_once __DIR__ . '/sessao.php';

/**
 * Dados da conta mostrados em Minha conta; null se a conta não existe.
 * email_pendente: o e-mail novo de uma troca que espera a confirmação pelo
 * link (o login continua sendo o atual até lá).
 */
function perfilDaCoordenacao(PDO $conexao, int $idCoordenacao): ?array {
    $stmt = $conexao->prepare("
        SELECT c.nome_coordenacao, c.email_coordenacao, c.papel, e.nome_escola, e.municipio,
               (e.codigo_cie IS NOT NULL) AS escola_do_catalogo,
               (SELECT v.email FROM tb_verificacao_email v
                WHERE v.id_coordenacao = c.id_coordenacao AND v.finalidade = 'troca_email'
                  AND v.usado_em IS NULL AND v.expira_em > NOW()
                ORDER BY v.criado_em DESC LIMIT 1) AS email_pendente
        FROM tb_coordenacao c JOIN tb_escola e ON e.id_escola = c.id_escola
        WHERE c.id_coordenacao = ?
    ");
    $stmt->execute([$idCoordenacao]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($linha) {
        $linha['escola_do_catalogo'] = valorBooleano($linha['escola_do_catalogo']);
    }
    return $linha ?: null;
}

/** Preferências salvas da conta, ou as padrão para quem nunca salvou. */
function preferenciasDaCoordenacao(PDO $conexao, int $idCoordenacao): array {
    $stmt = $conexao->prepare("
        SELECT tema, sons_ativos, volume_sons, som_confirmacao, som_notificacao, som_erro
        FROM tb_preferencia_coordenacao WHERE id_coordenacao = ?
    ");
    $stmt->execute([$idCoordenacao]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        return PREFERENCIAS_PADRAO;
    }
    return [
        'tema'            => $linha['tema'],
        'sons_ativos'     => valorBooleano($linha['sons_ativos']),
        'volume_sons'     => (int) $linha['volume_sons'],
        'som_confirmacao' => valorBooleano($linha['som_confirmacao']),
        'som_notificacao' => valorBooleano($linha['som_notificacao']),
        'som_erro'        => valorBooleano($linha['som_erro']),
    ];
}
