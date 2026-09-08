<?php
/**
 * Verifica a senha digitada contra o valor salvo no banco, com suporte
 * a migração transparente de contas antigas que ainda guardam a senha
 * em texto puro (formato usado antes desta correção).
 *
 * Se a senha antiga (texto puro) bater, sinaliza precisaAtualizar=true
 * para que o endpoint gere um hash novo e atualize o registro —
 * assim nenhuma conta existente fica impossibilitada de logar.
 *
 * @return array{valida: bool, precisaAtualizar: bool}
 */
function verificarSenhaComMigracao(string $senhaDigitada, ?string $hashArmazenado): array {
    if ($hashArmazenado === null || $hashArmazenado === '') {
        return ['valida' => false, 'precisaAtualizar' => false];
    }

    $infoHash = password_get_info($hashArmazenado);
    $pareceHash = $infoHash['algo'] !== null && $infoHash['algo'] !== 0;

    if ($pareceHash) {
        return ['valida' => password_verify($senhaDigitada, $hashArmazenado), 'precisaAtualizar' => false];
    }

    // Registro legado: senha ainda estava em texto puro no banco.
    $senhaCorreta = hash_equals($hashArmazenado, $senhaDigitada);
    return ['valida' => $senhaCorreta, 'precisaAtualizar' => $senhaCorreta];
}
