<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Dispositivos conectados: as sessões abertas e dentro da validade, do
 * uso mais recente para o mais antigo. "atual" marca a deste aparelho.
 */
$idCoordenacao = (int) autenticar();

try {
    $stmt = $conexao->prepare("
        SELECT id_sessao, cliente, dispositivo, lembrar, criada_em, ultimo_acesso, expira_em
        FROM tb_sessao
        WHERE id_coordenacao = ? AND encerrada_em IS NULL AND expira_em > NOW()
        ORDER BY ultimo_acesso DESC
    ");
    $stmt->execute([$idCoordenacao]);
    $atual = sessaoAtual();

    $sessoes = array_map(fn(array $s) => [
        'id_sessao'     => $s['id_sessao'],
        'cliente'       => $s['cliente'],
        'dispositivo'   => $s['dispositivo'],
        'lembrar'       => valorBooleano($s['lembrar']),
        'criada_em'     => $s['criada_em'],
        'ultimo_acesso' => $s['ultimo_acesso'],
        'expira_em'     => $s['expira_em'],
        'atual'         => $s['id_sessao'] === $atual,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Dispositivos conectados.", $sessoes);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao listar os dispositivos conectados.", 500, $erro);
}
