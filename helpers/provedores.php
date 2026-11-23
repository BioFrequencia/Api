<?php
/**
 * Saída de mensagens pelos provedores configurados no ambiente: e-mail
 * aqui, WhatsApp em helpers/contatoResponsavel.php. Nenhum token fica no
 * código.
 *
 *   EMAIL_API_URL, EMAIL_API_TOKEN,   e-mail por HTTP, no formato da API do
 *   EMAIL_REMETENTE                   Resend (o Render não tem servidor de
 *                                     e-mail: mail() não entrega lá).
 *                                     EMAIL_API_URL é o endereço de envio
 *                                     (https://api.resend.com/emails); só o
 *                                     endereço base do Resend também vale.
 *   SMTP_REMETENTE                    alternativa: mail() do PHP, para um
 *                                     servidor com sendmail
 *
 * Resultado de cada envio: ENVIO_OK (o provedor aceitou), ENVIO_SEM_PROVEDOR
 * (nada configurado - quem chamou decide o que fazer) ou ENVIO_FALHOU
 * (havia provedor e ele recusou ou não respondeu). As funções "...Detalhado"
 * devolvem também o motivo da falha (motivoDaFalhaDoProvedor), que fica
 * gravado com a notificação: antes ele ia só para o log do servidor, e a
 * tela dizia "erro" sem dizer por quê.
 */

const ENVIO_OK = 'enviado';
const ENVIO_SEM_PROVEDOR = 'sem_provedor';
const ENVIO_FALHOU = 'falhou';

/** Endereço do Resend sem o caminho do envio ganha /emails (o POST no endereço base dá 404). */
function enderecoDeEnvioDeEmail(string $url): string {
    $url = trim($url);
    $partes = parse_url($url);
    if (!is_array($partes) || ($partes['host'] ?? '') !== 'api.resend.com') {
        return $url;
    }
    $caminho = $partes['path'] ?? '';
    return ($caminho === '' || $caminho === '/') ? rtrim($url, '/') . '/emails' : $url;
}

/**
 * Motivo de uma recusa do provedor, para a tela e o banco: o código HTTP e
 * a explicação que o provedor deu (Resend: message; Meta: error.message e
 * o código), SEM e-mails nem números de telefone - a resposta pode ecoar o
 * destino. Função pura.
 */
function motivoDaFalhaDoProvedor(int $status, $resposta, string $canal): string {
    $corpo = is_string($resposta) ? json_decode($resposta, true) : null;
    $detalhe = '';
    if (is_array($corpo)) {
        $erro = $corpo['error'] ?? null;
        if (is_array($erro)) {
            $detalhe = trim((string) ($erro['message'] ?? ''));
            if (isset($erro['code']) && !str_contains($detalhe, (string) $erro['code'])) {
                $detalhe = trim("({$erro['code']}) $detalhe");
            }
        } else {
            $detalhe = trim((string) ($corpo['message'] ?? $corpo['name'] ?? ''));
        }
    }
    $detalhe = preg_replace('/[^\s@<>"\']+@[^\s@<>"\']+/u', '[e-mail]', $detalhe) ?? '';
    $detalhe = preg_replace('/\+?\d[\d\s().-]{7,}\d/u', '[telefone]', $detalhe) ?? '';
    $detalhe = trim((string) preg_replace('/\s+/u', ' ', $detalhe));
    $base = $status === 0 ? "$canal: o provedor não respondeu" : "$canal: o provedor recusou (HTTP $status)";
    $texto = $detalhe !== '' ? "$base - $detalhe" : $base;
    return mb_substr($texto, 0, 300);
}

/**
 * POST JSON autenticado no provedor. Para o log vai só o código HTTP (a
 * resposta pode ecoar o destino e o texto da mensagem).
 *
 * @return array{0: string, 1: ?string} [ENVIO_*, motivo da falha]
 */
function postarNoProvedor(string $url, string $token, array $corpo, string $canal, array $cabecalhos = []): array {
    $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return [ENVIO_FALHOU, "$canal: a mensagem não pôde ser montada para o envio."];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            ...$cabecalhos,
        ],
        CURLOPT_POSTFIELDS => $json,
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resposta === false || $status < 200 || $status >= 300) {
        error_log("[BioFrequenciaApi] Falha ao enviar $canal: HTTP $status");
        return [ENVIO_FALHOU, motivoDaFalhaDoProvedor($resposta === false ? 0 : $status, $resposta, $canal)];
    }
    return [ENVIO_OK, null];
}

/** Há algum jeito de enviar e-mail configurado? */
function emailConfigurado(): bool {
    $configurada = fn(string $nome) => trim((string) getenv($nome)) !== '';
    return ($configurada('EMAIL_API_URL') && $configurada('EMAIL_API_TOKEN') && $configurada('EMAIL_REMETENTE'))
        || $configurada('SMTP_REMETENTE');
}

/**
 * Envia um e-mail em texto (e, se vier, também em HTML - o provedor HTTP
 * manda os dois e o programa de e-mail escolhe). $responderPara: o e-mail
 * de contato da escola - a resposta do responsável vai para a escola, não
 * para o remetente do sistema (que não lê respostas).
 *
 * @return array{0: string, 1: ?string} [ENVIO_*, motivo da falha]
 */
function enviarEmailDetalhado(
    string $destino, string $assunto, string $texto, ?string $html = null,
    ?string $chaveDeIdempotencia = null, ?string $responderPara = null
): array {
    $url = getenv('EMAIL_API_URL');
    $token = getenv('EMAIL_API_TOKEN');
    $remetente = getenv('EMAIL_REMETENTE');
    if ($url && $token && $remetente) {
        $corpo = [
            'from'    => $remetente,
            'to'      => [$destino],
            'subject' => $assunto,
            'text'    => $texto,
        ];
        if ($html !== null) {
            $corpo['html'] = $html;
        }
        if ($responderPara !== null && $responderPara !== '') {
            $corpo['reply_to'] = $responderPara;
        }
        // O Resend não manda de novo um pedido com a mesma chave (24 h):
        // a conexão que cai depois do envio e a nova tentativa não duplicam.
        $cabecalhos = $chaveDeIdempotencia !== null ? ['Idempotency-Key: ' . $chaveDeIdempotencia] : [];
        return postarNoProvedor(enderecoDeEnvioDeEmail($url), $token, $corpo, 'e-mail', $cabecalhos);
    }

    $remetenteSmtp = getenv('SMTP_REMETENTE');
    if ($remetenteSmtp === false || trim($remetenteSmtp) === '') {
        return [ENVIO_SEM_PROVEDOR, null];
    }
    $cabecalhos = [
        'From: ' . $remetenteSmtp,
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if ($responderPara !== null && $responderPara !== '') {
        $cabecalhos[] = 'Reply-To: ' . $responderPara;
    }
    if (!@mail($destino, $assunto, $texto, implode("\r\n", $cabecalhos))) {
        error_log('[BioFrequenciaApi] Falha ao enviar e-mail.');
        return [ENVIO_FALHOU, 'e-mail: o servidor de e-mail (mail) recusou o envio.'];
    }
    return [ENVIO_OK, null];
}

/** Como enviarEmailDetalhado, só com o resultado (e-mails da conta: confirmação, senha nova). */
function enviarEmail(string $destino, string $assunto, string $texto, ?string $html = null, ?string $chaveDeIdempotencia = null): string {
    return enviarEmailDetalhado($destino, $assunto, $texto, $html, $chaveDeIdempotencia)[0];
}
