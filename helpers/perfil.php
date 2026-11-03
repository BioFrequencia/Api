<?php
require_once __DIR__ . '/sessao.php';

/** Dados da conta mostrados em Minha conta; null se a conta não existe. */
function perfilDaCoordenacao(PDO $conexao, int $idCoordenacao): ?array {
    $stmt = $conexao->prepare("
        SELECT c.nome_coordenacao, c.email_coordenacao, c.papel, e.nome_escola, e.municipio,
               (e.codigo_cie IS NOT NULL) AS escola_do_catalogo
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
