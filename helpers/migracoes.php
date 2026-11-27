<?php
require_once __DIR__ . '/schema.php';

/**
 * Migrações aplicadas pela própria API (database/migrar.php, chamado pelo
 * docker-entrypoint.sh a cada início do container).
 *
 * As migrações 001 a 014 foram aplicadas à mão no SQL Editor do Supabase
 * (database/migracao-completa.sql); esquecer de rodar uma delas já deixou
 * a produção dias com erro 500. A partir da MIGRACAO_AUTOMATICA_DESDE, a
 * API aplica sozinha as que faltam, uma transação por migração, e registra
 * cada uma em tb_migracao.
 *
 * Regras das migrações automáticas (conferidas em tests/helpers_test.php):
 *   - nome NNN_descricao.sql, com NNN >= MIGRACAO_AUTOMATICA_DESDE;
 *   - sem BEGIN/COMMIT: quem abre e fecha a transação é o migrar.php;
 *   - idempotentes (IF NOT EXISTS...), porque o CI e o docker-compose.teste
 *     também as rodam direto pelo psql, duas vezes;
 *   - nada de CREATE INDEX CONCURRENTLY (não roda dentro de transação).
 */

const MIGRACAO_AUTOMATICA_DESDE = 15;

/** Chave da trava (pg_advisory_xact_lock): duas instâncias subindo juntas não aplicam a mesma migração. */
const TRAVA_MIGRACOES = 7015015;

/**
 * As migrações do diretório: [numero => caminho], em ordem. Só os arquivos
 * no formato NNN_descricao.sql. Função pura (só lê o diretório).
 *
 * @return array<int, string>
 */
function migracoesDoDiretorio(string $diretorio): array {
    $migracoes = [];
    foreach (scandir($diretorio) ?: [] as $arquivo) {
        if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $arquivo, $partes)) {
            $migracoes[(int) $partes[1]] = rtrim($diretorio, '/\\') . DIRECTORY_SEPARATOR . $arquivo;
        }
    }
    ksort($migracoes);
    return $migracoes;
}

/**
 * Quais aplicar agora: as de número >= $desde que ainda não estão em
 * tb_migracao, em ordem. Função pura.
 *
 * @param array<int, string> $migracoes numero => caminho
 * @param int[] $aplicadas números já registrados
 * @return array<int, string>
 */
function migracoesParaAplicar(array $migracoes, array $aplicadas, int $desde = MIGRACAO_AUTOMATICA_DESDE): array {
    $jaAplicadas = array_flip(array_map('intval', $aplicadas));
    $pendentes = [];
    foreach ($migracoes as $numero => $caminho) {
        if ($numero >= $desde && !isset($jaAplicadas[$numero])) {
            $pendentes[$numero] = $caminho;
        }
    }
    ksort($pendentes);
    return $pendentes;
}

/**
 * Problema que impede uma migração de rodar sozinha, ou null. Função pura:
 * recebe o SQL do arquivo.
 */
function problemaNaMigracaoAutomatica(string $sql): ?string {
    $semComentarios = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
    if (preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK|START\s+TRANSACTION)\s*;/mi', $semComentarios)) {
        return 'não pode ter BEGIN/COMMIT (o migrar.php abre a transação)';
    }
    if (preg_match('/\bCONCURRENTLY\b/i', $semComentarios)) {
        return 'CREATE INDEX CONCURRENTLY não roda dentro de transação';
    }
    return null;
}

/** O banco já tem as migrações manuais (001 a 014)? Sem elas, as automáticas não têm sobre o que rodar. */
function schemaBaseCompleto(PDO $conexao): bool {
    foreach (migracoesPendentes($conexao) as $numero) {
        if ((int) $numero < MIGRACAO_AUTOMATICA_DESDE) {
            return false;
        }
    }
    return true;
}

function prepararRegistroDeMigracoes(PDO $conexao): void {
    // RLS ligado e sem política, como as outras tabelas: só a API lê.
    $conexao->exec("
        CREATE TABLE IF NOT EXISTS tb_migracao (
            numero      INT PRIMARY KEY,
            arquivo     VARCHAR(150) NOT NULL,
            aplicada_em TIMESTAMP NOT NULL DEFAULT NOW()
        );
        ALTER TABLE tb_migracao ENABLE ROW LEVEL SECURITY;
    ");
}

/** @return int[] */
function migracoesRegistradas(PDO $conexao): array {
    return array_map('intval', $conexao->query("SELECT numero FROM tb_migracao")->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Aplica uma migração numa transação só. Devolve false quando outra
 * instância já a tinha aplicado (conferido depois de pegar a trava).
 * Lança a exceção do banco se o SQL falhar: nada da migração fica gravado.
 */
function aplicarMigracao(PDO $conexao, int $numero, string $caminho): bool {
    $sql = file_get_contents($caminho);
    if ($sql === false) {
        throw new RuntimeException("Não consegui ler $caminho.");
    }
    $problema = problemaNaMigracaoAutomatica($sql);
    if ($problema !== null) {
        throw new RuntimeException(basename($caminho) . ": $problema.");
    }
    $conexao->beginTransaction();
    try {
        $conexao->query("SELECT pg_advisory_xact_lock(" . TRAVA_MIGRACOES . ")");
        $ja = $conexao->prepare("SELECT 1 FROM tb_migracao WHERE numero = ?");
        $ja->execute([$numero]);
        if ($ja->fetchColumn()) {
            $conexao->rollBack();
            return false;
        }
        $conexao->exec($sql);
        $conexao->prepare("INSERT INTO tb_migracao (numero, arquivo) VALUES (?, ?)")->execute([$numero, basename($caminho)]);
        $conexao->commit();
        return true;
    } catch (Throwable $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        throw $erro;
    }
}
