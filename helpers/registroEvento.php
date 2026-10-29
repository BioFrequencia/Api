<?php
require_once __DIR__ . '/tempo.php';

/**
 * Lançamento de falta/atraso, compartilhado por user/marcarFalta.php,
 * user/marcarAtraso.php e ocorrencia/revisarOcorrencia.php (aprovação de
 * um envio do app) - os três gravam nas mesmas tabelas e precisam da
 * mesma regra:
 *
 *  - Um aluno tem no máximo UMA falta e UM atraso por dia (no fuso da
 *    escola). Antes não havia trava nenhuma: clique duplo no botão,
 *    lançamento manual + aprovação do app, ou duas pessoas lançando ao
 *    mesmo tempo gravavam duas linhas, e a frequência do mês caía duas
 *    vezes pelo mesmo dia.
 *  - A trava usa pg_advisory_xact_lock por aluno: duas requisições
 *    simultâneas para o mesmo aluno esperam uma pela outra, e a segunda
 *    enxerga a linha que a primeira gravou. É liberada sozinha no fim da
 *    transação (funciona com o pooler do Supabase em modo Transaction).
 *
 * Precisa ser chamada DENTRO de uma transação (beginTransaction).
 */

const EVENTOS_FREQUENCIA = [
    'falta' => [
        'tabela' => 'tb_falta', 'id' => 'id_falta', 'data' => 'dta_falta',
        'motivo' => 'motivo_falta', 'justificado' => 'justificado_falta',
        'observacao' => 'observacao_falta', 'trava' => 7301,
    ],
    'atraso' => [
        'tabela' => 'tb_atraso', 'id' => 'id_atraso', 'data' => 'dta_atraso',
        'motivo' => 'motivo_atraso', 'justificado' => 'justificado_atraso',
        'observacao' => 'observacao_atraso', 'trava' => 7302,
    ],
];

/**
 * Lê a data/hora opcional enviada pelo cliente (horário LOCAL da escola:
 * 'Y-m-d', 'Y-m-d H:i' ou 'Y-m-d H:i:s') e devolve em UTC, ou null para
 * "agora". Encerra com 422 se vier num formato inválido ou no futuro -
 * antes qualquer texto ia direto para o banco e virava erro 500.
 */
function lerMomentoDoEvento($valor): ?string {
    if ($valor === null || $valor === '') {
        return null;
    }
    $formatos = ['!Y-m-d', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i', '!Y-m-d\TH:i:s'];
    $momento = false;
    foreach ($formatos as $formato) {
        $momento = is_string($valor) ? DateTimeImmutable::createFromFormat($formato, $valor, fusoEscola()) : false;
        if ($momento !== false && $momento->format(substr($formato, 1)) === $valor) {
            break;
        }
        $momento = false;
    }
    if ($momento === false) {
        enviarErro("Data inválida. Use o formato AAAA-MM-DD (opcionalmente com HH:MM).", 422);
    }
    if (strlen($valor) === 10) {
        // Só a data: meio-dia local, longe da virada do dia em qualquer fuso.
        $momento = $momento->setTime(12, 0);
    }
    if ($momento > agoraUtc()->modify('+5 minutes')) {
        enviarErro("Não é possível lançar uma ocorrência no futuro.", 422);
    }
    return $momento->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Grava a falta/atraso, ou devolve a que já existe no mesmo dia.
 *
 * @return array{id:int, data:string, ja_existia:bool}  data em UTC
 */
function registrarEventoFrequencia(
    PDO $conexao,
    string $tipo,
    int $idAluno,
    int $idCoordenacao,
    ?string $momentoUtc,
    ?string $motivo,
    ?string $observacao,
    bool $justificado
): array {
    $c = EVENTOS_FREQUENCIA[$tipo];
    $momentoUtc = $momentoUtc ?? agoraUtc()->format('Y-m-d H:i:s');

    $trava = $conexao->prepare("SELECT pg_advisory_xact_lock(?, ?)");
    $trava->execute([$c['trava'], $idAluno]);

    $existente = $conexao->prepare("
        SELECT {$c['id']} AS id, {$c['data']} AS data
        FROM {$c['tabela']}
        WHERE id_aluno = ? AND " . sqlDataNaEscola($c['data']) . " = ?::date
        ORDER BY {$c['id']}
        LIMIT 1
    ");
    $existente->execute([$idAluno, dataNaEscola($momentoUtc)]);
    $linha = $existente->fetch(PDO::FETCH_ASSOC);
    if ($linha) {
        return ['id' => (int) $linha['id'], 'data' => $linha['data'], 'ja_existia' => true];
    }

    // justificado vai como texto 'true'/'false': PDO::PARAM_BOOL tem um
    // bug conhecido no driver pgsql que costuma virar '' e quebrar o
    // insert numa coluna boolean.
    $insert = $conexao->prepare("
        INSERT INTO {$c['tabela']} (id_aluno, id_coordenacao, {$c['data']}, {$c['motivo']}, {$c['justificado']}, {$c['observacao']})
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING {$c['id']} AS id, {$c['data']} AS data
    ");
    $insert->execute([
        $idAluno,
        $idCoordenacao,
        $momentoUtc,
        $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null,
        $justificado ? 'true' : 'false',
        $observacao !== null && trim($observacao) !== '' ? trim($observacao) : null,
    ]);
    $novo = $insert->fetch(PDO::FETCH_ASSOC);
    return ['id' => (int) $novo['id'], 'data' => $novo['data'], 'ja_existia' => false];
}
