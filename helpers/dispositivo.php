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

/**
 * Valida uma requisição assinada pelo celular do aluno (device_uuid +
 * assinatura HMAC + timestamp) e devolve o dispositivo e o aluno
 * correspondentes. Extraído de digital/checkinBiometrico.php para o
 * envio de ocorrências usar exatamente a mesma verificação - duas
 * implementações da mesma regra divergem com o tempo.
 *
 * Encerra a requisição (401/422) quando algo não confere; só retorna em
 * caso de sucesso.
 */
function exigirDispositivoAssinado(PDO $conexao, ?string $deviceUuid, ?string $tipo, ?string $timestamp, ?string $assinatura): array {
    if (!campoPreenchido($deviceUuid) || !campoPreenchido($tipo)
        || !campoPreenchido($timestamp) || !campoPreenchido($assinatura)) {
        enviarErro("Requisição incompleta.", 422);
    }

    $momento = DateTime::createFromFormat(DateTime::ATOM, $timestamp)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $timestamp);
    if (!$momento) {
        enviarErro("Timestamp em formato inválido (use ISO 8601).", 422);
    }

    // Mesma tolerância de 5 minutos do check-in: fora dela, uma
    // assinatura antiga capturada não pode ser reenviada.
    $agora = new DateTime('now', new DateTimeZone('UTC'));
    if (abs($agora->getTimestamp() - $momento->getTimestamp()) > 300) {
        enviarErro("Requisição fora da janela de tempo aceita. Verifique o relógio do aparelho.", 401);
    }

    $stmt = $conexao->prepare("
        SELECT d.id_dispositivo, d.id_aluno, d.device_secret, a.nome_aluno, s.id_escola
        FROM tb_dispositivo_aluno d
        JOIN tb_aluno a ON a.id_aluno = d.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE d.device_uuid = ? AND d.ativo = TRUE
    ");
    $stmt->bindValue(1, $deviceUuid);
    $stmt->execute();
    $dispositivo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispositivo) {
        // É esta a resposta de "biometria não corresponde a nenhum aluno
        // cadastrado": o aparelho não está vinculado a ninguém.
        enviarErro("Aparelho não vinculado a nenhum aluno. Procure a coordenação.", 401);
    }

    $esperada = assinaturaCheckin($deviceUuid, $tipo, $timestamp, $dispositivo['device_secret']);
    if (!hash_equals($esperada, strtolower($assinatura))) {
        enviarErro("Assinatura inválida.", 401);
    }

    return $dispositivo;
}
