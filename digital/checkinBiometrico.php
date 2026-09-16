<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/dispositivo.php';

/**
 * Chamado pelo app do aluno depois que a biometria (digital/Face ID) foi
 * confirmada LOCALMENTE no celular - a API nunca vê o template
 * biométrico, só recebe um check-in assinado com o segredo trocado em
 * vincularDispositivo.php. Também sem autenticar() (é o celular do
 * aluno chamando, não a coordenação); a autenticidade vem da assinatura.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$deviceUuid  = $dados["device_uuid"] ?? null;
$tipo        = $dados["tipo"] ?? null;
$timestamp   = $dados["timestamp"] ?? null; // ISO 8601, gerado pelo app
$assinatura  = $dados["assinatura"] ?? null; // HMAC-SHA256 hex

$tiposAceitos = ["atraso", "presenca"];

if (!campoPreenchido($deviceUuid) || !campoPreenchido($tipo) || !campoPreenchido($timestamp) || !campoPreenchido($assinatura)) {
    enviarErro("Requisição de check-in incompleta.", 422);
}
if (!in_array($tipo, $tiposAceitos, true)) {
    enviarErro("Tipo de check-in inválido.", 422);
}

$momentoCheckin = DateTime::createFromFormat(DateTime::ATOM, $timestamp) ?: DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $timestamp);
if (!$momentoCheckin) {
    enviarErro("Timestamp em formato inválido (use ISO 8601).", 422);
}

// Tolerância de 5 minutos para o relógio do celular - fora disso, o
// check-in é recusado (mitiga replay de uma assinatura antiga capturada).
$agora = new DateTime('now', new DateTimeZone('UTC'));
$diferencaSegundos = abs($agora->getTimestamp() - $momentoCheckin->getTimestamp());
if ($diferencaSegundos > 300) {
    enviarErro("Check-in fora da janela de tempo aceita. Verifique o relógio do aparelho.", 401);
}

try {
    $stmt = $conexao->prepare("
        SELECT id_dispositivo, id_aluno, device_secret
        FROM tb_dispositivo_aluno
        WHERE device_uuid = ? AND ativo = TRUE
    ");
    $stmt->bindParam(1, $deviceUuid);
    $stmt->execute();
    $dispositivo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispositivo) {
        enviarErro("Dispositivo não vinculado ou revogado.", 401);
    }

    $assinaturaEsperada = assinaturaCheckin($deviceUuid, $tipo, $timestamp, $dispositivo['device_secret']);
    if (!hash_equals($assinaturaEsperada, strtolower($assinatura))) {
        enviarErro("Assinatura inválida.", 401);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_checkin_biometrico (id_aluno, id_dispositivo, tipo, data_checkin)
        VALUES (?, ?, ?, ?)
        RETURNING id_checkin
    ");
    $insert->bindParam(1, $dispositivo['id_aluno']);
    $insert->bindParam(2, $dispositivo['id_dispositivo']);
    $insert->bindParam(3, $tipo);
    $insert->bindValue(4, $momentoCheckin->format('Y-m-d H:i:s'));
    $insert->execute();
    $novo = $insert->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Check-in registrado. Aguardando confirmação da coordenação.", [
        "id_checkin" => $novo["id_checkin"],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao registrar o check-in.", 500, $erro);
}
