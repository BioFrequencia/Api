<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

autenticar();

try {
    $stmt = $conexao->query("
        SELECT c.id_checkin, c.id_aluno, al.nome_aluno, s.nome_sala,
               c.tipo, c.metodo, c.data_checkin
        FROM tb_checkin_biometrico c
        JOIN tb_aluno al ON al.id_aluno = c.id_aluno
        LEFT JOIN tb_sala s ON s.id_sala = al.id_sala
        WHERE c.confirmado_em IS NULL
        ORDER BY c.data_checkin DESC
        LIMIT 100
    ");
    $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    enviarSucesso("Check-ins pendentes carregados com sucesso.", $pendentes);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os check-ins pendentes.", 500, $erro);
}
