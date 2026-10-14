<?php
/**
 * CORS da BioFrequenciaApi.
 *
 * Precisa continuar sendo o PRIMEIRO require de cada endpoint (antes de
 * database/conexao.php): o navegador manda uma requisição OPTIONS de
 * "preflight" antes de qualquer POST com Authorization/Content-Type
 * customizado, e ela não deve abrir conexão com o banco nem rodar regra
 * de negócio nenhuma - só responder os cabeçalhos e encerrar.
 *
 * Por que deixou de ser "Access-Control-Allow-Origin: *":
 * o curinga funciona, mas libera a API para qualquer site da internet
 * chamar em nome de quem estiver com a aba aberta. Como a autenticação é
 * por token no cabeçalho Authorization (nunca por cookie), não existe o
 * risco de CSRF clássico, mas também não existe motivo para manter a
 * porta aberta: a lista de origens permitidas é curta e conhecida.
 *
 * A lista vem da variável de ambiente CORS_ORIGENS (origens separadas por
 * vírgula). Sem ela - que é o caso de quem roda no XAMPP para
 * desenvolver -, valem as origens locais padrão abaixo. Em produção
 * (Render), defina CORS_ORIGENS com o domínio real do site.
 *
 * O valor especial "*" em CORS_ORIGENS reativa o curinga, para o caso de
 * uma demonstração em que o domínio ainda não é conhecido. Não use isso
 * como configuração permanente.
 */

/** Origens liberadas quando CORS_ORIGENS não está definida. */
const CORS_ORIGENS_PADRAO = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost',
    'http://127.0.0.1',
];

function corsOrigensPermitidas(): array {
    $configurado = getenv('CORS_ORIGENS');
    if ($configurado === false || trim($configurado) === '') {
        return CORS_ORIGENS_PADRAO;
    }
    return array_values(array_filter(array_map('trim', explode(',', $configurado))));
}

function corsAplicar(): void {
    $permitidas = corsOrigensPermitidas();
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array('*', $permitidas, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origem !== '' && in_array($origem, $permitidas, true)) {
        // Devolve a origem exata (e não a lista): é o que o navegador
        // exige - ele compara o cabeçalho com a própria origem dele.
        header('Access-Control-Allow-Origin: ' . $origem);
        // Sem Vary, um proxy/CDN no meio pode guardar a resposta de uma
        // origem e devolvê-la para outra, quebrando o CORS de forma
        // intermitente e dificílima de reproduzir.
        header('Vary: Origin');
    }
    // Origem não permitida: nenhum cabeçalho é enviado e o navegador
    // bloqueia por conta própria. O app de celular (que não é navegador
    // e não manda Origin) continua funcionando normalmente.

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

corsAplicar();
