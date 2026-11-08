<?php
/**
 * Provedores FALSOS de WhatsApp e de e-mail, só para os testes de
 * integração - nenhuma mensagem real sai daqui. Sobe com:
 *   php -S 127.0.0.1:8099 tests/mock_provedores.php
 * e a API é apontada para ele com WHATSAPP_API_URL=http://127.0.0.1:8099/
 * e EMAIL_API_URL=http://127.0.0.1:8099/email.
 *
 * Cada envio vira uma linha JSON no log (WHATSAPP_LOG ou EMAIL_LOG), que o
 * teste lê para conferir o que saiu - inclusive os links de confirmação
 * de e-mail. Números de WhatsApp terminados em 0000 e e-mails para
 * @falha.test simulam falha do provedor (HTTP 500).
 */
$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$caminho = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');

if (str_starts_with($caminho, '/email')) {
    // Formato da API do Resend: { from, to: [...], subject, text, html? }
    $destinos = array_map('strval', (array) ($dados['to'] ?? []));
    $log = getenv('EMAIL_LOG') ?: sys_get_temp_dir() . '/email.log';
    file_put_contents($log, json_encode([
        'to'      => $destinos,
        'from'    => $dados['from'] ?? null,
        'auth'    => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'assunto' => $dados['subject'] ?? null,
        'texto'   => $dados['text'] ?? null,
        'html'    => $dados['html'] ?? null,
        'chave'   => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if (array_filter($destinos, fn($destino) => str_ends_with($destino, '@falha.test'))) {
        http_response_code(500);
        echo json_encode(['message' => 'falha simulada']);
        return;
    }
    echo json_encode(['id' => 'email-teste-' . bin2hex(random_bytes(4))]);
    return;
}

$destino = (string) ($dados['to'] ?? '');
$log = getenv('WHATSAPP_LOG') ?: sys_get_temp_dir() . '/whatsapp.log';

file_put_contents($log, json_encode([
    'to'    => $destino,
    'auth'  => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'texto' => $dados['text']['body'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if (str_ends_with($destino, '0000')) {
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'falha simulada']]);
    return;
}
echo json_encode(['messages' => [['id' => 'wamid.teste-' . bin2hex(random_bytes(4))]]]);
