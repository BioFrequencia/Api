<?php
/**
 * Importação offline de entradas atrasadas e saídas antecipadas: sem
 * internet, a coordenação anota no Excel (modelo de
 * registro/modeloImportacao.php); quando a conexão volta, importa a
 * planilha (registro/previaImportacao.php e confirmarImportacao.php).
 *
 * Funções puras (sem banco), testadas em tests/helpers_test.php. O banco
 * fica em helpers/importacaoOcorrenciasEscola.php e o arquivo em
 * helpers/planilha.php.
 *
 * As regras são as do lançamento manual (user/marcarAtraso.php e
 * marcarSaida.php): dia de aula, dentro do horário da turma, entrada
 * atrasada só depois da tolerância e uma de cada tipo por aluno por dia.
 * O banco não tem matrícula: o aluno é achado pelo código (id) que o
 * modelo traz na aba Alunos ou, sem código, pelo nome (e a turma).
 */

require_once __DIR__ . '/importacao.php';
require_once __DIR__ . '/calendario.php';

const IMPORTACAO_MAX_OCORRENCIAS = 3000;

/**
 * Nomes aceitos para cada coluna (depois de normalizarTexto). O primeiro
 * é o nome do modelo, usado nas mensagens - por isso pode ter acento.
 */
const COLUNAS_OCORRENCIAS = [
    'codigo' => ['Código do aluno', 'codigo do aluno', 'codigo', 'cod', 'matricula', 'ra', 'id do aluno', 'id', 'identificador'],
    'nome' => ['Nome do aluno', 'nome do aluno', 'aluno', 'nome', 'nome completo', 'estudante'],
    'turma' => ['Turma', 'turma', 'sala', 'turma/sala'],
    'data' => ['Data', 'data', 'data da ocorrencia', 'dia'],
    'horario' => ['Horário', 'horario', 'hora', 'horario da ocorrencia'],
    'tipo' => ['Tipo', 'tipo', 'tipo de ocorrencia', 'ocorrencia'],
    'motivo' => ['Motivo / justificativa', 'motivo / justificativa', 'motivo/justificativa', 'motivo', 'justificativa'],
    'justificada' => ['Justificada?', 'justificada?', 'justificada', 'justificado?', 'justificado'],
    'observacao' => ['Observação', 'observacao', 'observacoes', 'obs', 'obs.'],
    'retirado_por' => ['Quem buscou (saída)', 'quem buscou (saida)', 'quem buscou', 'retirado por', 'quem retirou'],
];
const COLUNAS_OBRIGATORIAS_OCORRENCIAS = ['data', 'horario', 'tipo'];

const NOMES_TIPO_OCORRENCIA = ['atraso' => 'entrada atrasada', 'saida' => 'saída antecipada'];

/** "Entrada atrasada", "atraso", "chegou atrasado" => 'atraso'; "Saída antecipada", "saída" => 'saida'; senão null. */
function lerTipoDeOcorrencia($valor): ?string {
    $texto = normalizarTexto(textoDaCelula($valor));
    if ($texto === '') return null;
    if (str_contains($texto, 'atras') || in_array($texto, ['entrada', 'chegada', 'e', 'a'], true)) return 'atraso';
    if (str_contains($texto, 'saida') || str_contains($texto, 'antecipad') || in_array($texto, ['saiu', 's'], true)) return 'saida';
    return null;
}

/** Coluna "Justificada?": em branco = não; null quando não dá para entender. */
function lerSimNao($valor): ?bool {
    if (is_bool($valor)) return $valor;
    $texto = normalizarTexto(textoDaCelula($valor));
    if ($texto === '') return false;
    if (in_array($texto, ['sim', 's', 'x', 'yes', 'y', 'true', 'verdadeiro', '1', 'justificada', 'justificado'], true)) return true;
    if (in_array($texto, ['nao', 'n', 'no', 'false', 'falso', '0'], true)) return false;
    return null;
}

/** Código do aluno (o número da aba Alunos): 12, "12", "#12" => 12; vazio => null; outra coisa => false. */
function lerCodigoDoAluno($valor): int|false|null {
    $texto = textoDaCelula($valor);
    if ($texto === '') return null;
    if (preg_match('/^#?\s*(\d{1,9})$/', $texto, $partes) && (int) $partes[1] > 0) return (int) $partes[1];
    return false;
}

/** A explicação de conferirHorarioDoRegistro() no tom das outras da prévia: minúscula, sem o ponto final. */
function fraseDaPrevia(string $frase): string {
    return mb_strtolower(mb_substr($frase, 0, 1)) . rtrim(mb_substr($frase, 1), '.');
}

/**
 * Confere o formato de uma linha da aba Ocorrências, sem banco: devolve os
 * valores arrumados, os erros e os avisos. Quem é o aluno, o horário da
 * turma e os repetidos ficam para montarPreviaDeOcorrencias().
 */
function conferirLinhaDeOcorrencia(array $valores): array {
    $erros = [];
    $avisos = [];

    $codigo = lerCodigoDoAluno($valores['codigo'] ?? null);
    $nome = textoDaCelula($valores['nome'] ?? null);
    $turma = textoDaCelula($valores['turma'] ?? null);
    if ($codigo === false) {
        $erros[] = 'código do aluno inválido (use o número da aba Alunos do modelo)';
        $codigo = null;
    } elseif ($codigo === null && $nome === '') {
        $erros[] = 'informe o código ou o nome do aluno';
    }

    $data = null;
    if (textoDaCelula($valores['data'] ?? null) === '') {
        $erros[] = 'falta a data';
    } else {
        $data = lerDataDaCelula($valores['data'], true);
        if ($data === null) $erros[] = 'data inválida (use dd/mm/aaaa)';
    }

    [$hora, $erroHora] = lerHora($valores['horario'] ?? null);
    if ($erroHora !== null) {
        $erros[] = $erroHora;
    } elseif ($hora === null) {
        $erros[] = 'falta o horário';
    }

    $tipo = null;
    $textoTipo = textoDaCelula($valores['tipo'] ?? null);
    if ($textoTipo === '') {
        $erros[] = 'falta o tipo (Entrada atrasada ou Saída antecipada)';
    } else {
        $tipo = lerTipoDeOcorrencia($textoTipo);
        if ($tipo === null) $erros[] = "tipo \"{$textoTipo}\" desconhecido (use Entrada atrasada ou Saída antecipada)";
    }

    $motivo = textoDaCelula($valores['motivo'] ?? null);
    if (mb_strlen($motivo) > 150) $erros[] = 'o motivo passa de 150 caracteres';

    $justificado = lerSimNao($valores['justificada'] ?? null);
    if ($justificado === null) {
        $erros[] = 'em "Justificada?" use Sim ou Não';
        $justificado = false;
    }

    // A observação pode ter mais de uma linha (Alt+Enter no Excel).
    $observacao = $valores['observacao'] ?? null;
    $observacao = is_string($observacao) ? trim(str_replace("\r\n", "\n", $observacao)) : textoDaCelula($observacao);

    $retiradoPor = textoDaCelula($valores['retirado_por'] ?? null);
    if (mb_strlen($retiradoPor) > 150) $erros[] = 'o nome de quem buscou passa de 150 caracteres';
    if ($retiradoPor !== '' && $tipo === 'atraso') {
        $avisos[] = '"Quem buscou" só vale para saída antecipada: fica de fora';
        $retiradoPor = '';
    }

    return [
        'codigo' => $codigo,
        'nome' => $nome,
        'turma' => $turma,
        'data' => $data,
        'hora' => $hora,
        'tipo' => $tipo,
        'motivo' => $motivo === '' ? null : $motivo,
        'justificado' => $justificado,
        'observacao' => $observacao === '' ? null : $observacao,
        'retirado_por' => $retiradoPor === '' ? null : $retiradoPor,
        'erros' => $erros,
        'avisos' => $avisos,
    ];
}

/**
 * A prévia: cada linha vira 'nova', 'existente' (já está no sistema: fica
 * como está) ou 'erro' (com o motivo) - nada é gravado aqui.
 *
 * $escola (helpers/importacaoOcorrenciasEscola.php):
 *   'alunos'     => [id => ['nome', 'turma', 'periodo', 'inicio', 'fim']]
 *   'registros'  => ['atraso|12|2026-11-05' => '07:42', ...]  o que já está gravado
 *   'calendario' => itens de tb_calendario_escola no intervalo das datas
 *   'tolerancia' => minutos
 * $agoraLocal: 'Y-m-d H:i' no fuso da escola (nada pode ser no futuro).
 */
function montarPreviaDeOcorrencias(array $linhas, array $escola, string $agoraLocal): array {
    $porNome = [];
    foreach ($escola['alunos'] as $id => $aluno) {
        $porNome[chaveDeNome($aluno['nome'])][] = $id;
    }
    [$hoje, $agora] = explode(' ', $agoraLocal);

    $vistas = [];
    $saida = [];
    foreach ($linhas as [$numero, $valores]) {
        $linha = conferirLinhaDeOcorrencia($valores);
        $erros = $linha['erros'];
        $avisos = $linha['avisos'];

        // Quem é o aluno: pelo código; sem código, pelo nome (e pela turma, se veio).
        $idAluno = null;
        if ($linha['codigo'] !== null) {
            if (!isset($escola['alunos'][$linha['codigo']])) {
                $erros[] = "não há aluno com o código {$linha['codigo']} nesta escola (veja a aba Alunos do modelo)";
            } elseif ($linha['nome'] !== '' && chaveDeNome($linha['nome']) !== chaveDeNome($escola['alunos'][$linha['codigo']]['nome'])) {
                $erros[] = "o código {$linha['codigo']} é de {$escola['alunos'][$linha['codigo']]['nome']}, não de {$linha['nome']}";
            } else {
                $idAluno = $linha['codigo'];
            }
        } elseif ($linha['nome'] !== '') {
            $ids = $porNome[chaveDeNome($linha['nome'])] ?? [];
            if (count($ids) > 1 && $linha['turma'] !== '') {
                $ids = array_values(array_filter($ids, fn($id) => chaveDeNome($escola['alunos'][$id]['turma']) === chaveDeNome($linha['turma'])));
            }
            if ($ids === []) {
                $erros[] = "aluno \"{$linha['nome']}\" não encontrado nesta escola";
            } elseif (count($ids) > 1) {
                $erros[] = "há mais de um aluno \"{$linha['nome']}\": informe o código (aba Alunos do modelo)";
            } else {
                $idAluno = $ids[0];
            }
        }
        $aluno = $idAluno !== null ? $escola['alunos'][$idAluno] : null;
        if ($aluno !== null && $linha['turma'] !== '' && chaveDeNome($linha['turma']) !== chaveDeNome($aluno['turma'])) {
            $erros[] = "{$aluno['nome']} é da turma {$aluno['turma']}, não da {$linha['turma']}";
        }

        // Dia e horário: as mesmas regras do lançamento manual.
        if ($aluno !== null && $linha['data'] !== null && $linha['hora'] !== null && $linha['tipo'] !== null) {
            if ($linha['data'] > $hoje || ($linha['data'] === $hoje && $linha['hora'] > $agora)) {
                $erros[] = 'data e horário no futuro';
            } else {
                $erroHorario = conferirHorarioDoRegistro(
                    $linha['tipo'], $linha['data'], $linha['hora'],
                    situacaoDoDia($linha['data'], $escola['calendario']),
                    horarioDaTurma($aluno['periodo'], $aluno['inicio'], $aluno['fim']),
                    $escola['tolerancia'], $aluno['turma']
                );
                if ($erroHorario !== null) $erros[] = fraseDaPrevia($erroHorario);
            }
        }

        // Repetidos: uma de cada tipo por aluno por dia, no arquivo e no sistema.
        $situacao = $erros === [] ? 'nova' : 'erro';
        if ($situacao === 'nova') {
            $chave = "{$linha['tipo']}|{$idAluno}|{$linha['data']}";
            $nomeDoTipo = NOMES_TIPO_OCORRENCIA[$linha['tipo']];
            if (isset($vistas[$chave])) {
                $erros[] = "repetida: a linha {$vistas[$chave]} já tem {$nomeDoTipo} deste aluno nesse dia";
                $situacao = 'erro';
            } else {
                $vistas[$chave] = $numero;
                if (isset($escola['registros'][$chave])) {
                    $situacao = 'existente';
                    $avisos[] = "já está registrada no sistema (às {$escola['registros'][$chave]}): fica como está";
                }
            }
        }

        $saida[] = [
            'linha' => $numero,
            'situacao' => $situacao,
            'id_aluno' => $idAluno,
            'aluno' => $aluno['nome'] ?? $linha['nome'],
            'turma' => $aluno['turma'] ?? $linha['turma'],
            'data' => $linha['data'],
            'hora' => $linha['hora'],
            'tipo' => $linha['tipo'],
            'motivo' => $linha['motivo'],
            'justificado' => $linha['justificado'],
            'observacao' => $linha['observacao'],
            'retirado_por' => $linha['retirado_por'],
            'erros' => $erros,
            'avisos' => $avisos,
        ];
    }

    $novas = array_filter($saida, fn($l) => $l['situacao'] === 'nova');
    return [
        'linhas' => $saida,
        'resumo' => [
            'novas' => count($novas),
            'existentes' => count(array_filter($saida, fn($l) => $l['situacao'] === 'existente')),
            'com_erro' => count(array_filter($saida, fn($l) => $l['situacao'] === 'erro')),
            'atrasos_novos' => count(array_filter($novas, fn($l) => $l['tipo'] === 'atraso')),
            'saidas_novas' => count(array_filter($novas, fn($l) => $l['tipo'] === 'saida')),
        ],
    ];
}

/**
 * A frase do fim da importação, com plural e concordância:
 * "Importação concluída: 3 entradas atrasadas e 1 saída antecipada registradas."
 */
function mensagemDaImportacaoDeOcorrencias(int $atrasos, int $saidas): string {
    $partes = [];
    if ($atrasos > 0) $partes[] = $atrasos . ($atrasos === 1 ? ' entrada atrasada' : ' entradas atrasadas');
    if ($saidas > 0) $partes[] = $saidas . ($saidas === 1 ? ' saída antecipada' : ' saídas antecipadas');
    if ($partes === []) {
        return 'Importação concluída: nada novo foi registrado.';
    }
    return 'Importação concluída: ' . implode(' e ', $partes) . ' registrada' . ($atrasos + $saidas === 1 ? '' : 's') . '.';
}
