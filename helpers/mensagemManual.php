<?php
/**
 * Mensagens que a coordenação manda aos responsáveis pela tela "Enviar
 * mensagem" (notificacao/contatos.php, previa.php e enviar.php): escolher
 * o aluno, os responsáveis e os canais (WhatsApp, e-mail), escrever ou
 * usar um modelo, ver a prévia e enviar. A entrega é a mesma dos avisos
 * automáticos (entregarMensagem, helpers/avisos.php).
 *
 * Sem duplicados: a tela gera uma chave por mensagem (chave_envio). O
 * envio grava as linhas ANTES de mandar (com a chave, única por
 * responsável e canal) e só depois entrega; o mesmo pedido repetido -
 * clique duplo, a conexão que caiu e o navegador ou o programa que tentou
 * de novo - acha as linhas que já existem e não manda outra vez. No
 * e-mail, o provedor também recebe uma chave de idempotência por mensagem.
 */

require_once __DIR__ . '/avisos.php';
require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/notificacao.php';
require_once __DIR__ . '/tempo.php';
require_once __DIR__ . '/validacao.php';

const MENSAGEM_MANUAL_MAXIMO = 1000;
const DESTINOS_POR_ENVIO = 20;

/** O que cada variável vira na mensagem (a tela mostra a lista). */
const VARIAVEIS_DA_MENSAGEM = [
    'responsavel' => 'primeiro nome do responsável',
    'aluno'       => 'nome do aluno',
    'turma'       => 'turma do aluno',
    'escola'      => 'nome da escola',
    'data'        => 'data de hoje',
];

/** Modelos prontos: o texto pode ser mudado antes de enviar. */
const MODELOS_DE_MENSAGEM = [
    ['chave' => 'livre', 'titulo' => 'Mensagem livre', 'texto' => ''],
    ['chave' => 'entrada_atrasada', 'titulo' => 'Entrada atrasada hoje',
     'texto' => 'Olá, {responsavel}. Registramos hoje, {data}, uma entrada atrasada de {aluno} ({turma}). Se precisar, fale com a coordenação da {escola}.'],
    ['chave' => 'saida_antecipada', 'titulo' => 'Saída antecipada hoje',
     'texto' => 'Olá, {responsavel}. Registramos hoje, {data}, a saída antecipada de {aluno} ({turma}). Se precisar, fale com a coordenação da {escola}.'],
    ['chave' => 'atrasos_frequentes', 'titulo' => 'Entradas atrasadas frequentes',
     'texto' => 'Olá, {responsavel}. Notamos entradas atrasadas frequentes de {aluno} ({turma}) nas últimas semanas. Pedimos que converse com o(a) estudante e, se precisar, procure a coordenação da {escola}.'],
    ['chave' => 'saidas_frequentes', 'titulo' => 'Saídas antecipadas frequentes',
     'texto' => 'Olá, {responsavel}. Notamos saídas antecipadas frequentes de {aluno} ({turma}) nas últimas semanas. Se houver algum motivo, conte para a coordenação da {escola}.'],
    ['chave' => 'conversa', 'titulo' => 'Pedido de conversa',
     'texto' => 'Olá, {responsavel}. A coordenação da {escola} gostaria de conversar sobre a frequência de {aluno} ({turma}). Por favor, entre em contato ou passe na escola quando puder.'],
];

/** Troca {responsavel}, {aluno}, {turma}, {escola} e {data} pelos dados; o resto do texto fica como está. */
function montarMensagem(string $texto, array $dados): string {
    $trocas = [];
    foreach (array_keys(VARIAVEIS_DA_MENSAGEM) as $variavel) {
        $trocas['{' . $variavel . '}'] = (string) ($dados[$variavel] ?? '');
    }
    return trim(strtr($texto, $trocas));
}

/** "Maria da Silva" -> "Maria". */
function primeiroNomeDoResponsavel(string $nome): string {
    $partes = preg_split('/\s+/u', trim($nome));
    return $partes[0] ?? '';
}

/** O texto que a coordenação escreveu: [texto arrumado, erro]. */
function conferirTextoDaMensagem($texto): array {
    if (!is_string($texto) || trim($texto) === '') {
        return [null, 'Escreva a mensagem ou escolha um modelo.'];
    }
    $texto = trim(str_replace("\r\n", "\n", $texto));
    if (mb_strlen($texto) > MENSAGEM_MANUAL_MAXIMO) {
        return [null, 'A mensagem passa de ' . MENSAGEM_MANUAL_MAXIMO . ' caracteres.'];
    }
    return [$texto, null];
}

/** Os destinos pedidos ([{id_responsavel, canal}]), sem repetidos: [destinos, erro]. */
function lerDestinos($destinos): array {
    if (!is_array($destinos) || $destinos === []) {
        return [[], 'Escolha pelo menos um responsável e um canal.'];
    }
    $lidos = [];
    foreach ($destinos as $destino) {
        $id = is_array($destino) ? ($destino['id_responsavel'] ?? null) : null;
        $canal = is_array($destino) ? ($destino['canal'] ?? null) : null;
        if (!inteiroValido($id) || !in_array($canal, ['whatsapp', 'email'], true)) {
            return [[], 'Destino inválido: escolha o responsável e o canal (WhatsApp ou e-mail).'];
        }
        $lidos["{$id}|{$canal}"] = ['id_responsavel' => (int) $id, 'canal' => $canal];
    }
    if (count($lidos) > DESTINOS_POR_ENVIO) {
        return [[], 'No máximo ' . DESTINOS_POR_ENVIO . ' destinos por envio.'];
    }
    return [array_values($lidos), null];
}

/** Chave do envio, gerada pela tela a cada mensagem nova (um UUID): 8 a 64 letras, números e hífens. */
function chaveDeEnvioValida($chave): bool {
    return is_string($chave) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $chave) === 1;
}

/** O contato que o canal usa, ou null quando falta (sem e-mail, telefone incompleto). */
function contatoDoCanal(array $responsavel, string $canal): ?string {
    if ($canal === 'whatsapp') {
        return telefoneParaWhatsapp($responsavel['telefone_responsavel'] ?? null);
    }
    $email = trim((string) ($responsavel['email_responsavel'] ?? ''));
    return $email !== '' ? $email : null;
}

/** Há envio automático configurado para cada canal? Sem ele, WhatsApp vira link e e-mail fica na fila. */
function provedoresConfigurados(): array {
    return [
        'whatsapp' => trim((string) getenv('WHATSAPP_API_URL')) !== '' && trim((string) getenv('WHATSAPP_TOKEN')) !== '',
        'email' => emailConfigurado(),
    ];
}

/**
 * A prévia (e o que o envio vai gravar), sem banco: para cada destino, a
 * mensagem montada para aquele responsável, o contato e como ela sai -
 * 'automatico' (pelo provedor), 'link' (WhatsApp sem provedor: a
 * coordenação manda pelo link) ou 'fila' (e-mail sem provedor).
 *
 * @param array $aluno       ['nome', 'turma']
 * @param array $responsaveis id => ['nome_responsavel', 'telefone_responsavel', 'email_responsavel', 'parentesco'] (só os do aluno)
 * @return array{envios: array, erros: string[]}
 */
function montarEnvios(array $aluno, array $responsaveis, array $destinos, string $texto, string $escola, string $hojeLocal, array $provedores): array {
    $envios = [];
    $erros = [];
    foreach ($destinos as ['id_responsavel' => $id, 'canal' => $canal]) {
        $responsavel = $responsaveis[$id] ?? null;
        if ($responsavel === null) {
            $erros[] = 'Um dos responsáveis escolhidos não é deste aluno.';
            continue;
        }
        $contato = contatoDoCanal($responsavel, $canal);
        if ($contato === null) {
            $erros[] = $canal === 'whatsapp'
                ? "{$responsavel['nome_responsavel']} não tem telefone com DDD para WhatsApp."
                : "{$responsavel['nome_responsavel']} não tem e-mail cadastrado.";
            continue;
        }
        $envios[] = [
            'id_responsavel' => $id,
            'nome' => $responsavel['nome_responsavel'],
            'parentesco' => $responsavel['parentesco'] ?? null,
            'canal' => $canal,
            'contato' => $contato,
            'assunto' => $canal === 'email' ? mb_substr("{$escola}: mensagem sobre {$aluno['nome']}", 0, 150) : null,
            'mensagem' => montarMensagem($texto, [
                'responsavel' => primeiroNomeDoResponsavel($responsavel['nome_responsavel']),
                'aluno' => $aluno['nome'],
                'turma' => $aluno['turma'],
                'escola' => $escola,
                'data' => dataBr($hojeLocal),
            ]),
            'como' => $provedores[$canal] ? 'automatico' : ($canal === 'whatsapp' ? 'link' : 'fila'),
        ];
    }
    if ($envios === [] && $erros === []) {
        $erros[] = 'Escolha pelo menos um responsável e um canal.';
    }
    return ['envios' => $envios, 'erros' => array_values(array_unique($erros))];
}

/** "Mensagem enviada a 2 contatos; 1 ficou com o link do WhatsApp para você mandar." */
function mensagemDoEnvio(array $resumo): string {
    $partes = [];
    if ($resumo['enviadas'] > 0) {
        $partes[] = $resumo['enviadas'] === 1 ? '1 enviada' : "{$resumo['enviadas']} enviadas";
    }
    if ($resumo['pendentes'] > 0) {
        $partes[] = $resumo['pendentes'] === 1 ? '1 para mandar pelo link (sem envio automático)' : "{$resumo['pendentes']} para mandar pelo link (sem envio automático)";
    }
    if ($resumo['erros'] > 0) {
        $partes[] = $resumo['erros'] === 1 ? '1 com erro no envio' : "{$resumo['erros']} com erro no envio";
    }
    $texto = $partes === [] ? 'Nada foi enviado.' : 'Mensagem: ' . implode(', ', $partes) . '.';
    if ($resumo['repetidas'] > 0) {
        $texto .= ' Este envio já tinha sido feito: nada foi mandado de novo.';
    }
    return $texto;
}

// ---------------------------------------------------------------- banco

/** O aluno (da escola), a turma e os responsáveis dele: null se não for da escola. */
function alunoParaMensagem(PDO $conexao, int $idAluno, int $idEscola): ?array {
    $busca = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, s.nome_sala
        FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE a.id_aluno = ? AND s.id_escola = ?
    ");
    $busca->execute([$idAluno, $idEscola]);
    $aluno = $busca->fetch(PDO::FETCH_ASSOC);
    if (!$aluno) {
        return null;
    }
    $busca = $conexao->prepare("
        SELECT r.id_responsavel, r.nome_responsavel, r.telefone_responsavel, r.email_responsavel, r.canal_preferido, ar.parentesco
        FROM tb_aluno_responsavel ar JOIN tb_responsavel r ON r.id_responsavel = ar.id_responsavel
        WHERE ar.id_aluno = ?
        ORDER BY r.nome_responsavel, r.id_responsavel
    ");
    $busca->execute([$idAluno]);
    $responsaveis = [];
    foreach ($busca->fetchAll(PDO::FETCH_ASSOC) as $responsavel) {
        $responsaveis[(int) $responsavel['id_responsavel']] = $responsavel;
    }
    return ['id' => (int) $aluno['id_aluno'], 'nome' => $aluno['nome_aluno'], 'turma' => $aluno['nome_sala'], 'responsaveis' => $responsaveis];
}

/**
 * Grava e entrega as mensagens (a caixa de saída): as linhas entram antes,
 * com a chave do envio; as que já existiam com a mesma chave não saem de
 * novo. Devolve o resultado de cada destino e o resumo.
 */
function registrarEEntregar(PDO $conexao, int $idAluno, int $idCoordenacao, string $chave, array $envios, array $responsaveis): array {
    $insert = $conexao->prepare("
        INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao, canal, tipo_aviso, assunto, id_coordenacao, chave_envio, criado_em)
        VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, ?, NOW())
        ON CONFLICT (chave_envio, id_responsavel, canal) WHERE chave_envio IS NOT NULL DO NOTHING
        RETURNING id_notificacao
    ");
    $existente = $conexao->prepare("
        SELECT id_notificacao, status_notificacao, mensagem FROM tb_notificacao
        WHERE chave_envio = ? AND id_responsavel = ? AND canal = ?
    ");
    $conexao->beginTransaction();
    try {
        $linhas = [];
        foreach ($envios as $envio) {
            $insert->execute([
                $idAluno, $envio['id_responsavel'], $envio['mensagem'], NOTIFICACAO_PENDENTE, $envio['canal'],
                $envio['assunto'], $idCoordenacao, $chave,
            ]);
            $id = $insert->fetchColumn();
            if ($id !== false) {
                $linhas[] = $envio + ['id_notificacao' => (int) $id, 'repetido' => false];
                continue;
            }
            $existente->execute([$chave, $envio['id_responsavel'], $envio['canal']]);
            $anterior = $existente->fetch(PDO::FETCH_ASSOC);
            $linhas[] = $envio + [
                'id_notificacao' => (int) $anterior['id_notificacao'],
                'repetido' => true,
                'status' => statusNotificacaoParaApi($anterior['status_notificacao']),
                'conteudo' => $anterior['mensagem'],
            ];
        }
        $conexao->commit();
    } catch (PDOException $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        throw $erro;
    }

    // Só agora sai: se o servidor cair no meio, a linha fica 'pendente' com a
    // chave e a nova tentativa não manda de novo.
    $atualiza = $conexao->prepare("
        UPDATE tb_notificacao SET status_notificacao = ?, mensagem = ?, data_envio_notificacao = ? WHERE id_notificacao = ?
    ");
    $resumo = ['enviadas' => 0, 'pendentes' => 0, 'erros' => 0, 'repetidas' => 0];
    $resultados = [];
    foreach ($linhas as $linha) {
        if (!$linha['repetido']) {
            [$resultado, $conteudo] = entregarMensagem(
                $linha['canal'], $responsaveis[$linha['id_responsavel']], (string) $linha['assunto'], $linha['mensagem'],
                'biofrequencia-notificacao-' . $linha['id_notificacao']
            );
            $statusBanco = statusPorResultadoEnvio($resultado);
            $atualiza->execute([$statusBanco, $conteudo, $resultado === ENVIO_OK ? agoraUtc()->format('Y-m-d H:i:s') : null, $linha['id_notificacao']]);
            $linha['status'] = statusNotificacaoParaApi($statusBanco);
            $linha['conteudo'] = $conteudo;
        } else {
            $resumo['repetidas']++;
        }
        match ($linha['status']) {
            'enviada' => $resumo['enviadas']++,
            'erro' => $resumo['erros']++,
            default => $resumo['pendentes']++,
        };
        $resultados[] = [
            'id_notificacao' => $linha['id_notificacao'],
            'id_responsavel' => $linha['id_responsavel'],
            'nome' => $linha['nome'],
            'canal' => $linha['canal'],
            'contato' => $linha['contato'],
            'status' => $linha['status'],
            'repetido' => $linha['repetido'],
            // WhatsApp sem envio automático: o link pronto para a coordenação mandar.
            'link' => str_starts_with((string) $linha['conteudo'], 'https://wa.me/') ? $linha['conteudo'] : null,
        ];
    }
    return ['resultados' => $resultados, 'resumo' => $resumo];
}
