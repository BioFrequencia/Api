<?php
/**
 * Importa o catálogo de escolas (database/dados/escolas-sp.csv) no banco
 * de config/config.php. Ver helpers/catalogoEscolas.php.
 *
 *   php database/importarEscolas.php                   importa (ou atualiza)
 *   php database/importarEscolas.php --se-necessario   só se esta versão
 *                                                      ainda não foi importada
 *
 * O docker-entrypoint.sh roda a segunda forma a cada início do container
 * e segue em frente se ela falhar (banco fora do ar, migração 008 ainda
 * não aplicada): o catálogo nunca impede a API de subir.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/catalogoEscolas.php';

try {
    $conexao = new PDO(
        "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASSWORD,
        // Mesmo motivo de database/conexao.php: o pooler do Supabase em
        // modo Transaction não guarda prepared statements entre idas.
        [PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $temTabela = $conexao->query("SELECT to_regclass('tb_catalogo_escolas') IS NOT NULL")->fetchColumn();
    if (!valorBooleano($temTabela)) {
        echo "Catálogo de escolas: migração 008 ainda não aplicada; nada importado.\n";
        exit(0);
    }
    if (in_array('--se-necessario', $argv, true) && catalogoJaImportado($conexao)) {
        echo "Catálogo de escolas " . VERSAO_CATALOGO_ESCOLAS . " já importado.\n";
        exit(0);
    }
    $resumo = importarCatalogoEscolas($conexao);
    printf(
        "Catálogo de escolas %s: %d escolas (%d novas, %d atualizadas, %d já cadastradas reconhecidas pelo nome).\n",
        $resumo['versao'], $resumo['total'], $resumo['inseridas'], $resumo['atualizadas'], $resumo['vinculadas']
    );
} catch (Throwable $erro) {
    // Só a mensagem do erro (a senha do banco nunca aparece nela).
    fwrite(STDERR, "Catálogo de escolas não importado: " . $erro->getMessage() . "\n");
    exit(1);
}
