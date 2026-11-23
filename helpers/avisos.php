<?php
require_once __DIR__ . '/contatoResponsavel.php';
require_once __DIR__ . '/indicadores.php';

/**
 * Avisos automáticos aos responsáveis, pelos canais de cada um (WhatsApp,
 * e-mail ou os dois - tb_responsavel.canal_preferido).
 *
 * Dois gatilhos, cada um ligado e desligado por tipo pela gestão em
 * Configurações da escola (helpers/configuracaoEscola.php):
 *  - limite (padrão: ligado para entradas atrasadas e para saídas
 *    antecipadas): o aluno passou do limite da escola (padrão: 20% dos
 *    dias de aula dos últimos 30 dias, com pelo menos 3 ocorrências). Os
 *    responsáveis recebem UMA mensagem, e outra só depois do intervalo da
 *    escola (padrão: 7 dias) se a situação continuar. Avaliado logo depois
 *    de cada registro - não depende de alguém abrir uma tela - e de novo
 *    quando a tela de alertas abre;
 *  - cada registro (padrão: desligado): mensagem a cada entrada atrasada
 *    e/ou a cada saída antecipada.
 *
 * A coordenação também manda mensagens pela tela "Enviar mensagem"
 * (helpers/mensagemManual.php); a entrega é a mesma (entregarMensagem).
 *
 * O que sai de fato depende do provedor (helpers/contatoResponsavel.php):
 * sem ele, a mensagem fica em Notificações com o link do WhatsApp pronto.
 * Um aviso nunca derruba o registro: tudo aqui é chamado depois do
 * COMMIT, e um erro só vai para o log.
 *
 * Cada escola tem o próprio contato (tb_escola.email_contato e
 * telefone_contato, migração 012): ele vai no fim da mensagem e, no
 * e-mail, como "responder para". A escola é sempre a da turma do aluno -
 * nunca a de quem está logado nem um contato único do sistema.
 */

const NOMES_TIPO_AVISO = [
    'atrasos' => ['entrada atrasada', 'entradas atrasadas'],
    'saidas'  => ['saída antecipada', 'saídas antecipadas'],
];

function responsaveisDoAluno(PDO $conexao, int $idAluno): array {
    $stmt = $conexao->prepare("
        SELECT r.id_responsavel, r.nome_responsavel, r.email_responsavel, r.telefone_responsavel, r.canal_preferido
        FROM tb_aluno_responsavel ar
        JOIN tb_responsavel r ON r.id_responsavel = ar.id_responsavel
        WHERE ar.id_aluno = ?
        ORDER BY r.id_responsavel
    ");
    $stmt->execute([$idAluno]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Por onde a mensagem vai para este responsável (função pura): o canal
 * preferido dele e, se faltar o contato daquele canal (ex.: prefere
 * e-mail mas não tem e-mail cadastrado), o que existir.
 */
function canaisDoResponsavel(array $responsavel): array {
    $temWhatsapp = telefoneParaWhatsapp($responsavel['telefone_responsavel'] ?? null) !== null;
    $temEmail = trim((string) ($responsavel['email_responsavel'] ?? '')) !== '';
    $preferido = $responsavel['canal_preferido'] ?? 'whatsapp';

    $canais = [];
    if ($temWhatsapp && in_array($preferido, ['whatsapp', 'ambos'], true)) {
        $canais[] = 'whatsapp';
    }
    if ($temEmail && in_array($preferido, ['email', 'ambos'], true)) {
        $canais[] = 'email';
    }
    if (empty($canais)) {
        if ($temWhatsapp) {
            $canais[] = 'whatsapp';
        } elseif ($temEmail) {
            $canais[] = 'email';
        }
    }
    return $canais;
}

/**
 * A escola do aluno (pela turma) e o contato dela: [nome, email, telefone].
 * null quando o aluno não existe.
 *
 * @return ?array{nome: string, email: ?string, telefone: ?string}
 */
function escolaDoAlunoParaAviso(PDO $conexao, int $idAluno): ?array {
    $stmt = $conexao->prepare("
        SELECT e.nome_escola, e.email_contato, e.telefone_contato
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        JOIN tb_escola e ON e.id_escola = s.id_escola
        WHERE a.id_aluno = ?
    ");
    $stmt->execute([$idAluno]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        return null;
    }
    return [
        'nome' => (string) $linha['nome_escola'],
        'email' => $linha['email_contato'] !== null && $linha['email_contato'] !== '' ? $linha['email_contato'] : null,
        'telefone' => $linha['telefone_contato'] !== null && $linha['telefone_contato'] !== '' ? $linha['telefone_contato'] : null,
    ];
}

/** "(11) 99999-1111": telefone da escola só com dígitos -> formato de leitura. Função pura. */
function telefoneDeContatoBr(string $digitos): string {
    $d = preg_replace('/\D/', '', $digitos) ?? '';
    if (strlen($d) === 11) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
    }
    if (strlen($d) === 10) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    }
    return $digitos;
}

/**
 * A linha com o contato da escola, no fim da mensagem: "Contato da E.E.
 * Exemplo: (11) 99999-1111 · contato@escola.br". Vazia se a escola não
 * cadastrou contato. Função pura.
 */
function assinaturaDaEscola(array $escola): string {
    $contatos = array_values(array_filter([
        $escola['telefone'] !== null ? telefoneDeContatoBr($escola['telefone']) : null,
        $escola['email'],
    ]));
    return $contatos === [] ? '' : "Contato da {$escola['nome']}: " . implode(' · ', $contatos) . '.';
}

/** A mensagem com a assinatura da escola numa linha separada (se houver contato). Função pura. */
function comAssinaturaDaEscola(string $mensagem, array $escola): string {
    $assinatura = assinaturaDaEscola($escola);
    return $assinatura === '' ? $mensagem : rtrim($mensagem) . "\n\n" . $assinatura;
}

function mensagemAvisoLimite(string $escola, string $aluno, string $tipo, int $ocorrencias, int $diasDeAula, ?float $taxa, int $janela): string {
    [$singular, $plural] = NOMES_TIPO_AVISO[$tipo];
    return sprintf(
        '%s: %s teve %d %s em %d dias de aula nos últimos %d dias (%s%%). Pedimos que converse com o(a) estudante e, se precisar, procure a coordenação da escola.',
        $escola, $aluno, $ocorrencias, $ocorrencias === 1 ? $singular : $plural, $diasDeAula, $janela, numeroBr($taxa)
    );
}

function mensagemAvisoRegistro(string $escola, string $aluno, string $tipo, string $dataLocal, string $horaLocal, ?string $motivo, ?string $retiradoPor): string {
    $texto = sprintf(
        '%s: registramos a %s de %s em %s às %s.',
        $escola, $tipo === 'atraso' ? 'entrada atrasada' : 'saída antecipada', $aluno, dataBr($dataLocal), $horaLocal
    );
    if ($motivo !== null && trim($motivo) !== '') {
        $texto .= ' Motivo: ' . trim($motivo) . '.';
    }
    if ($retiradoPor !== null && trim($retiradoPor) !== '') {
        $texto .= ' Retirado(a) por: ' . trim($retiradoPor) . '.';
    }
    return $texto;
}

/**
 * Manda uma mensagem por um canal ao responsável e devolve o resultado
 * (ENVIO_*), o que fica guardado em tb_notificacao (no WhatsApp sem envio
 * automático, o link wa.me pronto para a coordenação mandar pelo WhatsApp
 * dela) e o motivo da falha, quando falhou. $chave vai ao provedor de
 * e-mail como chave de idempotência (o mesmo pedido repetido não manda
 * duas vezes); $responderPara é o e-mail de contato da escola.
 *
 * @return array{0: string, 1: string, 2: ?string}
 */
function entregarMensagem(string $canal, array $responsavel, string $assunto, string $mensagem, ?string $chave = null, ?string $responderPara = null): array {
    if ($canal === 'whatsapp') {
        $telefone = telefoneParaWhatsapp($responsavel['telefone_responsavel'] ?? null);
        if ($telefone === null) {
            return [ENVIO_FALHOU, $mensagem, 'WhatsApp: o responsável não tem telefone com DDD.'];
        }
        [$resultado, $motivo] = enviarWhatsappResponsavel($telefone, $mensagem);
        // Sem provedor, o link wa.me pronto; se o provedor falhou, o texto
        // (para "Tentar de novo" - a tela também monta o link, se quiser).
        return [$resultado, $resultado === ENVIO_SEM_PROVEDOR ? linkWhatsapp($telefone, $mensagem) : $mensagem, $motivo];
    }
    $email = trim((string) ($responsavel['email_responsavel'] ?? ''));
    if ($email === '') {
        return [ENVIO_FALHOU, $mensagem, 'e-mail: o responsável não tem e-mail cadastrado.'];
    }
    [$resultado, $motivo] = enviarEmailResponsavel($email, $assunto, $mensagem, $chave, $responderPara);
    return [$resultado, $mensagem, $motivo];
}

/**
 * Envia (ou deixa na fila) a mensagem para os responsáveis e grava cada
 * envio em tb_notificacao, com o canal e o motivo do aviso. Aviso
 * automático: sem conta da coordenação (id_coordenacao vazio).
 *
 * @return array{responsaveis:int, enviados:int, na_fila:int, falhas:int, sem_contato:int}
 */
function enviarAosResponsaveis(PDO $conexao, int $idAluno, array $responsaveis, string $assunto, string $mensagem, string $tipoAviso, ?string $responderPara = null): array {
    $resumo = ['responsaveis' => count($responsaveis), 'enviados' => 0, 'na_fila' => 0, 'falhas' => 0, 'sem_contato' => 0];
    $insert = $conexao->prepare("
        INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao, canal, tipo_aviso, data_envio_notificacao, assunto, erro_envio)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($responsaveis as $responsavel) {
        $canais = canaisDoResponsavel($responsavel);
        if (empty($canais)) {
            $resumo['sem_contato']++;
            continue;
        }
        foreach ($canais as $canal) {
            [$resultado, $conteudo, $motivo] = entregarMensagem($canal, $responsavel, $assunto, $mensagem, null, $responderPara);
            $insert->execute([
                $idAluno, $responsavel['id_responsavel'], $conteudo, statusPorResultadoEnvio($resultado), $canal, $tipoAviso,
                $resultado === ENVIO_OK ? agoraUtc()->format('Y-m-d H:i:s') : null,
                $canal === 'email' ? mb_substr($assunto, 0, 150) : null,
                $resultado === ENVIO_FALHOU ? $motivo : null,
            ]);
            match ($resultado) {
                ENVIO_OK     => $resumo['enviados']++,
                ENVIO_FALHOU => $resumo['falhas']++,
                default      => $resumo['na_fila']++,
            };
        }
    }
    return $resumo;
}

function nomeDaEscolaParaAviso(PDO $conexao, int $idEscola): string {
    $stmt = $conexao->prepare("SELECT nome_escola FROM tb_escola WHERE id_escola = ?");
    $stmt->execute([$idEscola]);
    return (string) ($stmt->fetchColumn() ?: 'Escola');
}

/**
 * Aviso por limite: se o aluno passou do limite (prioridade média ou
 * alta), avisa os responsáveis - no máximo uma vez por intervalo, por
 * aluno e tipo.
 *
 * A trava é tomada ANTES do envio, num INSERT ... ON CONFLICT condicional
 * (e não lida antes e gravada depois): dois registros quase simultâneos
 * do mesmo aluno, ou o registro e a tela de alertas ao mesmo tempo,
 * mandariam a mensagem em dobro. Sem responsável cadastrado a trava não
 * é tomada, para o aviso sair assim que alguém for cadastrado.
 *
 * @return array<string, array> tipo => resumo (ou ['ja_avisado' => true] / ['sem_responsavel' => true])
 */
function avisarSeAtingiuLimite(PDO $conexao, int $idEscola, int $idAluno, array $config, ?array $indicador = null): array {
    $ligado = ['atrasos' => $config['aviso_limite_atrasos'], 'saidas' => $config['aviso_limite_saidas']];
    if (!in_array(true, $ligado, true)) {
        return [];
    }
    if ($indicador === null) {
        $indicador = indicadoresDaJanela($conexao, $idEscola, $config, null, null, $idAluno)['alunos'][$idAluno] ?? null;
        if ($indicador === null) {
            return [];
        }
    }

    $avisos = [];
    $responsaveis = null;
    $escola = null;
    foreach (array_keys(NOMES_TIPO_AVISO) as $tipo) {
        if (!$ligado[$tipo] || !in_array($indicador["prioridade_$tipo"], ['media', 'alta'], true)) {
            continue;
        }
        $responsaveis = $responsaveis ?? responsaveisDoAluno($conexao, $idAluno);
        if (empty($responsaveis)) {
            $avisos[$tipo] = ['sem_responsavel' => true];
            continue;
        }

        $trava = $conexao->prepare("
            INSERT INTO tb_aviso_limite (id_aluno, tipo, enviado_em, taxa, ocorrencias)
            VALUES (?, ?, NOW(), ?, ?)
            ON CONFLICT (id_aluno, tipo) DO UPDATE
            SET enviado_em = NOW(), taxa = EXCLUDED.taxa, ocorrencias = EXCLUDED.ocorrencias
            WHERE tb_aviso_limite.enviado_em < NOW() - make_interval(days => ?::int)
            RETURNING id_aluno
        ");
        $trava->execute([$idAluno, $tipo, $indicador["taxa_$tipo"], $indicador[$tipo], $config['intervalo_aviso_dias']]);
        if ($trava->fetch() === false) {
            $avisos[$tipo] = ['ja_avisado' => true];
            continue;
        }

        $escola = $escola ?? (escolaDoAlunoParaAviso($conexao, $idAluno) ?? ['nome' => nomeDaEscolaParaAviso($conexao, $idEscola), 'email' => null, 'telefone' => null]);
        $mensagem = comAssinaturaDaEscola(mensagemAvisoLimite(
            $escola['nome'], $indicador['nome_aluno'], $tipo, $indicador[$tipo], $indicador['dias_letivos'],
            $indicador["taxa_$tipo"], $config['janela_alerta_dias']
        ), $escola);
        $avisos[$tipo] = enviarAosResponsaveis(
            $conexao, $idAluno, $responsaveis, "Entradas e saídas - {$indicador['nome_aluno']}", $mensagem, "limite_$tipo", $escola['email']
        );
    }
    return $avisos;
}

/**
 * Depois de gravar uma entrada atrasada ('atraso') ou saída antecipada
 * ('saida'): o aviso do próprio registro (se a escola liga) e o aviso
 * por limite. Chamado fora da transação; nunca lança erro.
 *
 * @return array{registro: ?array, limite: array}
 */
function avisosDepoisDoRegistro(
    PDO $conexao, int $idEscola, int $idAluno, string $nomeAluno, string $tipo,
    string $momentoUtc, ?string $motivo = null, ?string $retiradoPor = null
): array {
    try {
        $config = configuracaoDaEscola($conexao, $idEscola);
        $registro = null;
        if ($tipo === 'atraso' ? $config['aviso_entrada_atrasada'] : $config['aviso_saida_antecipada']) {
            $responsaveis = responsaveisDoAluno($conexao, $idAluno);
            if (!empty($responsaveis)) {
                $local = (new DateTimeImmutable($momentoUtc, new DateTimeZone('UTC')))->setTimezone(fusoEscola());
                $escola = escolaDoAlunoParaAviso($conexao, $idAluno) ?? ['nome' => nomeDaEscolaParaAviso($conexao, $idEscola), 'email' => null, 'telefone' => null];
                $mensagem = comAssinaturaDaEscola(mensagemAvisoRegistro(
                    $escola['nome'], $nomeAluno, $tipo,
                    $local->format('Y-m-d'), $local->format('H:i'), $motivo, $retiradoPor
                ), $escola);
                $assunto = ($tipo === 'atraso' ? 'Entrada atrasada' : 'Saída antecipada') . " - $nomeAluno";
                $registro = enviarAosResponsaveis($conexao, $idAluno, $responsaveis, $assunto, $mensagem, "registro_$tipo", $escola['email']);
            }
        }
        return ['registro' => $registro, 'limite' => avisarSeAtingiuLimite($conexao, $idEscola, $idAluno, $config)];
    } catch (PDOException $erro) {
        error_log('[BioFrequenciaApi] Falha ao avisar os responsáveis: ' . $erro->getMessage());
        return ['registro' => null, 'limite' => []];
    }
}
