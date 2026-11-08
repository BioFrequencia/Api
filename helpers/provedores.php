<?php
/**
 * Saída de mensagens pelos provedores configurados no ambiente: e-mail
 * aqui, WhatsApp em helpers/contatoResponsavel.php. Nenhum token fica no
 * código.
 *
 *   EMAIL_API_URL, EMAIL_API_TOKEN,   e-mail por HTTP, no formato da API do
 *   EMAIL_REMETENTE                   Resend (o Render não tem servidor de
 *                                     e-mail: mail() não entrega lá)
 *   SMTP_REMETENTE                    alternativa: mail() do PHP, para um
 *                                     servidor com sendmail
 *
 * Resultado de cada envio: ENVIO_OK (o provedor aceitou), ENVIO_SEM_PROVEDOR
 * (nada configurado - quem chamou decide o que fazer) ou ENVIO_FALHOU
 * (havia provedor e ele recusou ou não respondeu).
 */

const ENVIO_OK = 'enviado';
const ENVIO_SEM_PROVEDOR = 'sem_provedor';
const ENVIO_FALHOU = 'falhou';

/** POST JSON autenticado no provedor; só o código HTTP vai para o log. */
function postarNoProvedor(string $url, string $token, array $corpo, string $canal, array $cabecalhos = []): string {
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
        CURLOPT_POSTFIELDS => json_encode($corpo),
    ]);
    $resposta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resposta === false || $status < 200 || $status >= 300) {
        // A resposta do provedor pode ecoar o destino e o texto da
        // mensagem: não vai para o log.
        error_log("[BioFrequenciaApi] Falha ao enviar $canal: HTTP $status");
        return ENVIO_FALHOU;
    }
    return ENVIO_OK;
}

/** Há algum jeito de enviar e-mail configurado? */
function emailConfigurado(): bool {
    $configurada = fn(string $nome) => trim((string) getenv($nome)) !== '';
    return ($configurada('EMAIL_API_URL') && $configurada('EMAIL_API_TOKEN') && $configurada('EMAIL_REMETENTE'))
        || $configurada('SMTP_REMETENTE');
}

/**
 * Envia um e-mail em texto (e, se vier, também em HTML - o provedor HTTP
 * manda os dois e o programa de e-mail escolhe).
 */
function enviarEmail(string $destino, string $assunto, string $texto, ?string $html = null, ?string $chaveDeIdempotencia = null): string {
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
        // O Resend não manda de novo um pedido com a mesma chave (24 h):
        // a conexão que cai depois do envio e a nova tentativa não duplicam.
        $cabecalhos = $chaveDeIdempotencia !== null ? ['Idempotency-Key: ' . $chaveDeIdempotencia] : [];
        return postarNoProvedor($url, $token, $corpo, 'e-mail', $cabecalhos);
    }

    $remetenteSmtp = getenv('SMTP_REMETENTE');
    if ($remetenteSmtp === false || trim($remetenteSmtp) === '') {
        return ENVIO_SEM_PROVEDOR;
    }
    $cabecalhos = [
        'From: ' . $remetenteSmtp,
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if (!@mail($destino, $assunto, $texto, implode("\r\n", $cabecalhos))) {
        error_log('[BioFrequenciaApi] Falha ao enviar e-mail.');
        return ENVIO_FALHOU;
    }
    return ENVIO_OK;
}
