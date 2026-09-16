<?php
/**
 * Utilidades para o pareamento do app do aluno e a assinatura HMAC dos
 * check-ins biométricos. Ver comentário em tb_dispositivo_aluno
 * (database/migrations/003_biometria.sql) sobre por que device_secret
 * fica em texto puro no banco.
 */

function gerarCodigoVinculo(): string {
    // Alfabeto sem caracteres ambíguos (sem 0/O, 1/I) - o código é digitado
    // manualmente no app, então precisa ser fácil de ler/copiar.
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $codigo = '';
    for ($i = 0; $i < 8; $i++) {
        $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $codigo;
}

function gerarSegredoDispositivo(): string {
    return bin2hex(random_bytes(32));
}

function assinaturaCheckin(string $deviceUuid, string $tipo, string $timestamp, string $segredo): string {
    return hash_hmac('sha256', "$deviceUuid|$tipo|$timestamp", $segredo);
}
