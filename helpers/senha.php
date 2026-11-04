<?php
require_once __DIR__ . '/validacao.php';

/**
 * Senhas: o hash guardado, a conferência no login e a regra de senha forte.
 *
 * O hash é Argon2id (o recomendado hoje para senhas), com os parâmetros
 * mínimos do OWASP: 19 MiB de memória e 2 passadas - pesado para quem
 * tenta adivinhar em massa, leve para um servidor pequeno. As contas com
 * hash antigo (bcrypt, ou texto puro de antes da primeira correção) são
 * convertidas no próximo login certo, sem a pessoa perceber.
 */

const OPCOES_ARGON2ID = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

function gerarHashSenha(string $senha): string {
    // Só por garantia: um PHP sem argon2 usa bcrypt (o login converte depois).
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($senha, PASSWORD_ARGON2ID, OPCOES_ARGON2ID)
        : password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
}

function hashPrecisaAtualizar(string $hash): bool {
    return defined('PASSWORD_ARGON2ID')
        ? password_needs_rehash($hash, PASSWORD_ARGON2ID, OPCOES_ARGON2ID)
        : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Confere a senha digitada contra o valor salvo no banco. precisaAtualizar
 * diz que a senha está certa mas o hash é de um formato antigo (texto puro
 * de antes da primeira correção, ou bcrypt): quem chamou grava um hash novo
 * com gerarHashSenha() - nenhuma conta fica impossibilitada de entrar.
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
        $valida = password_verify($senhaDigitada, $hashArmazenado);
        return ['valida' => $valida, 'precisaAtualizar' => $valida && hashPrecisaAtualizar($hashArmazenado)];
    }

    // Registro legado: senha ainda estava em texto puro no banco.
    $senhaCorreta = hash_equals($hashArmazenado, $senhaDigitada);
    return ['valida' => $senhaCorreta, 'precisaAtualizar' => $senhaCorreta];
}

/**
 * Recusa a senha que não cumpre a regra (helpers/validacao.php), com a
 * lista do que falta em dados.requisitos_senha - a tela marca cada item.
 */
function exigirSenhaForte(?string $senha): void {
    $pendentes = requisitosSenhaPendentes($senha);
    if ($pendentes !== []) {
        enviarErro(mensagemRequisitosSenha($pendentes), 422, null, ['requisitos_senha' => $pendentes]);
    }
}
