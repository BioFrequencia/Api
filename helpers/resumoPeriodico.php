<?php
require_once __DIR__ . '/avisos.php';
require_once __DIR__ . '/configuracaoEscola.php';
require_once __DIR__ . '/notificacao.php';
require_once __DIR__ . '/tempo.php';

/**
 * Resumo periódico às famílias (migração 014): cada responsável recebe,
 * pelo canal dele, quantas entradas atrasadas e saídas antecipadas o(a)
 * estudante teve na semana (toda segunda-feira, da semana anterior) ou no
 * mês (dia 1º, do mês anterior) - conforme a escola escolheu em
 * Configurações. Aluno sem nenhum registro no período não recebe nada:
 * o resumo não vira spam.
 *
 * Quem dispara é um agendador (o workflow "Resumo às famílias" do
 * GitHub Actions chama notificacao/resumoPeriodico.php todo dia de
 * manhã); a API decide quem está no dia. Rodar duas vezes no mesmo dia
 * não manda duas vezes: cada mensagem entra em tb_notificacao com uma
 * chave do período (chave_envio) ANTES de sair.
 */

/**
 * O período que o resumo cobre, se hoje é dia de mandar: semanal na
 * segunda-feira (segunda a domingo anteriores), mensal no dia 1º (o mês
 * anterior inteiro). null: hoje não é dia (ou a escola desligou).
 * Função pura.
 *
 * @return ?array{inicio: string, fim: string, rotulo: string, chave: string}
 */
function periodoDoResumo(string $frequencia, string $hojeLocal): ?array {
    $hoje = new DateTimeImmutable($hojeLocal);
    if ($frequencia === 'semanal' && $hoje->format('N') === '1') {
        $inicio = $hoje->modify('-7 days');
        $fim = $hoje->modify('-1 day');
        return [
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
            'rotulo' => 'na semana de ' . $inicio->format('d/m') . ' a ' . $fim->format('d/m'),
            'chave' => 'semana-' . $inicio->format('Y-m-d'),
        ];
    }
    if ($frequencia === 'mensal' && $hoje->format('j') === '1') {
        $inicio = $hoje->modify('first day of previous month');
        $fim = $hoje->modify('-1 day');
        return [
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
            'rotulo' => 'em ' . MESES_RESUMO[(int) $inicio->format('n') - 1] . ' de ' . $inicio->format('Y'),
            'chave' => 'mes-' . $inicio->format('Y-m'),
        ];
    }
    return null;
}

const MESES_RESUMO = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** O texto do resumo de um(a) estudante. Função pura. */
function mensagemDoResumo(string $escola, string $aluno, string $rotuloPeriodo, int $atrasos, int $saidas): string {
    $partes = [];
    if ($atrasos > 0) {
        $partes[] = $atrasos . ' ' . ($atrasos === 1 ? 'entrada atrasada' : 'entradas atrasadas');
    }
    if ($saidas > 0) {
        $partes[] = $saidas . ' ' . ($saidas === 1 ? 'saída antecipada' : 'saídas antecipadas');
    }
    return sprintf(
        '%s: resumo de %s %s - %s. Os detalhes de cada registro estão com a coordenação da escola.',
        $escola, $aluno, $rotuloPeriodo, implode(' e ', $partes)
    );
}

/**
 * Alunos ATIVOS da escola com entrada atrasada ou saída antecipada (não
 * canceladas) no período, com as contagens.
 *
 * @return list<array{id_aluno: int, nome_aluno: string, atrasos: int, saidas: int}>
 */
function alunosParaResumo(PDO $conexao, int $idEscola, string $inicio, string $fim): array {
    $de = localParaUtc($inicio);
    $ate = localParaUtc(diaSeguinte($fim));
    $stmt = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno,
               (SELECT COUNT(*) FROM tb_atraso t WHERE t.id_aluno = a.id_aluno AND t.cancelado_em IS NULL
                   AND t.dta_atraso >= ? AND t.dta_atraso < ?) AS atrasos,
               (SELECT COUNT(*) FROM tb_saida_antecipada r WHERE r.id_aluno = a.id_aluno AND r.cancelado_em IS NULL
                   AND r.dta_saida >= ? AND r.dta_saida < ?) AS saidas
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE s.id_escola = ? AND a.situacao_aluno = 'ativo'
        ORDER BY a.id_aluno
    ");
    $stmt->execute([$de, $ate, $de, $ate, $idEscola]);
    $alunos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $atrasos = (int) $linha['atrasos'];
        $saidas = (int) $linha['saidas'];
        if ($atrasos + $saidas > 0) {
            $alunos[] = ['id_aluno' => (int) $linha['id_aluno'], 'nome_aluno' => $linha['nome_aluno'], 'atrasos' => $atrasos, 'saidas' => $saidas];
        }
    }
    return $alunos;
}

/**
 * Manda o resumo de um(a) estudante a cada responsável, por canal. A
 * linha entra antes com a chave do período: se já existe (o agendador
 * rodou de novo), não sai outra vez.
 *
 * @return array{enviados: int, na_fila: int, falhas: int, repetidos: int, sem_contato: int}
 */
function enviarResumoDoAluno(PDO $conexao, array $aluno, array $escola, array $periodo, string $frequencia): array {
    $resumo = ['enviados' => 0, 'na_fila' => 0, 'falhas' => 0, 'repetidos' => 0, 'sem_contato' => 0];
    $responsaveis = responsaveisDoAluno($conexao, $aluno['id_aluno']);
    if (empty($responsaveis)) {
        return $resumo;
    }
    $mensagem = comAssinaturaDaEscola(
        mensagemDoResumo($escola['nome'], $aluno['nome_aluno'], $periodo['rotulo'], $aluno['atrasos'], $aluno['saidas']),
        $escola
    );
    $assunto = ($frequencia === 'mensal' ? 'Resumo do mês' : 'Resumo da semana') . " - {$aluno['nome_aluno']}";
    $chave = "resumo-{$periodo['chave']}-aluno-{$aluno['id_aluno']}";

    $insert = $conexao->prepare("
        INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao, canal, tipo_aviso, assunto, chave_envio, criado_em)
        VALUES (?, ?, ?, ?, ?, 'resumo', ?, ?, NOW())
        ON CONFLICT (chave_envio, id_responsavel, canal) WHERE chave_envio IS NOT NULL DO NOTHING
        RETURNING id_notificacao
    ");
    $atualiza = $conexao->prepare("
        UPDATE tb_notificacao SET status_notificacao = ?, mensagem = ?, data_envio_notificacao = ?, erro_envio = ?, remetente_envio = ? WHERE id_notificacao = ?
    ");
    foreach ($responsaveis as $responsavel) {
        $canais = canaisDoResponsavel($responsavel);
        if (empty($canais)) {
            $resumo['sem_contato']++;
            continue;
        }
        foreach ($canais as $canal) {
            $insert->execute([
                $aluno['id_aluno'], $responsavel['id_responsavel'], $mensagem, NOTIFICACAO_PENDENTE, $canal,
                $canal === 'email' ? mb_substr($assunto, 0, 150) : null, $chave,
            ]);
            $id = $insert->fetchColumn();
            if ($id === false) {
                $resumo['repetidos']++;
                continue;
            }
            [$resultado, $conteudo, $motivo, $remetente] = entregarMensagem(
                $canal, $responsavel, $assunto, $mensagem, 'biofrequencia-notificacao-' . $id, $escola
            );
            $atualiza->execute([
                statusPorResultadoEnvio($resultado), $conteudo,
                $resultado === ENVIO_OK ? agoraUtc()->format('Y-m-d H:i:s') : null,
                $resultado === ENVIO_FALHOU ? $motivo : null, $remetente, (int) $id,
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

/**
 * Roda o resumo de todas as escolas que têm turma, para o dia $hojeLocal.
 * Só as escolas em que hoje é dia de mandar (pela frequência de cada uma)
 * e só alunos com algum registro no período.
 *
 * @return array{escolas: int, alunos: int, enviados: int, na_fila: int, falhas: int, repetidos: int, sem_contato: int}
 */
function rodarResumoPeriodico(PDO $conexao, string $hojeLocal): array {
    $total = ['escolas' => 0, 'alunos' => 0, 'enviados' => 0, 'na_fila' => 0, 'falhas' => 0, 'repetidos' => 0, 'sem_contato' => 0];
    $escolas = $conexao->query("SELECT DISTINCT id_escola FROM tb_sala ORDER BY id_escola")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($escolas as $idEscola) {
        $idEscola = (int) $idEscola;
        $config = configuracaoDaEscola($conexao, $idEscola);
        $periodo = periodoDoResumo($config['resumo_periodico'], $hojeLocal);
        if ($periodo === null) {
            continue;
        }
        $alunos = alunosParaResumo($conexao, $idEscola, $periodo['inicio'], $periodo['fim']);
        if (empty($alunos)) {
            continue;
        }
        $total['escolas']++;
        foreach ($alunos as $aluno) {
            $escola = escolaParaAviso($conexao, $idEscola, (int) $aluno['id_aluno']);
            try {
                $resultado = enviarResumoDoAluno($conexao, $aluno, $escola, $periodo, $config['resumo_periodico']);
            } catch (PDOException $erro) {
                error_log('[BioFrequenciaApi] Resumo não enviado: ' . $erro->getMessage());
                continue;
            }
            $total['alunos']++;
            foreach (['enviados', 'na_fila', 'falhas', 'repetidos', 'sem_contato'] as $campo) {
                $total[$campo] += $resultado[$campo];
            }
        }
    }
    return $total;
}

/**
 * O agendador se identifica com a chave AGENDADOR_CHAVE (cabeçalho
 * X-Chave-Agendador). Sem a variável no servidor, o resumo fica
 * desligado (503) - nunca aberto. Função pura (recebe os valores).
 */
function chaveDoAgendadorConfere(?string $configurada, ?string $recebida): bool {
    $configurada = trim((string) $configurada);
    return strlen($configurada) >= 16 && $recebida !== null && hash_equals($configurada, trim($recebida));
}
