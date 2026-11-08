<?php
require_once __DIR__ . '/notificacao.php';
require_once __DIR__ . '/provedores.php';

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
 *   EMAIL_*, SMTP_REMETENTE            e-mail (ver helpers/provedores.php)
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

/**
 * O texto de uma mensagem gravada. Sem provedor, o WhatsApp fica gravado
 * como o link wa.me (com o texto dentro) - e, antes da migração 011, também
 * quando o provedor falhava: "Tentar de novo" manda o texto, não o link.
 */
function textoDaMensagemGravada(string $mensagem): string {
    if (!str_starts_with($mensagem, 'https://wa.me/')) {
        return $mensagem;
    }
    $consulta = parse_url($mensagem, PHP_URL_QUERY);
    if (!is_string($consulta)) {
        return $mensagem;
    }
    parse_str($consulta, $parametros);
    $texto = $parametros['text'] ?? null;
    return is_string($texto) && trim($texto) !== '' ? $texto : $mensagem;
}

function enviarEmailResponsavel(string $destino, string $assunto, string $mensagem, ?string $chave = null): string {
    return enviarEmail($destino, $assunto, $mensagem, null, $chave); // sem provedor: fica na fila
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
