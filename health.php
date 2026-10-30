<?php
/**
 * Health check da API - usado pelo Render, pelo Docker (HEALTHCHECK) e
 * pelo CI para saber se o container subiu de verdade.
 *
 * Responde 200 só quando a API responde, o banco aceita conexão E o
 * schema tem tudo o que este código usa; 503 em qualquer outro caso.
 *
 * Por que conferir o schema: o código da API já dependia da migração 005
 * (tb_escola, coluna id_escola...) enquanto o banco de produção ainda
 * não a tinha - toda chamada autenticada respondia 500. Com a conferência
 * aqui, o Render não coloca no ar uma versão da API mais nova que o
 * banco (o deploy falha no health check e a versão anterior continua
 * atendendo), e o motivo aparece em "faltando".
 *
 * Não exige autenticação de propósito: um health check que precisa de
 * token não serve para o orquestrador decidir se o container está vivo.
 * Não devolve nenhum dado sensível - nem host, nem usuário, nem versão
 * do banco; só o número das migrações que faltam.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/helpers/cors.php';
require_once __DIR__ . '/helpers/schema.php';

$bancoOk = false;
$detalhe = 'não verificado';
$faltando = [];
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
    $faltando = migracoesPendentes($pdo);
} catch (Throwable $erro) {
    error_log('[BioFrequenciaApi][health] ' . $erro->getMessage());
    $detalhe = 'indisponível';
}

$schemaOk = $bancoOk && empty($faltando);
http_response_code($schemaOk ? 200 : 503);
echo json_encode([
    'sucesso' => $schemaOk,
    'mensagem' => match (true) {
        $schemaOk => 'API no ar.',
        $bancoOk  => 'API no ar, mas o banco precisa das migrações: ' . implode(', ', $faltando) . '.',
        default   => 'API no ar, banco indisponível.',
    },
    'dados' => [
        'api'      => 'ok',
        'banco'    => $detalhe,
        'schema'   => $bancoOk ? ($schemaOk ? 'ok' : 'desatualizado') : 'não verificado',
        'faltando' => $faltando,
    ],
]);
