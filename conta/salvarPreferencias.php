<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/perfil.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Salva as preferências da conta. Basta mandar o que mudou; o resto
 * continua como está. Devolve as preferências completas.
 *
 *   { tema?: "claro" | "escuro" | "sistema", sons_ativos?: bool,
 *     volume_sons?: 0-100, som_confirmacao?: bool, som_notificacao?: bool,
 *     som_erro?: bool }
 */
$idCoordenacao = (int) autenticar();

$dados = json_decode(file_get_contents("php://input"), true);
if (!is_array($dados)) {
    enviarErro("Envie as preferências em JSON.", 422);
}

try {
    [$preferencias, $erro] = lerPreferencias($dados, preferenciasDaCoordenacao($conexao, $idCoordenacao));
    if ($erro !== null) {
        enviarErro($erro, 422);
    }

    // Booleanos como texto 'true'/'false' (ver helpers/registroEvento.php).
    $bool = fn(bool $valor) => $valor ? 'true' : 'false';
    $conexao->prepare("
        INSERT INTO tb_preferencia_coordenacao
            (id_coordenacao, tema, sons_ativos, volume_sons, som_confirmacao, som_notificacao, som_erro, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON CONFLICT (id_coordenacao) DO UPDATE SET
            tema = EXCLUDED.tema, sons_ativos = EXCLUDED.sons_ativos, volume_sons = EXCLUDED.volume_sons,
            som_confirmacao = EXCLUDED.som_confirmacao, som_notificacao = EXCLUDED.som_notificacao,
            som_erro = EXCLUDED.som_erro, atualizado_em = NOW()
    ")->execute([
        $idCoordenacao,
        $preferencias['tema'],
        $bool($preferencias['sons_ativos']),
        $preferencias['volume_sons'],
        $bool($preferencias['som_confirmacao']),
        $bool($preferencias['som_notificacao']),
        $bool($preferencias['som_erro']),
    ]);

    enviarSucesso("Preferências salvas.", $preferencias);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao salvar as preferências.", 500, $erro);
}
