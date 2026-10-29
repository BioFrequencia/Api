<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Confirma que a coordenação revisou um check-in biométrico. Isto só
 * marca o check-in como tratado - registrar a falta/atraso oficial
 * continua sendo feito pelos endpoints já existentes (marcarFalta.php/
 * marcarAtraso.php), chamados pelo site/desktop a partir dos dados deste
 * check-in. Ver comentário em tb_checkin_biometrico sobre por que não
 * gravamos direto em tb_atraso a partir do celular do aluno.
 *
 * Só check-ins de alunos da escola de quem confirma.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idCheckin = $dados["id_checkin"] ?? null;

if (!inteiroValido($idCheckin)) {
    enviarErro("Informe um check-in válido.", 422);
}

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $update = $conexao->prepare("
        UPDATE tb_checkin_biometrico c
        SET confirmado_em = NOW(), id_coordenacao_confirmacao = ?
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE c.id_checkin = ? AND c.confirmado_em IS NULL
          AND a.id_aluno = c.id_aluno AND s.id_escola = ?
    ");
    $update->execute([$idCoordenacao, $idCheckin, $idEscola]);

    if ($update->rowCount() === 0) {
        enviarErro("Check-in não encontrado ou já confirmado.", 404);
    }

    enviarSucesso("Check-in confirmado.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao confirmar o check-in.", 500, $erro);
}
