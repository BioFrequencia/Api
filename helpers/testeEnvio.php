<?php
require_once __DIR__ . '/contatoResponsavel.php';

/**
 * Mensagem de teste do envio (Administração > Visão geral): depois de
 * contratar e configurar um provedor, a equipe do sistema confere com UMA
 * mensagem, para um destino que ela mesma digita (o próprio e-mail ou
 * celular), se o provedor aceita o envio - sem esperar um aluno chegar
 * atrasado. Sai pelo mesmo caminho dos avisos reais (formato, modelo do
 * WhatsApp, remetente), então o que funcionar aqui funciona nos avisos.
 *
 * Não entra em Notificações (não é aviso a um responsável) e o destino não
 * vai para o log. Sem provedor configurado nada é enviado e a resposta diz
 * isso - nenhum envio é simulado.
 */

const ASSUNTO_TESTE_ENVIO = 'Teste de envio - BioFrequência';
const TEXTO_TESTE_ENVIO = 'Mensagem de teste do BioFrequência, pedida pela equipe do sistema para conferir o envio automático aos responsáveis. Nenhuma ação é necessária.';

/** O destino digitado, já no formato do canal (e-mail; WhatsApp com 55 e DDD), ou null se não serve. Função pura. */
function destinoDoTeste(string $canal, string $destino): ?string {
    $destino = trim($destino);
    if ($canal === 'email') {
        return mb_strlen($destino) <= 150 && filter_var($destino, FILTER_VALIDATE_EMAIL) !== false ? $destino : null;
    }
    if ($canal === 'whatsapp') {
        return telefoneParaWhatsapp($destino);
    }
    return null;
}

/**
 * Envia a mensagem de teste pelo provedor configurado.
 *
 * @return array{0: string, 1: ?string} [ENVIO_*, motivo da falha]
 */
function enviarMensagemDeTeste(string $canal, string $destino): array {
    if ($canal === 'email') {
        return enviarEmailDetalhado($destino, ASSUNTO_TESTE_ENVIO, TEXTO_TESTE_ENVIO);
    }
    return enviarWhatsappResponsavel($destino, TEXTO_TESTE_ENVIO);
}

/** O que a tela mostra para cada resultado. Função pura. */
function mensagemDoResultadoDoTeste(string $canal, string $resultado, ?string $motivo): string {
    $nome = $canal === 'email' ? 'e-mail' : 'WhatsApp';
    return match ($resultado) {
        ENVIO_OK => "O provedor de $nome aceitou a mensagem de teste. Confira se ela chegou: aceitar ainda não garante a entrega.",
        ENVIO_SEM_PROVEDOR => $canal === 'email'
            ? 'Nenhum provedor de e-mail configurado na Render (EMAIL_API_URL, EMAIL_API_TOKEN e EMAIL_REMETENTE): nada foi enviado.'
            : 'Nenhum provedor de WhatsApp configurado na Render (WHATSAPP_API_URL e WHATSAPP_TOKEN): nada foi enviado.',
        default => $motivo !== null && $motivo !== '' ? $motivo : "O provedor de $nome recusou a mensagem de teste.",
    };
}
