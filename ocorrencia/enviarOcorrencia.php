<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/dispositivo.php';

/**
 * Envio de ocorrência pelo app do aluno.
 *
 * Fluxo: o aluno confirma a biometria no próprio aparelho (a API nunca
 * vê o dado biométrico), a API sabe de quem é o aparelho pelo login do
 * aluno no app (aluno/entrar.php), o aluno preenche
 * motivo, observação e se o responsável está ciente, e o envio chega
 * aqui ASSINADO com o segredo do dispositivo.
 *
 * Isto NÃO cria falta nem atraso. Grava um registro pendente que a
 * coordenação revisa em ocorrencia/listarPendentes.php e decide em
 * ocorrencia/revisarOcorrencia.php - o lançamento continua sendo
 * decisão de uma pessoa, o app só adianta o preenchimento.
 *
 * Sem autenticar(): quem chama é o celular do aluno, que não tem (nem
 * deve ter) token de coordenação. A autenticidade vem da assinatura
 * HMAC, igual ao check-in biométrico.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$deviceUuid = $dados["device_uuid"] ?? null;
$tipo       = $dados["tipo"] ?? null;
$timestamp  = $dados["timestamp"] ?? null;
$assinatura = $dados["assinatura"] ?? null;

$motivo            = $dados["motivo"] ?? null;
$observacao        = $dados["observacao"] ?? null;
$responsavelCiente = !empty($dados["responsavel_ciente"]);

if (!in_array($tipo, ["falta", "atraso"], true)) {
    enviarErro("Tipo de ocorrência inválido (use \"falta\" ou \"atraso\").", 422);
}
if (!campoPreenchido($motivo)) {
    enviarErro("Informe o motivo da ocorrência.", 422);
}
if (mb_strlen(trim($motivo)) > 150) {
    enviarErro("O motivo deve ter no máximo 150 caracteres.", 422);
}
if ($observacao !== null && mb_strlen($observacao) > 2000) {
    enviarErro("A observação está longa demais.", 422);
}

$dispositivo = exigirDispositivoAssinado($conexao, $deviceUuid, $tipo, $timestamp, $assinatura);

try {
    $insert = $conexao->prepare("
        INSERT INTO tb_ocorrencia_pendente
            (id_aluno, id_dispositivo, tipo, motivo, observacao, responsavel_ciente, metodo_identificacao)
        VALUES (?, ?, ?, ?, ?, ?, 'biometria_dispositivo')
        RETURNING id_ocorrencia, criado_em
    ");
    $insert->bindValue(1, $dispositivo['id_aluno']);
    $insert->bindValue(2, $dispositivo['id_dispositivo']);
    $insert->bindValue(3, $tipo);
    $insert->bindValue(4, trim($motivo));
    $insert->bindValue(5, $observacao !== null ? trim($observacao) : null);
    $insert->bindValue(6, $responsavelCiente ? 'true' : 'false');
    $insert->execute();
    $criado = $insert->fetch(PDO::FETCH_ASSOC);

    enviarSucesso(
        "Enviado para a coordenação. Você será avisado quando for analisado.",
        [
            "id_ocorrencia" => (int) $criado['id_ocorrencia'],
            "criado_em"     => $criado['criado_em'],
            "nome_aluno"    => $dispositivo['nome_aluno'],
            "status"        => "pendente",
        ],
        201
    );
} catch (PDOException $erro) {
    // 23505 = violação de unicidade: o índice parcial
    // ux_ocorrencia_pendente_dia impede dois envios pendentes do mesmo
    // aluno, do mesmo tipo, no mesmo dia (toque duplo no botão, ou
    // reenvio por falha de rede quando o primeiro já tinha chegado).
    if ($erro->getCode() === '23505') {
        enviarErro("Você já enviou uma ocorrência deste tipo hoje e ela ainda está em análise.", 409);
    }
    enviarErro("Erro no servidor ao registrar a ocorrência.", 500, $erro);
}
