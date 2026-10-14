<?php
/**
 * Health check da API - usado pelo Render, pelo Docker (HEALTHCHECK) e
 * pelo CI para saber se o container subiu de verdade.
 *
 * Responde 200 quando a API responde E o banco aceita conexão; 503
 * quando o banco está fora. Não exige autenticação de propósito: um
 * health check que precisa de token não serve para o orquestrador
 * decidir se o container está vivo. Não devolve nenhum dado sensível -
 * nem host, nem usuário, nem versão do banco.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/helpers/cors.php';

$bancoOk = false;
$detalhe = 'não verificado';
try {
    require_once __DIR__ . '/config/config.php';
    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->query('SELECT 1');
    $bancoOk = true;
    $detalhe = 'ok';
} catch (Throwable $erro) {
    error_log('[BioFrequenciaApi][health] ' . $erro->getMessage());
    $detalhe = 'indisponível';
}

http_response_code($bancoOk ? 200 : 503);
echo json_encode([
    'sucesso' => $bancoOk,
    'mensagem' => $bancoOk ? 'API no ar.' : 'API no ar, banco indisponível.',
    'dados' => ['api' => 'ok', 'banco' => $detalhe],
]);
