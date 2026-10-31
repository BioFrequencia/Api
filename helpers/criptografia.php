<?php
/**
 * Criptografia dos dados biométricos gravados no banco (vetores faciais e
 * templates de digital) - dado pessoal sensível pela LGPD.
 *
 * AES-256-GCM com a chave da variável de ambiente BIOMETRIA_CHAVE, que NÃO
 * fica no banco: um vazamento do banco sozinho não expõe a biometria de
 * ninguém. O GCM também detecta adulteração, e o "contexto" (ex.: o id do
 * aluno) entra como dado autenticado - um registro copiado para outro
 * aluno deixa de abrir.
 *
 * Formato gravado: "gcm1:" + base64(iv[12] + tag[16] + texto cifrado).
 */

const PREFIXO_CIFRA_BIOMETRIA = 'gcm1:';

/**
 * Chave de 32 bytes: 64 caracteres hexadecimais ou base64 (o formato do
 * "generateValue" do Render). null quando ausente ou inválida.
 */
function chaveBiometria(?string $configurada = null): ?string {
    if ($configurada === null) {
        $lida = getenv('BIOMETRIA_CHAVE');
        if ($lida === false && defined('BIOMETRIA_CHAVE')) {
            $lida = BIOMETRIA_CHAVE;
        }
        $configurada = $lida === false ? '' : (string) $lida;
    }
    $configurada = trim($configurada);

    if (preg_match('/^[0-9a-fA-F]{64}$/', $configurada)) {
        return hex2bin($configurada);
    }
    $bytes = base64_decode($configurada, true);
    return ($bytes !== false && strlen($bytes) === 32) ? $bytes : null;
}

function cifrarDado(string $texto, string $chave, string $contexto): string {
    $iv = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt($texto, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag, $contexto);
    if ($cifrado === false) {
        throw new RuntimeException('Falha ao cifrar o dado biométrico.');
    }
    return PREFIXO_CIFRA_BIOMETRIA . base64_encode($iv . $tag . $cifrado);
}

/** null quando o dado não abre: chave errada, adulterado ou de outro contexto. */
function decifrarDado(string $guardado, string $chave, string $contexto): ?string {
    if (!str_starts_with($guardado, PREFIXO_CIFRA_BIOMETRIA)) {
        return null;
    }
    $bruto = base64_decode(substr($guardado, strlen(PREFIXO_CIFRA_BIOMETRIA)), true);
    if ($bruto === false || strlen($bruto) < 29) {
        return null;
    }
    $texto = openssl_decrypt(
        substr($bruto, 28), 'aes-256-gcm', $chave, OPENSSL_RAW_DATA,
        substr($bruto, 0, 12), substr($bruto, 12, 16), $contexto
    );
    return $texto === false ? null : $texto;
}

/**
 * Para os endpoints de biometria: sem a chave configurada, a biometria
 * fica desligada (503) em vez de gravar dado sensível sem proteção.
 */
function exigirChaveBiometria(): string {
    $chave = chaveBiometria();
    if ($chave === null) {
        enviarErro("A biometria está desativada no servidor: falta configurar a variável BIOMETRIA_CHAVE.", 503);
    }
    return $chave;
}
