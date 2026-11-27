<?php
/**
 * Saída de mensagens pelos provedores configurados no ambiente: e-mail
 * aqui, WhatsApp em helpers/contatoResponsavel.php. Nenhum token fica no
 * código.
 *
 *   EMAIL_API_URL, EMAIL_API_TOKEN,   e-mail por HTTP (o Render não tem
 *   EMAIL_REMETENTE                   servidor de e-mail: mail() não entrega
 *                                     lá). O provedor sai do endereço:
 *                                     api.brevo.com usa o formato da Brevo
 *                                     (entrega a qualquer pessoa só com um
 *                                     remetente verificado, sem domínio
 *                                     próprio); qualquer outro, o do Resend.
 *                                     O endereço base, sem https:// ou o do
 *                                     painel (resend.com/emails) viram o
 *                                     endereço de envio da API.
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

const ENDERECO_ENVIO_RESEND = 'https://api.resend.com/emails';
const ENDERECO_ENVIO_BREVO = 'https://api.brevo.com/v3/smtp/email';

/** O host de um endereço, com ou sem "https://", em minúsculas; null se não der para ler. Função pura. */
function hostDoEndereco(string $url): ?string {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? mb_strtolower($host) : null;
}

/** O host é o domínio ou um subdomínio dele ("api.resend.com" é de "resend.com"). */
function hostDoDominio(?string $host, string $dominio): bool {
    return $host !== null && ($host === $dominio || str_ends_with($host, '.' . $dominio));
}

/** "brevo" (api.brevo.com; sendinblue é o nome antigo) ou "resend" (o formato padrão). Função pura. */
function provedorDeEmail(string $url): string {
    $host = hostDoEndereco($url);
    return hostDoDominio($host, 'brevo.com') || hostDoDominio($host, 'sendinblue.com') ? 'brevo' : 'resend';
}

/**
 * O endereço de envio da API, a partir do que foi posto em EMAIL_API_URL.
 * Do Resend e da Brevo, qualquer endereço deles vira o de envio: o base
 * (o POST nele dá 404), o sem "https://" e o do PAINEL - https://resend.com/emails
 * é a página que lista os e-mails enviados, ela redireciona para o login e
 * o envio falhava com HTTP 307. Outro endereço (outro provedor no formato
 * do Resend, ou o provedor falso dos testes) fica como está. Função pura.
 */
function enderecoDeEnvioDeEmail(string $url): string {
    $host = hostDoEndereco($url);
    if (hostDoDominio($host, 'resend.com')) {
        return ENDERECO_ENVIO_RESEND;
    }
    if (provedorDeEmail($url) === 'brevo') {
        return ENDERECO_ENVIO_BREVO;
    }
    return trim($url);
}

/**
 * "BioFrequência <nao-responda@escola.br>" => ['nome' => 'BioFrequência',
 * 'email' => 'nao-responda@escola.br']; só o e-mail também vale. null
 * quando não há e-mail legível. Função pura.
 */
function partesDoRemetente(string $remetente): ?array {
    $remetente = trim($remetente);
    if (preg_match('/^(.*?)\s*<\s*([^<>@\s]+@[^<>\s]+)\s*>$/u', $remetente, $m)) {
        $nome = trim($m[1], " \t\"'");
        return ['nome' => $nome === '' ? null : $nome, 'email' => $m[2]];
    }
    if (preg_match('/^[^<>@\s]+@[^<>\s]+$/u', $remetente)) {
        return ['nome' => null, 'email' => $remetente];
    }
    return null;
}

/**
 * O pedido de envio no formato do provedor: ['corpo' => ..., 'cabecalhos' => [...]],
 * já com a autorização (Resend: "Authorization: Bearer"; Brevo: "api-key").
 * Função pura.
 */
function pedidoDeEmail(
    string $provedor, string $token, string $remetente, string $destino, string $assunto, string $texto,
    ?string $html = null, ?string $responderPara = null, ?string $chaveDeIdempotencia = null
): array {
    $responder = $responderPara !== null && trim($responderPara) !== '' ? trim($responderPara) : null;
    if ($provedor === 'brevo') {
        $partes = partesDoRemetente($remetente) ?? ['nome' => null, 'email' => trim($remetente)];
        $corpo = [
            'sender' => array_filter(['name' => $partes['nome'], 'email' => $partes['email']], fn($v) => $v !== null),
            'to' => [['email' => $destino]],
            'subject' => $assunto,
            'textContent' => $texto,
        ];
        if ($html !== null) {
            $corpo['htmlContent'] = $html;
        }
        if ($responder !== null) {
            $corpo['replyTo'] = ['email' => $responder];
        }
        return ['corpo' => $corpo, 'cabecalhos' => ['api-key: ' . $token, 'Accept: application/json']];
    }
    $corpo = [
        'from'    => $remetente,
        'to'      => [$destino],
        'subject' => $assunto,
        'text'    => $texto,
    ];
    if ($html !== null) {
        $corpo['html'] = $html;
    }
    if ($responder !== null) {
        $corpo['reply_to'] = $responder;
    }
    $cabecalhos = ['Authorization: Bearer ' . $token];
    // O Resend não manda de novo um pedido com a mesma chave (24 h):
    // a conexão que cai depois do envio e a nova tentativa não duplicam.
    if ($chaveDeIdempotencia !== null) {
        $cabecalhos[] = 'Idempotency-Key: ' . $chaveDeIdempotencia;
    }
    return ['corpo' => $corpo, 'cabecalhos' => $cabecalhos];
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
    if ($status >= 300 && $status < 400) {
        // A resposta de um redirecionamento é uma página, não o motivo.
        return "$canal: o endereço configurado redirecionou (HTTP $status) - ele não é o da API de envio. "
            . 'Confira o endereço na Render (e-mail: https://api.resend.com/emails ou https://api.brevo.com/v3/smtp/email).';
    }
    $base = $status === 0 ? "$canal: o provedor não respondeu" : "$canal: o provedor recusou (HTTP $status)";
    $explicacao = explicacaoDaRecusa($status, $detalhe);
    if ($explicacao !== null) {
        $base .= ": $explicacao";
    }
    $texto = $detalhe !== '' ? "$base - $detalhe" : $base;
    return mb_substr($texto, 0, 300);
}

/**
 * Em português, o que as recusas mais comuns dos provedores querem dizer
 * (a resposta deles vem em inglês). null quando não é uma conhecida.
 * Função pura.
 */
function explicacaoDaRecusa(int $status, string $detalhe): ?string {
    $d = mb_strtolower($detalhe);
    return match (true) {
        str_contains($d, 'domain is not verified') || (str_contains($d, 'domain') && str_contains($d, 'not verified'))
            => 'o domínio do remetente não está verificado no provedor (verifique o domínio ou use a Brevo com um remetente verificado)',
        str_contains($d, 'testing emails to your own email')
            => 'o Resend está em modo de teste e só entrega no e-mail do dono da conta',
        str_contains($d, 'sender') && (str_contains($d, 'not valid') || str_contains($d, 'invalid') || str_contains($d, 'inactive'))
            => 'o remetente não está verificado na conta do provedor',
        str_contains($d, 'key not found') || str_contains($d, 'api key is invalid') || ($status === 401 && !str_contains($d, '(190)'))
            => 'a chave de API foi recusada (confira se está completa e ativa)',
        str_contains($d, '(190)')
            => 'o token do WhatsApp é inválido ou venceu',
        str_contains($d, '(132001)') || str_contains($d, 'template name does not exist')
            => 'o modelo de mensagem não existe ou não foi aprovado na Meta',
        str_contains($d, '(131030)')
            => 'o número de destino não está na lista de teste da Meta (conta ainda em modo de desenvolvimento)',
        str_contains($d, '(131026)')
            => 'o número de destino não tem WhatsApp ou não pode receber a mensagem',
        default => null,
    };
}

/**
 * POST JSON no provedor. Com $token, vai "Authorization: Bearer"; sem ele,
 * a autorização já vem em $cabecalhos (a Brevo usa "api-key"). Para o log
 * vai só o código HTTP (a resposta pode ecoar o destino e o texto da
 * mensagem).
 *
 * @return array{0: string, 1: ?string} [ENVIO_*, motivo da falha]
 */
function postarNoProvedor(string $url, ?string $token, array $corpo, string $canal, array $cabecalhos = []): array {
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
            ...($token !== null ? ['Authorization: Bearer ' . $token] : []),
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
        $pedido = pedidoDeEmail(provedorDeEmail($url), trim($token), trim($remetente), $destino, $assunto, $texto, $html, $responderPara, $chaveDeIdempotencia);
        return postarNoProvedor(enderecoDeEnvioDeEmail($url), null, $pedido['corpo'], 'e-mail', $pedido['cabecalhos']);
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
