<?php
require_once __DIR__ . '/contatoResponsavel.php';
require_once __DIR__ . '/indicadores.php';

/**
 * Avisos automáticos aos responsáveis, pelos canais de cada um (WhatsApp,
 * e-mail ou os dois - tb_responsavel.canal_preferido).
 *
 * Dois gatilhos, ligados e desligados pela gestão em Configurações da
 * escola:
 *  - limite (padrão: ligado): o aluno passou do limite da escola (padrão:
 *    20% dos dias de aula dos últimos 30 dias, com pelo menos 3
 *    ocorrências). Os responsáveis recebem UMA mensagem, e outra só
 *    depois do intervalo da escola (padrão: 7 dias) se a situação
 *    continuar. Avaliado logo depois de cada registro - não depende de
 *    alguém abrir uma tela - e de novo quando a tela de alertas abre;
 *  - cada registro (padrão: desligado): mensagem a cada entrada atrasada
 *    ou saída antecipada.
 *
 * O que sai de fato depende do provedor (helpers/contatoResponsavel.php):
 * sem ele, a mensagem fica em Notificações com o link do WhatsApp pronto.
 * Um aviso nunca derruba o registro: tudo aqui é chamado depois do
 * COMMIT, e um erro só vai para o log.
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
 * Envia (ou deixa na fila) a mensagem para os responsáveis e grava cada
 * envio em tb_notificacao, com o canal e o motivo do aviso.
 *
 * @return array{responsaveis:int, enviados:int, na_fila:int, falhas:int, sem_contato:int}
 */
function enviarAosResponsaveis(PDO $conexao, int $idAluno, array $responsaveis, string $assunto, string $mensagem, string $tipoAviso): array {
    $resumo = ['responsaveis' => count($responsaveis), 'enviados' => 0, 'na_fila' => 0, 'falhas' => 0, 'sem_contato' => 0];
    $insert = $conexao->prepare("
        INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao, canal, tipo_aviso, data_envio_notificacao)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($responsaveis as $responsavel) {
        $canais = canaisDoResponsavel($responsavel);
        if (empty($canais)) {
            $resumo['sem_contato']++;
            continue;
        }
        foreach ($canais as $canal) {
            if ($canal === 'whatsapp') {
                $telefone = telefoneParaWhatsapp($responsavel['telefone_responsavel']);
                $resultado = enviarWhatsappResponsavel($telefone, $mensagem);
                // Sem envio automático, a mensagem guardada já é o link
                // clicável para a coordenação mandar pelo WhatsApp dela.
                $conteudo = $resultado === ENVIO_OK ? $mensagem : linkWhatsapp($telefone, $mensagem);
            } else {
                $resultado = enviarEmailResponsavel($responsavel['email_responsavel'], $assunto, $mensagem);
                $conteudo = $mensagem;
            }
            $insert->execute([
                $idAluno, $responsavel['id_responsavel'], $conteudo, statusPorResultadoEnvio($resultado), $canal, $tipoAviso,
                $resultado === ENVIO_OK ? agoraUtc()->format('Y-m-d H:i:s') : null,
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
    if (!$config['aviso_automatico']) {
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
        if (!in_array($indicador["prioridade_$tipo"], ['media', 'alta'], true)) {
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

        $escola = $escola ?? nomeDaEscolaParaAviso($conexao, $idEscola);
        $mensagem = mensagemAvisoLimite(
            $escola, $indicador['nome_aluno'], $tipo, $indicador[$tipo], $indicador['dias_letivos'],
            $indicador["taxa_$tipo"], $config['janela_alerta_dias']
        );
        $avisos[$tipo] = enviarAosResponsaveis(
            $conexao, $idAluno, $responsaveis, "Entradas e saídas - {$indicador['nome_aluno']}", $mensagem, "limite_$tipo"
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
        if ($config['aviso_cada_registro']) {
            $responsaveis = responsaveisDoAluno($conexao, $idAluno);
            if (!empty($responsaveis)) {
                $local = (new DateTimeImmutable($momentoUtc, new DateTimeZone('UTC')))->setTimezone(fusoEscola());
                $mensagem = mensagemAvisoRegistro(
                    nomeDaEscolaParaAviso($conexao, $idEscola), $nomeAluno, $tipo,
                    $local->format('Y-m-d'), $local->format('H:i'), $motivo, $retiradoPor
                );
                $assunto = ($tipo === 'atraso' ? 'Entrada atrasada' : 'Saída antecipada') . " - $nomeAluno";
                $registro = enviarAosResponsaveis($conexao, $idAluno, $responsaveis, $assunto, $mensagem, "registro_$tipo");
            }
        }
        return ['registro' => $registro, 'limite' => avisarSeAtingiuLimite($conexao, $idEscola, $idAluno, $config)];
    } catch (PDOException $erro) {
        error_log('[BioFrequenciaApi] Falha ao avisar os responsáveis: ' . $erro->getMessage());
        return ['registro' => null, 'limite' => []];
    }
}
