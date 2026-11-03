<?php
require_once __DIR__ . '/notificacao.php';

/**
 * Envio de mensagens aos responsáveis: WhatsApp e e-mail. Quem decide
 * QUANDO avisar é helpers/avisos.php; aqui fica só COMO a mensagem sai.
 *
 * O envio só sai sozinho com provedor configurado por variável de
 * ambiente (nenhum token fica no código):
 *
 *   WHATSAPP_API_URL, WHATSAPP_TOKEN   API do WhatsApp (formato da Meta
 *                                      Cloud API)
 *   WHATSAPP_TEMPLATE                  opcional: modelo aprovado pela Meta,
 *                                      com uma variável ({{1}}) que recebe
 *                                      a mensagem - fora da janela de 24 h
 *                                      a Meta só entrega modelos
 *   WHATSAPP_TEMPLATE_IDIOMA           idioma do modelo (padrão pt_BR)
 *   EMAIL_API_URL, EMAIL_API_TOKEN,    e-mail por HTTP, no formato da API
 *   EMAIL_REMETENTE                    do Resend (o Render não tem servidor
 *                                      de e-mail: mail() não entrega lá)
 *   SMTP_REMETENTE                     alternativa: mail() do PHP, para um
 *                                      servidor com sendmail
 *
 * Sem provedor - o estado de um TCC sem conta paga - a mensagem fica na
 * fila de Notificações com um link wa.me pronto, que a coordenação clica e
 * manda pelo WhatsApp dela. Funciona sem credencial nenhuma e não finge
 * um envio que não aconteceu.
 *
 * Resultado gravado por mensagem: 'Enviado' (provedor aceitou),
 * 'Pendente' (sem provedor: fila manual) ou 'Erro' (havia provedor e o
 * envio falhou - fica registrado para a coordenação ver e tratar).
 */

const ENVIO_OK = 'enviado';
const ENVIO_SEM_PROVEDOR = 'sem_provedor';
const ENVIO_FALHOU = 'falhou';

function telefoneParaWhatsapp(?string $telefone): ?string {
    if ($telefone === null) {
        return null;
    }
    $digitos = preg_replace('/\D/', '', $telefone);
    if (strlen($digitos) < 10) {
        return null;
    }
    // Acrescenta o código do Brasil quando o número veio só com DDD.
    return strlen($digitos) <= 11 ? '55' . $digitos : $digitos;
}

function linkWhatsapp(string $telefoneInternacional, string $mensagem): string {
    return 'https://wa.me/' . $telefoneInternacional . '?text=' . rawurlencode($mensagem);
}

/** POST JSON autenticado no provedor; só o código HTTP vai para o log. */
function postarNoProvedor(string $url, string $token, array $corpo, string $canal): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
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

function enviarEmailResponsavel(string $destino, string $assunto, string $mensagem): string {
    $url = getenv('EMAIL_API_URL');
    $token = getenv('EMAIL_API_TOKEN');
    $remetente = getenv('EMAIL_REMETENTE');
    if ($url && $token && $remetente) {
        return postarNoProvedor($url, $token, [
            'from'    => $remetente,
            'to'      => [$destino],
            'subject' => $assunto,
            'text'    => $mensagem,
        ], 'e-mail');
    }

    $remetenteSmtp = getenv('SMTP_REMETENTE');
    if ($remetenteSmtp === false || trim($remetenteSmtp) === '') {
        return ENVIO_SEM_PROVEDOR; // sem provedor: fica na fila
    }
    $cabecalhos = [
        'From: ' . $remetenteSmtp,
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if (!@mail($destino, $assunto, $mensagem, implode("\r\n", $cabecalhos))) {
        error_log('[BioFrequenciaApi] Falha ao enviar e-mail ao responsável.');
        return ENVIO_FALHOU;
    }
    return ENVIO_OK;
}

/**
 * Corpo da mensagem no formato da Meta Cloud API: texto livre ou, com
 * WHATSAPP_TEMPLATE, o modelo aprovado com a mensagem na variável {{1}}.
 */
function corpoWhatsapp(string $telefone, string $mensagem): array {
    $modelo = getenv('WHATSAPP_TEMPLATE');
    if ($modelo) {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $telefone,
            'type' => 'template',
            'template' => [
                'name' => $modelo,
                'language' => ['code' => getenv('WHATSAPP_TEMPLATE_IDIOMA') ?: 'pt_BR'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $mensagem]],
                ]],
            ],
        ];
    }
    return [
        'messaging_product' => 'whatsapp',
        'to' => $telefone,
        'type' => 'text',
        'text' => ['body' => $mensagem],
    ];
}

function enviarWhatsappResponsavel(string $telefone, string $mensagem): string {
    $url = getenv('WHATSAPP_API_URL');
    $token = getenv('WHATSAPP_TOKEN');
    if (!$url || !$token) {
        return ENVIO_SEM_PROVEDOR; // sem provedor: fica na fila com o link wa.me
    }
    return postarNoProvedor($url, $token, corpoWhatsapp($telefone, $mensagem), 'WhatsApp');
}

function statusPorResultadoEnvio(string $resultado): string {
    return match ($resultado) {
        ENVIO_OK     => NOTIFICACAO_ENVIADA,
        ENVIO_FALHOU => NOTIFICACAO_ERRO,
        default      => NOTIFICACAO_PENDENTE,
    };
}
