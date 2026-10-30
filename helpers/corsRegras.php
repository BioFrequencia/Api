<?php
/**
 * Regras de CORS (funções puras, sem cabeçalho nem exit) - separadas de
 * helpers/cors.php para poderem ser testadas em tests/helpers_test.php.
 * Ver helpers/cors.php para o porquê da allowlist.
 */

/** Origens liberadas quando CORS_ORIGENS não está definida. */
const CORS_ORIGENS_PADRAO = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost',
    'http://127.0.0.1',
];

/**
 * Forma canônica de uma origem para comparação: sem espaços, minúsculas
 * e sem barra final. O navegador manda sempre "https://site.vercel.app";
 * quem configura costuma colar "https://site.vercel.app/" (copiado da
 * barra de endereço) - sem normalizar, essa barra bloqueava o site
 * inteiro com um erro de CORS que não aponta a causa.
 */
function corsNormalizarOrigem(string $origem): string {
    return strtolower(rtrim(trim($origem), '/'));
}

/** Lê a lista de CORS_ORIGENS (ou o padrão local), já normalizada. */
function corsOrigensPermitidas(?string $configurado = null): array {
    if ($configurado === null) {
        $lido = getenv('CORS_ORIGENS');
        $configurado = $lido === false ? '' : $lido;
    }
    if (trim($configurado) === '') {
        return CORS_ORIGENS_PADRAO;
    }
    $origens = array_map('corsNormalizarOrigem', explode(',', $configurado));
    return array_values(array_filter($origens, fn($o) => $o !== ''));
}

/**
 * Uma entrada com "*" vale para UM trecho do nome do host - serve para
 * os deploys de preview da Vercel, cuja URL muda a cada branch:
 *   https://site-git-*-minha-equipe.vercel.app
 * O "*" não atravessa ponto e não pode ser o trecho inteiro: entradas
 * como https://*.vercel.app (qualquer site hospedado na Vercel) são
 * ignoradas de propósito.
 */
function corsPadraoAceito(string $padrao): bool {
    if (!str_contains($padrao, '*')) {
        return true;
    }
    if (!preg_match('#^https?://[^/]+$#', $padrao)) {
        return false;
    }
    // "*" sozinho como trecho do host (://*. ou .*. ou .* no fim).
    return !preg_match('#(://|\.)\*(\.|:|$)#', $padrao);
}

function corsOrigemPermitida(string $origem, array $permitidas): bool {
    $origem = corsNormalizarOrigem($origem);
    if ($origem === '') {
        return false;
    }
    foreach ($permitidas as $permitida) {
        if ($permitida === '*') {
            continue; // tratado à parte em corsAplicar()
        }
        if ($permitida === $origem) {
            return true;
        }
        if (str_contains($permitida, '*') && corsPadraoAceito($permitida)) {
            $regex = '#^' . str_replace('\*', '[a-z0-9-]*', preg_quote($permitida, '#')) . '$#';
            if (preg_match($regex, $origem) === 1) {
                return true;
            }
        }
    }
    return false;
}
