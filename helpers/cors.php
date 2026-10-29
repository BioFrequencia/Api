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
 * O preflight TEM de chegar aqui. Uma versão do .htaccess respondia todo
 * OPTIONS direto no Apache (RewriteRule ... [R=204]); enquanto o próprio
 * .htaccess também mandava os cabeçalhos de CORS, funcionava. Quando esses
 * cabeçalhos saíram do .htaccess (para não refletir qualquer origem), o
 * atalho ficou: o preflight voltava 204 SEM Access-Control-Allow-Origin e
 * o navegador bloqueava toda chamada do site com Authorization ou JSON.
 * Ver tests/integracao_test.php ("preflight").
 *
 * Por que deixou de ser "Access-Control-Allow-Origin: *":
 * o curinga funciona, mas libera a API para qualquer site da internet
 * chamar em nome de quem estiver com a aba aberta. Como a autenticação é
 * por token no cabeçalho Authorization (nunca por cookie), não existe o
 * risco de CSRF clássico, mas também não existe motivo para manter a
 * porta aberta: a lista de origens permitidas é curta e conhecida.
 *
 * A lista vem da variável de ambiente CORS_ORIGENS (origens separadas por
 * vírgula; ver helpers/corsRegras.php para o formato aceito, inclusive o
 * "*" para os previews da Vercel). Sem ela - que é o caso de quem roda no
 * XAMPP para desenvolver -, valem as origens locais padrão. Em produção
 * (Render), defina CORS_ORIGENS com o domínio real do site.
 *
 * O valor especial "*" em CORS_ORIGENS reativa o curinga, para o caso de
 * uma demonstração em que o domínio ainda não é conhecido. Não use isso
 * como configuração permanente.
 */
require_once __DIR__ . '/corsRegras.php';

function corsAplicar(): void {
    $permitidas = corsOrigensPermitidas();
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array('*', $permitidas, true)) {
        header('Access-Control-Allow-Origin: *');
    } else {
        // Sem Vary, um proxy/CDN no meio pode guardar a resposta de uma
        // origem e devolvê-la para outra, quebrando o CORS de forma
        // intermitente e dificílima de reproduzir.
        header('Vary: Origin');
        if (corsOrigemPermitida($origem, $permitidas)) {
            // Devolve a origem exata (e não a lista): é o que o navegador
            // exige - ele compara o cabeçalho com a própria origem dele.
            header('Access-Control-Allow-Origin: ' . $origem);
        }
    }
    // Origem não permitida: nenhum Allow-Origin é enviado e o navegador
    // bloqueia por conta própria. O app de celular (que não é navegador
    // e não manda Origin) continua funcionando normalmente.

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');

    // Quando o Apache desvia para o erro.php (URL inexistente), o pedido
    // chega como GET e o método original fica em REDIRECT_REQUEST_METHOD.
    // Um preflight nesse caso também precisa de 204: assim o navegador
    // segue para a chamada de verdade e mostra o 404 em JSON ("confira a
    // URL base"), em vez de um erro de CORS que não diz nada.
    $metodo = $_SERVER['REDIRECT_REQUEST_METHOD'] ?? $_SERVER['REQUEST_METHOD'] ?? '';
    if ($metodo === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

corsAplicar();
