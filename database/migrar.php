<?php
/**
 * Aplica as migrações que faltam (ver helpers/migracoes.php) no banco de
 * config/config.php.
 *
 *   php database/migrar.php            aplica as que faltam
 *   php database/migrar.php --listar   só mostra o que falta, sem aplicar
 *
 * O docker-entrypoint.sh roda este script antes de subir o Apache:
 *   - banco fora do ar (depois de algumas tentativas): segue em frente; a
 *     API sobe e o health check diz que o banco está indisponível;
 *   - banco sem as migrações manuais (001 a 014): não aplica nada e segue;
 *     o health check lista o que falta (rode database/migracao-completa.sql);
 *   - uma migração falhou: sai com erro e o container não sobe - o Render
 *     mantém a versão anterior no ar, e nada da migração fica gravado.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/migracoes.php';

$soListar = in_array('--listar', $argv, true);

$conexao = null;
for ($tentativa = 1; $tentativa <= 3 && $conexao === null; $tentativa++) {
    try {
        $conexao = new PDO(
            "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
            DB_USER,
            DB_PASSWORD,
            // Mesmo motivo de database/conexao.php (pooler em modo Transaction).
            [PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
        );
    } catch (PDOException $erro) {
        // Só a mensagem do erro (a senha do banco nunca aparece nela).
        fwrite(STDERR, "Migrações: banco indisponível (tentativa $tentativa de 3): " . $erro->getMessage() . "\n");
        if ($tentativa < 3) {
            sleep(3);
        }
    }
}
if ($conexao === null) {
    fwrite(STDERR, "Migrações: nada aplicado agora; a API sobe e o health check mostra o banco indisponível.\n");
    exit(0);
}

try {
    if (!schemaBaseCompleto($conexao)) {
        echo "Migrações: o banco ainda não tem as migrações 001 a 014 (rode database/migracao-completa.sql). Nenhuma automática aplicada.\n";
        exit(0);
    }
    prepararRegistroDeMigracoes($conexao);
    $pendentes = migracoesParaAplicar(migracoesDoDiretorio(__DIR__ . '/migrations'), migracoesRegistradas($conexao));
    if ($pendentes === []) {
        echo "Migrações: banco em dia.\n";
        exit(0);
    }
    if ($soListar) {
        echo "Migrações que faltam: " . implode(', ', array_map('basename', $pendentes)) . "\n";
        exit(0);
    }
    foreach ($pendentes as $numero => $caminho) {
        $aplicada = aplicarMigracao($conexao, $numero, $caminho);
        echo "Migração " . basename($caminho) . ($aplicada ? " aplicada.\n" : " já tinha sido aplicada por outra instância.\n");
    }
} catch (Throwable $erro) {
    fwrite(STDERR, "ERRO: migração não aplicada (nada dela ficou no banco): " . $erro->getMessage() . "\n");
    exit(1);
}
