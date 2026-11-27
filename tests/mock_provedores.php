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
 * @falha.test simulam falha do provedor (HTTP 500); e-mails para
 * @recusado.test, a recusa do Resend em modo de teste (HTTP 403, com o
 * destino ecoado na resposta - a API não pode gravá-lo).
 */
$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$caminho = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');

/*
 * Canais próprios das escolas (migração 015): a API usa
 * PROVEDORES_FALSOS_URL=http://.../provedor e chama /provedor/brevo/...,
 * /provedor/resend/... e /provedor/meta/... como chamaria a Brevo, o Resend
 * e a Meta. Chaves terminadas em "invalida" são recusadas; tokens da Meta
 * terminados em "vencido" dão o erro 190.
 */
if (str_starts_with($caminho, '/provedor/')) {
    $logEmail = getenv('EMAIL_LOG') ?: sys_get_temp_dir() . '/email.log';
    $logWhatsapp = getenv('WHATSAPP_LOG') ?: sys_get_temp_dir() . '/whatsapp.log';
    $registrar = fn(string $log, array $linha) => file_put_contents($log, json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    $bearer = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if ($caminho === '/provedor/brevo/v3/senders') {
        if (str_ends_with((string) ($_SERVER['HTTP_API_KEY'] ?? ''), 'invalida')) {
            http_response_code(401);
            echo json_encode(['code' => 'unauthorized', 'message' => 'Key not found']);
            return;
        }
        echo json_encode(['senders' => [
            ['id' => 1, 'name' => 'Escola 1', 'email' => 'secretaria@escola1.test', 'active' => true],
            ['id' => 2, 'name' => 'Pendente', 'email' => 'pendente@escola1.test', 'active' => false],
            ['id' => 3, 'name' => 'Escola 2', 'email' => 'secretaria@escola2.test', 'active' => true],
        ]]);
        return;
    }
    if ($caminho === '/provedor/resend/domains') {
        echo json_encode(['data' => [['name' => 'escola2.test', 'status' => 'verified']]]);
        return;
    }
    if ($caminho === '/provedor/brevo/v3/smtp/email' || $caminho === '/provedor/resend/emails') {
        $brevo = str_contains($caminho, 'brevo');
        $destinos = $brevo ? array_map(fn($d) => (string) ($d['email'] ?? ''), (array) ($dados['to'] ?? [])) : array_map('strval', (array) ($dados['to'] ?? []));
        $registrar($logEmail, [
            'to' => $destinos,
            'provedor' => $brevo ? 'brevo' : 'resend',
            'from' => $brevo ? ($dados['sender']['email'] ?? null) : ($dados['from'] ?? null),
            'nome_remetente' => $brevo ? ($dados['sender']['name'] ?? null) : null,
            'auth' => $brevo ? ($_SERVER['HTTP_API_KEY'] ?? null) : $bearer,
            'assunto' => $dados['subject'] ?? null,
            'texto' => $brevo ? ($dados['textContent'] ?? null) : ($dados['text'] ?? null),
            'reply_to' => $brevo ? ($dados['replyTo']['email'] ?? null) : ($dados['reply_to'] ?? null),
        ]);
        if (array_filter($destinos, fn($destino) => str_ends_with($destino, '@falha.test'))) {
            http_response_code(500);
            echo json_encode(['message' => 'falha simulada']);
            return;
        }
        http_response_code($brevo ? 201 : 200);
        echo json_encode($brevo ? ['messageId' => '<teste@brevo>'] : ['id' => 'email-teste']);
        return;
    }
    if (preg_match('#^/provedor/meta/v[\d.]+/(\d+)(/messages)?$#', $caminho, $partes)) {
        if (str_ends_with($bearer, 'vencido')) {
            http_response_code(401);
            echo json_encode(['error' => ['message' => 'Error validating access token: Session has expired.', 'type' => 'OAuthException', 'code' => 190]]);
            return;
        }
        $numeros = ['100000000000001' => '+55 11 3333-0001', '100000000000002' => '+55 11 3333-0002'];
        if (!isset($numeros[$partes[1]])) {
            http_response_code(400);
            echo json_encode(['error' => ['message' => 'Unsupported get request.', 'type' => 'GraphMethodException', 'code' => 100]]);
            return;
        }
        if (($partes[2] ?? '') === '') {
            echo json_encode(['display_phone_number' => $numeros[$partes[1]], 'verified_name' => 'Escola Teste', 'id' => $partes[1]]);
            return;
        }
        $destino = (string) ($dados['to'] ?? '');
        $registrar($logWhatsapp, [
            'to' => $destino,
            'auth' => 'Bearer ' . $bearer,
            'id_numero' => $partes[1],
            'modelo' => $dados['template']['name'] ?? null,
            'texto' => $dados['template']['components'][0]['parameters'][0]['text'] ?? ($dados['text']['body'] ?? null),
        ]);
        if (str_ends_with($destino, '0000')) {
            http_response_code(500);
            echo json_encode(['error' => ['message' => 'falha simulada']]);
            return;
        }
        echo json_encode(['messages' => [['id' => 'wamid.escola-' . bin2hex(random_bytes(4))]]]);
        return;
    }
    http_response_code(404);
    echo json_encode(['message' => 'caminho falso desconhecido']);
    return;
}

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
        'reply_to' => $dados['reply_to'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if (array_filter($destinos, fn($destino) => str_ends_with($destino, '@falha.test'))) {
        http_response_code(500);
        echo json_encode(['message' => 'falha simulada']);
        return;
    }
    $recusados = array_values(array_filter($destinos, fn($destino) => str_ends_with($destino, '@recusado.test')));
    if ($recusados !== []) {
        http_response_code(403);
        echo json_encode(['statusCode' => 403, 'name' => 'validation_error',
            'message' => "You can only send testing emails to your own email address ({$recusados[0]})."]);
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
