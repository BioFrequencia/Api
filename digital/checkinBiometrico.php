<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
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
 *
 * A verificação de assinatura/janela de tempo é a mesma do envio de
 * ocorrências (helpers/dispositivo.php) - antes este arquivo tinha a
 * própria cópia, com o mesmo problema de fuso no timestamp.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$tipo = $dados["tipo"] ?? null;
if (!in_array($tipo, ["atraso", "presenca"], true)) {
    enviarErro("Tipo de check-in inválido.", 422);
}

$dispositivo = exigirDispositivoAssinado(
    $conexao,
    $dados["device_uuid"] ?? null,
    $tipo,
    $dados["timestamp"] ?? null,    // ISO 8601, gerado pelo app
    $dados["assinatura"] ?? null    // HMAC-SHA256 hex
);

try {
    // Um check-in por tipo, por dia, enquanto a coordenação não revisa:
    // o botão é um toque só e o aluno tende a tocar de novo quando a
    // rede demora. O segundo toque devolve o mesmo check-in.
    $existente = $conexao->prepare("
        SELECT id_checkin FROM tb_checkin_biometrico
        WHERE id_aluno = ? AND tipo = ? AND confirmado_em IS NULL
          AND " . sqlDataNaEscola('data_checkin') . " = ?::date
        LIMIT 1
    ");
    $existente->execute([$dispositivo['id_aluno'], $tipo, dataNaEscola($dispositivo['momento_utc'])]);
    $idExistente = $existente->fetchColumn();
    if ($idExistente !== false) {
        enviarSucesso("Seu check-in de hoje já foi registrado e aguarda a coordenação.", [
            "id_checkin" => (int) $idExistente,
        ]);
    }

    $insert = $conexao->prepare("
        INSERT INTO tb_checkin_biometrico (id_aluno, id_dispositivo, tipo, data_checkin)
        VALUES (?, ?, ?, ?)
        RETURNING id_checkin
    ");
    $insert->bindValue(1, $dispositivo['id_aluno']);
    $insert->bindValue(2, $dispositivo['id_dispositivo']);
    $insert->bindValue(3, $tipo);
    $insert->bindValue(4, $dispositivo['momento_utc']);
    $insert->execute();

    enviarSucesso("Check-in registrado. Aguardando confirmação da coordenação.", [
        "id_checkin" => (int) $insert->fetchColumn(),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao registrar o check-in.", 500, $erro);
}
