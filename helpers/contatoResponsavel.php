<?php
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
 * Nenhum token é lido do código: tudo vem do ambiente.
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

function enviarEmailResponsavel(string $destino, string $assunto, string $mensagem): bool {
    $remetente = getenv('SMTP_REMETENTE');
    if ($remetente === false || trim($remetente) === '') {
        return false; // sem provedor: fica na fila
    }
    $cabecalhos = [
        'From: ' . $remetente,
        'Content-Type: text/plain; charset=UTF-8',
    ];
    return @mail($destino, $assunto, $mensagem, implode("\r\n", $cabecalhos));
}

function enviarWhatsappResponsavel(string $telefone, string $mensagem): bool {
    $url = getenv('WHATSAPP_API_URL');
    $token = getenv('WHATSAPP_TOKEN');
    if (!$url || !$token) {
        return false; // sem provedor: fica na fila com o link wa.me
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
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
    if ($resposta === false || $status >= 300) {
        error_log('[BioFrequenciaApi] Falha ao enviar WhatsApp: HTTP ' . $status);
        return false;
    }
    return true;
}

/**
 * Dispara o contato de nível máximo para todos os responsáveis do aluno.
 * Devolve um resumo do que foi enviado e do que ficou na fila.
 *
 * Não repete o contato se já houve um nas últimas 24h para o mesmo
 * aluno: o alerta continua crítico todo dia, e sem essa trava a família
 * receberia a mesma mensagem diariamente.
 */
function contatarResponsaveisNivelMaximo(PDO $conexao, int $idAluno, string $nomeAluno, string $motivo, int $nivel): array {
    $resumo = ['enviados' => 0, 'na_fila' => 0, 'sem_contato' => 0, 'ja_contatado' => false];

    $ultimo = $conexao->prepare("SELECT ultimo_contato_em FROM tb_alerta_nivel WHERE id_aluno = ?");
    $ultimo->bindValue(1, $idAluno);
    $ultimo->execute();
    $linha = $ultimo->fetch(PDO::FETCH_ASSOC);
    if ($linha && $linha['ultimo_contato_em'] !== null
        && (time() - strtotime($linha['ultimo_contato_em'])) < 86400) {
        $resumo['ja_contatado'] = true;
        return $resumo;
    }

    $stmt = $conexao->prepare("
        SELECT r.id_responsavel, r.nome_responsavel, r.email_responsavel, r.telefone_responsavel
        FROM tb_aluno_responsavel ar
        JOIN tb_responsavel r ON r.id_responsavel = ar.id_responsavel
        WHERE ar.id_aluno = ?
    ");
    $stmt->bindValue(1, $idAluno);
    $stmt->execute();
    $responsaveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($responsaveis)) {
        $resumo['sem_contato'] = 1;
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

    foreach ($responsaveis as $responsavel) {
        $temContato = false;

        if (!empty($responsavel['email_responsavel'])) {
            $temContato = true;
            $enviado = enviarEmailResponsavel($responsavel['email_responsavel'], $assunto, $mensagem);
            $insert->execute([$idAluno, $responsavel['id_responsavel'], $mensagem,
                $enviado ? 'enviada' : 'pendente', 'email', $nivel, $enviado ? date('Y-m-d H:i:s') : null]);
            $enviado ? $resumo['enviados']++ : $resumo['na_fila']++;
        }

        $telefone = telefoneParaWhatsapp($responsavel['telefone_responsavel'] ?? null);
        if ($telefone !== null) {
            $temContato = true;
            $enviado = enviarWhatsappResponsavel($telefone, $mensagem);
            // Sem provedor, a mensagem guardada já é o link clicável.
            $conteudo = $enviado ? $mensagem : linkWhatsapp($telefone, $mensagem);
            $insert->execute([$idAluno, $responsavel['id_responsavel'], $conteudo,
                $enviado ? 'enviada' : 'pendente', 'whatsapp', $nivel, $enviado ? date('Y-m-d H:i:s') : null]);
            $enviado ? $resumo['enviados']++ : $resumo['na_fila']++;
        }

        if (!$temContato) {
            $resumo['sem_contato']++;
        }
    }

    $marca = $conexao->prepare("
        UPDATE tb_alerta_nivel SET ultimo_contato_em = NOW(), ultimo_canal = ? WHERE id_aluno = ?
    ");
    $marca->execute([$resumo['enviados'] > 0 ? 'automatico' : 'fila', $idAluno]);

    return $resumo;
}
