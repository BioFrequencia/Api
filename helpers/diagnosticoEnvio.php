<?php
require_once __DIR__ . '/provedores.php';

/**
 * Diagnóstico do envio de mensagens (Administração > Visão geral): o que
 * está configurado, o que costuma impedir a entrega e o último erro de
 * cada canal - sem mostrar nenhum segredo (tokens, endereços completos).
 *
 * Avisos que ele sabe dar:
 *  - e-mail sem provedor: nada sai sozinho, as mensagens ficam na fila;
 *  - EMAIL_API_URL que não é o endereço de envio (o base, o do painel
 *    resend.com/emails - que dava HTTP 307 - ou sem https://): corrigido
 *    sozinho (helpers/provedores.php), mas vale acertar na Render;
 *  - remetente @resend.dev: o modo de teste do Resend só entrega no e-mail
 *    do dono da conta do Resend - para os responsáveis, falha (a saída sem
 *    domínio próprio é a Brevo, com um remetente verificado);
 *  - Brevo: o remetente precisa estar verificado na conta da Brevo;
 *  - domínio do remetente não verificado no Resend (consulta opcional, só
 *    leitura, GET /domains - nenhuma mensagem é enviada);
 *  - WhatsApp sem modelo aprovado (WHATSAPP_TEMPLATE): a Meta só entrega
 *    texto livre a quem falou com o número nas últimas 24 h;
 *  - WHATSAPP_API_URL da Meta fora do formato .../v<versão>/<ID do número>/messages
 *    (erro comum: o endereço base, ou o telefone no lugar do ID).
 */

/** Domínio do remetente ("Nome <x@dominio>" -> "dominio"); null se não der para ler. Função pura. */
function dominioDoRemetente(?string $remetente): ?string {
    if ($remetente === null || trim($remetente) === '') {
        return null;
    }
    if (preg_match('/<([^<>@\s]+)@([^<>\s]+)>/', $remetente, $m) || preg_match('/^\s*([^<>@\s]+)@([^<>\s]+)\s*$/', $remetente, $m)) {
        return mb_strtolower($m[2]);
    }
    return null;
}

/**
 * Problema no WHATSAPP_API_URL da Meta, ou null. Só julga o endereço da
 * Meta (graph.facebook.com): um provedor compatível com a Cloud API tem o
 * próprio formato. Função pura.
 */
function problemaNoEnderecoDoWhatsapp(string $url): ?string {
    $partes = parse_url(trim($url));
    if (!is_array($partes) || ($partes['host'] ?? '') !== 'graph.facebook.com') {
        return null;
    }
    $caminho = rtrim((string) ($partes['path'] ?? ''), '/');
    if (($partes['scheme'] ?? '') === 'https' && preg_match('#^/v\d+(\.\d+)?/\d+/messages$#', $caminho)) {
        return null;
    }
    return 'WHATSAPP_API_URL não está no formato da Meta: https://graph.facebook.com/v23.0/<ID do número de telefone>/messages. '
        . 'O ID fica no painel da Meta, em WhatsApp > Configuração da API (não é o número de telefone).';
}

/**
 * Avisos da configuração (sem consultar nada). Função pura: recebe os
 * valores do ambiente.
 *
 * @return list<array{canal: string, nivel: string, texto: string}>
 */
function avisosDaConfiguracaoDeEnvio(array $ambiente): array {
    $tem = fn(string $nome) => trim((string) ($ambiente[$nome] ?? '')) !== '';
    $avisos = [];

    $emailHttp = $tem('EMAIL_API_URL') && $tem('EMAIL_API_TOKEN') && $tem('EMAIL_REMETENTE');
    if (!$emailHttp && !$tem('SMTP_REMETENTE')) {
        $faltando = array_values(array_filter(['EMAIL_API_URL', 'EMAIL_API_TOKEN', 'EMAIL_REMETENTE'], fn($n) => !$tem($n)));
        $avisos[] = ['canal' => 'email', 'nivel' => 'erro',
            'texto' => 'E-mail sem provedor: faltam ' . implode(', ', $faltando) . ' na Render. Os e-mails ficam na fila e nada sai sozinho.'];
    }
    if ($emailHttp) {
        $url = (string) $ambiente['EMAIL_API_URL'];
        $provedor = provedorDeEmail($url);
        $envio = enderecoDeEnvioDeEmail($url);
        if ($envio !== trim($url)) {
            $painel = hostDoEndereco($url) === 'resend.com' || hostDoEndereco($url) === 'www.resend.com';
            $avisos[] = ['canal' => 'email', 'nivel' => 'aviso',
                'texto' => ($painel
                    ? 'EMAIL_API_URL aponta para o painel do Resend (resend.com), não para a API: ele redireciona para o login e o envio falhava com HTTP 307. '
                    : 'EMAIL_API_URL não é o endereço de envio da API. ')
                    . "O sistema já usa $envio, mas acerte também na Render."];
        }
        $dominio = dominioDoRemetente((string) $ambiente['EMAIL_REMETENTE']);
        if ($dominio === null) {
            $avisos[] = ['canal' => 'email', 'nivel' => 'erro',
                'texto' => 'EMAIL_REMETENTE não tem um endereço de e-mail legível (use "Nome <nao-responda@seu-dominio>").'];
        } elseif ($provedor === 'resend' && $dominio === 'resend.dev') {
            $avisos[] = ['canal' => 'email', 'nivel' => 'erro',
                'texto' => 'O remetente é do domínio de teste do Resend (resend.dev): ele só entrega no e-mail do dono da conta do Resend. Para os responsáveis, verifique um domínio próprio no Resend e use-o em EMAIL_REMETENTE, ou use a Brevo, que só pede um remetente verificado (docs/ENVIO-AUTOMATICO.md).'];
        } elseif ($provedor === 'brevo') {
            $avisos[] = ['canal' => 'email', 'nivel' => 'info',
                'texto' => "E-mail pela Brevo: o remetente ($dominio) precisa estar verificado na Brevo, em Senders, Domains & Dedicated IPs > Senders. Sem isso, a Brevo recusa o envio."];
        }
    }

    $whatsapp = $tem('WHATSAPP_API_URL') && $tem('WHATSAPP_TOKEN');
    if (!$whatsapp) {
        $avisos[] = ['canal' => 'whatsapp', 'nivel' => 'info',
            'texto' => 'WhatsApp sem envio automático: as mensagens ficam em Notificações com o link pronto para a coordenação enviar pelo WhatsApp da escola.'];
    } else {
        $problema = problemaNoEnderecoDoWhatsapp((string) $ambiente['WHATSAPP_API_URL']);
        if ($problema !== null) {
            $avisos[] = ['canal' => 'whatsapp', 'nivel' => 'erro', 'texto' => $problema];
        }
        if (!$tem('WHATSAPP_TEMPLATE')) {
            $avisos[] = ['canal' => 'whatsapp', 'nivel' => 'aviso',
                'texto' => 'WhatsApp sem modelo aprovado (WHATSAPP_TEMPLATE): a Meta aceita o pedido mas só entrega texto livre a quem falou com o número da escola nas últimas 24 horas.'];
        }
    }
    return $avisos;
}

/**
 * Consulta (só leitura) se o domínio do remetente está verificado no
 * Resend. Nenhuma mensagem é enviada.
 *
 * @return array{situacao: string, texto: string}
 *   situacao: verificado | nao_verificado | nao_encontrado | sem_permissao | indisponivel | nao_se_aplica
 */
function verificarDominioNoResend(): array {
    $url = enderecoDeEnvioDeEmail((string) getenv('EMAIL_API_URL'));
    $token = trim((string) getenv('EMAIL_API_TOKEN'));
    $dominio = dominioDoRemetente((string) getenv('EMAIL_REMETENTE'));
    if (hostDoEndereco($url) !== 'api.resend.com' || $token === '' || $dominio === null) {
        return ['situacao' => 'nao_se_aplica', 'texto' => 'A consulta do domínio só funciona com o Resend configurado.'];
    }
    $ch = curl_init('https://api.resend.com/domains');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return situacaoDoDominio($status, $resposta === false ? null : (string) $resposta, $dominio);
}

/** Interpreta a resposta de GET /domains do Resend. Função pura. */
function situacaoDoDominio(int $status, ?string $resposta, string $dominio): array {
    if ($resposta === null || $status === 0) {
        return ['situacao' => 'indisponivel', 'texto' => 'O Resend não respondeu agora.'];
    }
    if ($status === 401 || $status === 403) {
        return ['situacao' => 'sem_permissao',
            'texto' => 'A chave do Resend é só de envio e não permite consultar os domínios. Confira no painel do Resend se o domínio do remetente está verificado.'];
    }
    if ($status < 200 || $status >= 300) {
        return ['situacao' => 'indisponivel', 'texto' => "O Resend recusou a consulta (HTTP $status)."];
    }
    $lista = json_decode($resposta, true)['data'] ?? [];
    foreach (is_array($lista) ? $lista : [] as $item) {
        if (mb_strtolower((string) ($item['name'] ?? '')) === $dominio) {
            return ($item['status'] ?? '') === 'verified'
                ? ['situacao' => 'verificado', 'texto' => "O domínio $dominio está verificado no Resend."]
                : ['situacao' => 'nao_verificado', 'texto' => "O domínio $dominio está no Resend, mas ainda não verificado (situação: " . ($item['status'] ?? 'desconhecida') . '). Sem a verificação os e-mails são recusados.'];
        }
    }
    return ['situacao' => 'nao_encontrado', 'texto' => "O domínio $dominio não está cadastrado no Resend: os e-mails com esse remetente são recusados."];
}
