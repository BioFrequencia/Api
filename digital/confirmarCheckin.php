<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Confirma que a coordenação revisou um check-in biométrico. Isto só
 * marca o check-in como tratado - registrar a falta/atraso oficial
 * continua sendo feito pelos endpoints já existentes (marcarFalta.php/
 * marcarAtraso.php), chamados pelo desktop a partir dos dados deste
 * check-in. Ver comentário em tb_checkin_biometrico sobre por que não
 * gravamos direto em tb_atraso a partir do celular do aluno.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idCheckin = $dados["id_checkin"] ?? null;

if (!inteiroValido($idCheckin)) {
    enviarErro("Informe um check-in válido.", 422);
}

try {
    $update = $conexao->prepare("
        UPDATE tb_checkin_biometrico
        SET confirmado_em = NOW(), id_coordenacao_confirmacao = ?
        WHERE id_checkin = ? AND confirmado_em IS NULL
    ");
    $update->bindValue(1, $idCoordenacao);
    $update->bindValue(2, $idCheckin);
    $update->execute();

    if ($update->rowCount() === 0) {
        enviarErro("Check-in não encontrado ou já confirmado.", 404);
    }

    enviarSucesso("Check-in confirmado.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao confirmar o check-in.", 500, $erro);
}
