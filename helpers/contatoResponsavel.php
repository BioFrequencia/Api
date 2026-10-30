<?php
require_once __DIR__ . '/notificacao.php';

/**
 * Contato com o responsável quando o alerta chega ao nível máximo.
 *
 * O que realmente acontece, para não haver dúvida depois: a mensagem é
 * montada aqui e gravada em tb_notificacao com o canal (email/whatsapp).
 * O ENVIO só sai sozinho se houver provedor configurado por variável de
 * ambiente:
 *
 *   SMTP_REMETENTE   e-mail remetente; sem ele, nenhum e-mail é enviado
 *   WHATSAPP_API_URL endpoint do provedor (Meta Cloud API, Twilio, …)
 *   WHATSAPP_TOKEN   token do provedor
 *
 * Sem essas variáveis - que é o estado de um TCC sem conta paga - a
 * notificação fica na fila com um link wa.me pronto, que a coordenação
 * clica e manda pelo WhatsApp dela. Isso funciona sem credencial
 * nenhuma e não finge um envio que não aconteceu.
 *
 * Resultado gravado por mensagem: 'Enviado' (provedor aceitou),
 * 'Pendente' (sem provedor: fila manual) ou 'Erro' (havia provedor e o
 * envio falhou - fica registrado para a coordenação ver e tratar).
 *
 * Nenhum token é lido do código: tudo vem do ambiente.
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

function enviarEmailResponsavel(string $destino, string $assunto, string $mensagem): string {
    $remetente = getenv('SMTP_REMETENTE');
    if ($remetente === false || trim($remetente) === '') {
        return ENVIO_SEM_PROVEDOR; // sem provedor: fica na fila
    }
    $cabecalhos = [
        'From: ' . $remetente,
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if (!@mail($destino, $assunto, $mensagem, implode("\r\n", $cabecalhos))) {
        error_log('[BioFrequenciaApi] Falha ao enviar e-mail ao responsável.');
        return ENVIO_FALHOU;
    }
    return ENVIO_OK;
}

function enviarWhatsappResponsavel(string $telefone, string $mensagem): string {
    $url = getenv('WHATSAPP_API_URL');
    $token = getenv('WHATSAPP_TOKEN');
    if (!$url || !$token) {
        return ENVIO_SEM_PROVEDOR; // sem provedor: fica na fila com o link wa.me
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
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $telefone,
            'type' => 'text',
            'text' => ['body' => $mensagem],
        ]),
    ]);
    $resposta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resposta === false || $status < 200 || $status >= 300) {
        // Só o código HTTP vai para o log: a resposta do provedor pode
        // ecoar o telefone e o texto da mensagem.
        error_log('[BioFrequenciaApi] Falha ao enviar WhatsApp: HTTP ' . $status);
        return ENVIO_FALHOU;
    }
    return ENVIO_OK;
}

function statusPorResultadoEnvio(string $resultado): string {
    return match ($resultado) {
        ENVIO_OK     => NOTIFICACAO_ENVIADA,
        ENVIO_FALHOU => NOTIFICACAO_ERRO,
        default      => NOTIFICACAO_PENDENTE,
    };
}

/**
 * Dispara o contato de nível máximo para todos os responsáveis do aluno.
 * Devolve um resumo do que foi enviado, do que ficou na fila e do que
 * falhou.
 *
 * Não repete o contato se já houve um nas últimas 24h para o mesmo
 * aluno: o alerta continua crítico todo dia, e sem essa trava a família
 * receberia a mesma mensagem diariamente. A trava é tomada com um UPDATE
 * condicional ANTES do envio (e não lida antes e gravada depois): duas
 * telas de alertas abertas ao mesmo tempo liam "sem contato recente" ao
 * mesmo tempo e mandavam a mensagem em dobro.
 */
function contatarResponsaveisNivelMaximo(PDO $conexao, int $idAluno, string $nomeAluno, string $motivo, int $nivel): array {
    $resumo = ['enviados' => 0, 'na_fila' => 0, 'falhas' => 0, 'sem_contato' => 0, 'ja_contatado' => false];

    $stmt = $conexao->prepare("
        SELECT r.id_responsavel, r.nome_responsavel, r.email_responsavel, r.telefone_responsavel
        FROM tb_aluno_responsavel ar
        JOIN tb_responsavel r ON r.id_responsavel = ar.id_responsavel
        WHERE ar.id_aluno = ?
        ORDER BY r.id_responsavel
    ");
    $stmt->bindValue(1, $idAluno);
    $stmt->execute();
    $responsaveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($responsaveis)) {
        // Sem responsável não há a quem avisar - e não se toma a trava,
        // para o contato sair assim que alguém for cadastrado.
        $resumo['sem_contato'] = 1;
        return $resumo;
    }

    $trava = $conexao->prepare("
        UPDATE tb_alerta_nivel
        SET ultimo_contato_em = NOW()
        WHERE id_aluno = ?
          AND (ultimo_contato_em IS NULL OR ultimo_contato_em < NOW() - INTERVAL '24 hours')
        RETURNING id_aluno
    ");
    $trava->bindValue(1, $idAluno);
    $trava->execute();
    if ($trava->fetch() === false) {
        $resumo['ja_contatado'] = true;
        return $resumo;
    }

    $mensagem = "Escola: acompanhamento de frequência de $nomeAluno. "
        . "A situação está classificada como nível $nivel (máximo). $motivo "
        . "Pedimos que entre em contato com a coordenação.";
    $assunto = "Frequência escolar - $nomeAluno";

    $insert = $conexao->prepare("
        INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao, canal, nivel_alerta, data_envio_notificacao)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $contar = function (string $resultado) use (&$resumo): void {
        match ($resultado) {
            ENVIO_OK     => $resumo['enviados']++,
            ENVIO_FALHOU => $resumo['falhas']++,
            default      => $resumo['na_fila']++,
        };
    };

    foreach ($responsaveis as $responsavel) {
        $temContato = false;

        if (!empty($responsavel['email_responsavel'])) {
            $temContato = true;
            $resultado = enviarEmailResponsavel($responsavel['email_responsavel'], $assunto, $mensagem);
            $insert->execute([$idAluno, $responsavel['id_responsavel'], $mensagem,
                statusPorResultadoEnvio($resultado), 'email', $nivel,
                $resultado === ENVIO_OK ? date('Y-m-d H:i:s') : null]);
            $contar($resultado);
        }

        $telefone = telefoneParaWhatsapp($responsavel['telefone_responsavel'] ?? null);
        if ($telefone !== null) {
            $temContato = true;
            $resultado = enviarWhatsappResponsavel($telefone, $mensagem);
            // Sem envio automático, a mensagem guardada já é o link
            // clicável para a coordenação mandar pelo WhatsApp dela.
            $conteudo = $resultado === ENVIO_OK ? $mensagem : linkWhatsapp($telefone, $mensagem);
            $insert->execute([$idAluno, $responsavel['id_responsavel'], $conteudo,
                statusPorResultadoEnvio($resultado), 'whatsapp', $nivel,
                $resultado === ENVIO_OK ? date('Y-m-d H:i:s') : null]);
            $contar($resultado);
        }

        if (!$temContato) {
            $resumo['sem_contato']++;
        }
    }

    $marca = $conexao->prepare("UPDATE tb_alerta_nivel SET ultimo_canal = ? WHERE id_aluno = ?");
    $marca->execute([$resumo['enviados'] > 0 ? 'automatico' : 'fila', $idAluno]);

    return $resumo;
}
