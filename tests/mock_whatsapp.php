<?php
/**
 * Provedor de WhatsApp FALSO, só para os testes de integração - nenhuma
 * mensagem real sai daqui. Sobe com:
 *   php -S 127.0.0.1:8099 tests/mock_whatsapp.php
 * e a API é apontada para ele com WHATSAPP_API_URL=http://127.0.0.1:8099/.
 *
 * Cada envio vira uma linha JSON em WHATSAPP_LOG (destino e cabeçalho de
 * autorização), que o teste lê para conferir quantas mensagens saíram.
 * Números terminados em 0000 simulam falha do provedor (HTTP 500).
 */
$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$destino = (string) ($dados['to'] ?? '');
$log = getenv('WHATSAPP_LOG') ?: sys_get_temp_dir() . '/whatsapp.log';

file_put_contents($log, json_encode([
    'to'    => $destino,
    'auth'  => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'texto' => $dados['text']['body'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
if (str_ends_with($destino, '0000')) {
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'falha simulada']]);
    return;
}
echo json_encode(['messages' => [['id' => 'wamid.teste-' . bin2hex(random_bytes(4))]]]);
