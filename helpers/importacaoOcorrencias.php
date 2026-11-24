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
 *
 * A entrada atrasada ou saída antecipada é opcional: uma linha só com o
 * nome e a turma de um aluno que a escola ainda não tem cadastra o aluno
 * (e a turma, se for nova), sem data de nascimento e com gênero
 * "Indefinido" - dá para completar depois pela importação de Turmas e
 * alunos. Aluno que já existe, numa linha sem entrada/saída, fica como está.
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
    'periodo' => ['Período', 'periodo', 'turno'],
];

/**
 * Uma aba vale se o cabeçalho identifica o aluno: pela coluna do nome ou
 * pela do código (a aba Alunos do modelo também vale). Data, horário e
 * tipo não são obrigatórios: sem eles, a linha só cadastra o aluno.
 */
const PERFIS_ABA_OCORRENCIAS = ['por_nome' => ['nome'], 'por_codigo' => ['codigo']];

/** Campos da entrada atrasada / saída antecipada: algum preenchido = a linha registra uma. */
const CAMPOS_DA_OCORRENCIA = ['data', 'horario', 'tipo', 'motivo', 'justificada', 'observacao', 'retirado_por'];

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

/**
 * Um aluno da turma com o nome quase igual (até 2 letras de diferença),
 * para um erro de digitação não virar um aluno novo. null se não houver.
 */
function nomeParecidoNaTurma(string $nome, array $nomesDaTurma): ?string {
    $chave = chaveDeNome($nome);
    if (mb_strlen($chave) < 6) return null;
    foreach ($nomesDaTurma as $outro) {
        $chaveOutro = chaveDeNome($outro);
        if ($chaveOutro !== $chave && levenshtein($chave, $chaveOutro) <= 2) return $outro;
    }
    return null;
}

/** A explicação de conferirHorarioDoRegistro() no tom das outras da prévia: minúscula, sem o ponto final. */
function fraseDaPrevia(string $frase): string {
    return mb_strtolower(mb_substr($frase, 0, 1)) . rtrim(mb_substr($frase, 1), '.');
}

/**
 * Confere o formato de uma linha da aba Ocorrências, sem banco: devolve os
 * valores arrumados, os erros e os avisos. Quem é o aluno, o horário da
 * turma e os repetidos ficam para montarPreviaDeOcorrencias().
 *
 * 'ocorrencia' diz se a linha registra uma entrada atrasada ou saída
 * antecipada: com algum campo dela preenchido, data, horário e tipo passam
 * a ser obrigatórios; com todos em branco, a linha só identifica o aluno.
 */
function conferirLinhaDeOcorrencia(array $valores): array {
    $erros = [];
    $avisos = [];
    $temOcorrencia = false;
    foreach (CAMPOS_DA_OCORRENCIA as $campo) {
        if (textoDaCelula($valores[$campo] ?? null) !== '') {
            $temOcorrencia = true;
            break;
        }
    }

    $codigo = lerCodigoDoAluno($valores['codigo'] ?? null);
    $nome = textoDaCelula($valores['nome'] ?? null);
    $turma = textoDaCelula($valores['turma'] ?? null);
    if ($codigo === false) {
        $erros[] = 'código do aluno inválido (use o número da aba Alunos do modelo)';
        $codigo = null;
    } elseif ($codigo === null && $nome === '') {
        $erros[] = 'informe o código ou o nome do aluno';
    }

    $textoPeriodo = textoDaCelula($valores['periodo'] ?? null);
    $periodo = $textoPeriodo === '' ? null : lerPeriodo($textoPeriodo);
    if ($textoPeriodo !== '' && $periodo === null) {
        $avisos[] = "período \"{$textoPeriodo}\" desconhecido: só vale para turma nova, que fica com o período indefinido";
    }

    $data = null;
    if (textoDaCelula($valores['data'] ?? null) === '') {
        if ($temOcorrencia) $erros[] = 'falta a data';
    } else {
        $data = lerDataDaCelula($valores['data'], true);
        if ($data === null) $erros[] = 'data inválida (use dd/mm/aaaa)';
    }

    [$hora, $erroHora] = lerHora($valores['horario'] ?? null);
    if ($erroHora !== null) {
        $erros[] = $erroHora;
    } elseif ($hora === null && $temOcorrencia) {
        $erros[] = 'falta o horário';
    }

    $tipo = null;
    $textoTipo = textoDaCelula($valores['tipo'] ?? null);
    if ($textoTipo === '') {
        if ($temOcorrencia) $erros[] = 'falta o tipo (Entrada atrasada ou Saída antecipada)';
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
        'ocorrencia' => $temOcorrencia,
        'codigo' => $codigo,
        'nome' => $nome,
        'turma' => $turma,
        'periodo' => $periodo,
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
 * A prévia: cada linha vira 'nova' (registra a entrada/saída), 'cadastro'
 * (só cadastra um aluno que a escola não tem), 'existente' (a entrada/saída
 * já está no sistema: fica como está) ou 'erro' (com o motivo) - nada é
 * gravado aqui. Linha sem entrada/saída de um aluno que já existe não
 * aparece: só conta em resumo.ja_cadastrados.
 *
 * Aluno que a escola não tem, numa linha com o nome e a turma, vira aluno
 * novo (e a turma também, se for nova). Para não virar cadastro repetido:
 * nome que já existe em outra turma é erro, e nome quase igual ao de um
 * aluno da mesma turma (erro de digitação) também.
 *
 * $linhas: [[número da linha, valores, nome da aba (opcional)], ...]
 * $escola (helpers/importacaoOcorrenciasEscola.php):
 *   'alunos'     => [id => ['nome', 'turma', 'periodo', 'inicio', 'fim']]
 *   'turmas'     => [chaveDeNome => ['id_sala', 'nome', 'periodo', 'inicio', 'fim']]  (também as sem aluno)
 *   'registros'  => ['atraso|12|2026-11-05' => '07:42', ...]  o que já está gravado
 *   'calendario' => itens de tb_calendario_escola no intervalo das datas
 *   'tolerancia' => minutos
 * $agoraLocal: 'Y-m-d H:i' no fuso da escola (nada pode ser no futuro).
 */
function montarPreviaDeOcorrencias(array $linhas, array $escola, string $agoraLocal): array {
    $porNome = [];
    $nomesPorTurma = [];
    foreach ($escola['alunos'] as $id => $aluno) {
        $porNome[chaveDeNome($aluno['nome'])][] = $id;
        $nomesPorTurma[chaveCompactaDeTurma($aluno['turma'])][] = $aluno['nome'];
    }
    // Turmas da escola, também as sem aluno (e, se a lista não veio, as dos alunos).
    $turmas = $escola['turmas'] ?? [];
    foreach ($escola['alunos'] as $aluno) {
        $turmas[chaveDeNome($aluno['turma'])] ??= [
            'id_sala' => null, 'nome' => $aluno['turma'], 'periodo' => $aluno['periodo'], 'inicio' => $aluno['inicio'], 'fim' => $aluno['fim'],
        ];
    }
    $turmaPorCompacta = [];
    foreach ($turmas as $chave => $turma) {
        $turmaPorCompacta[chaveCompactaDeTurma($turma['nome'])] ??= $chave;
    }
    [$hoje, $agora] = explode(' ', $agoraLocal);
    $variasAbas = count(array_unique(array_map(fn($l) => (string) ($l[2] ?? ''), $linhas))) > 1;
    // Período de cada turma nova: o da primeira linha que informar (em qualquer aba).
    $periodoDaTurmaNova = [];
    foreach ($linhas as $item) {
        $turmaDaLinha = textoDaCelula($item[1]['turma'] ?? null);
        $textoPeriodo = textoDaCelula($item[1]['periodo'] ?? null);
        if ($turmaDaLinha === '' || $textoPeriodo === '' || lerPeriodo($textoPeriodo) === null) continue;
        $periodoDaTurmaNova[chaveDeNome($turmaDaLinha)] ??= lerPeriodo($textoPeriodo);
    }
    $rotuloDaLinha = fn(int $numero, ?string $aba) => $variasAbas && $aba !== null ? "{$numero} da aba {$aba}" : (string) $numero;

    $vistas = [];
    $saida = [];
    $alunosNovos = [];      // chave do aluno novo => ['nome', 'turma' (chave), 'linha']
    $turmasNovas = [];      // chave da turma nova => ['nome', 'periodo', 'linha']
    $cadastroListado = [];  // aluno novo que já tem uma linha 'cadastro'
    $jaCadastrados = 0;
    foreach ($linhas as $item) {
        [$numero, $valores] = $item;
        $aba = $item[2] ?? null;
        $rotulo = $rotuloDaLinha($numero, $aba);
        $linha = conferirLinhaDeOcorrencia($valores);
        $erros = $linha['erros'];
        $avisos = $linha['avisos'];

        // A turma escrita na linha, se a escola já tem (4A acha "4º A").
        $chaveTurma = null;
        if ($linha['turma'] !== '') {
            $chaveTurma = isset($turmas[chaveDeNome($linha['turma'])]) ? chaveDeNome($linha['turma'])
                : ($turmaPorCompacta[chaveCompactaDeTurma($linha['turma'])] ?? null);
        }

        // Quem é o aluno: pelo código; sem código (ou com um código que não é
        // de aluno desta escola, mas com o nome), pelo nome e pela turma.
        $idAluno = null;
        $chaveNovo = null;
        $peloNome = $linha['codigo'] === null;
        if ($linha['codigo'] !== null) {
            $doCodigo = $escola['alunos'][$linha['codigo']] ?? null;
            if ($doCodigo === null && $linha['nome'] === '') {
                $erros[] = "não há aluno com o código {$linha['codigo']} nesta escola (veja a aba Alunos do modelo)";
            } elseif ($doCodigo === null) {
                $avisos[] = "o código {$linha['codigo']} não é de um aluno desta escola: valeu o nome";
                $peloNome = true;
            } elseif ($linha['nome'] !== '' && chaveDeNome($linha['nome']) !== chaveDeNome($doCodigo['nome'])) {
                $erros[] = "o código {$linha['codigo']} é de {$doCodigo['nome']}, não de {$linha['nome']}";
            } else {
                $idAluno = $linha['codigo'];
            }
        }
        if ($peloNome && $linha['nome'] !== '') {
            $ids = $porNome[chaveDeNome($linha['nome'])] ?? [];
            if (count($ids) > 1 && $linha['turma'] !== '') {
                $naTurma = array_values(array_filter(
                    $ids,
                    fn($id) => chaveCompactaDeTurma($escola['alunos'][$id]['turma']) === chaveCompactaDeTurma($linha['turma'])
                ));
                if ($naTurma !== []) $ids = $naTurma;
            }
            if (count($ids) === 1) {
                $idAluno = $ids[0];
            } elseif (count($ids) > 1) {
                $erros[] = "há mais de um aluno \"{$linha['nome']}\": informe o código (aba Alunos do modelo) ou a turma certa";
            } elseif ($linha['turma'] === '') {
                $erros[] = "aluno \"{$linha['nome']}\" não encontrado nesta escola: informe a turma para cadastrar";
            } else {
                $parecido = nomeParecidoNaTurma($linha['nome'], $nomesPorTurma[chaveCompactaDeTurma($linha['turma'])] ?? []);
                if ($parecido !== null) {
                    $erros[] = "\"{$linha['nome']}\" não está na turma {$linha['turma']}, mas lá há \"{$parecido}\": corrija o nome"
                        . ' (se for outro aluno, cadastre pela tela de Turmas e alunos)';
                } else {
                    if ($chaveTurma === null) {
                        $chaveTurma = chaveDeNome($linha['turma']);
                        $turmasNovas[$chaveTurma] ??= [
                            'nome' => $linha['turma'], 'periodo' => $periodoDaTurmaNova[$chaveTurma] ?? 'Indefinido', 'linha' => $rotulo,
                        ];
                        if ($linha['periodo'] !== null && $linha['periodo'] !== $turmasNovas[$chaveTurma]['periodo']) {
                            $avisos[] = "a turma nova {$turmasNovas[$chaveTurma]['nome']} fica com o período "
                                . mb_strtolower($turmasNovas[$chaveTurma]['periodo']) . ', o primeiro informado na planilha';
                        }
                    }
                    $chaveNovo = chaveDeNome($linha['nome']) . '|' . $chaveTurma;
                    $alunosNovos[$chaveNovo] ??= ['nome' => $linha['nome'], 'turma' => $chaveTurma, 'linha' => $rotulo];
                }
            }
        }
        $aluno = $idAluno !== null ? $escola['alunos'][$idAluno] : null;
        if ($aluno !== null && $linha['turma'] !== '' && chaveCompactaDeTurma($linha['turma']) !== chaveCompactaDeTurma($aluno['turma'])) {
            $erros[] = "{$aluno['nome']} é da turma {$aluno['turma']}, não da {$linha['turma']}";
        }

        // A turma do aluno novo: uma que a escola já tem ou a nova desta planilha.
        $turmaDoNovo = null;
        if ($chaveNovo !== null) {
            $chaveDaTurma = $alunosNovos[$chaveNovo]['turma'];
            $turmaDoNovo = isset($turmasNovas[$chaveDaTurma])
                ? ['nome' => $turmasNovas[$chaveDaTurma]['nome'], 'periodo' => $turmasNovas[$chaveDaTurma]['periodo'], 'inicio' => null, 'fim' => null, 'nova' => true]
                : $turmas[$chaveDaTurma] + ['nova' => false];
        }

        // Dia e horário: as mesmas regras do lançamento manual.
        $horario = null;
        if ($aluno !== null) {
            $horario = horarioDaTurma($aluno['periodo'], $aluno['inicio'], $aluno['fim']);
        } elseif ($turmaDoNovo !== null) {
            $horario = horarioDaTurma($turmaDoNovo['periodo'], $turmaDoNovo['inicio'], $turmaDoNovo['fim']);
        }
        if ($horario !== null && $linha['ocorrencia'] && $linha['data'] !== null && $linha['hora'] !== null && $linha['tipo'] !== null) {
            if ($linha['data'] > $hoje || ($linha['data'] === $hoje && $linha['hora'] > $agora)) {
                $erros[] = 'data e horário no futuro';
            } else {
                $erroHorario = conferirHorarioDoRegistro(
                    $linha['tipo'], $linha['data'], $linha['hora'],
                    situacaoDoDia($linha['data'], $escola['calendario']),
                    $horario, $escola['tolerancia'], $aluno['turma'] ?? $turmaDoNovo['nome']
                );
                if ($erroHorario !== null) $erros[] = fraseDaPrevia($erroHorario);
            }
        }

        // Sem entrada/saída: aluno que já existe não precisa de nada; o novo
        // aparece uma vez só, como cadastro.
        if ($erros === [] && !$linha['ocorrencia']) {
            if ($chaveNovo === null) {
                $jaCadastrados++;
                continue;
            }
            if (isset($cadastroListado[$chaveNovo])) {
                continue;
            }
            $cadastroListado[$chaveNovo] = true;
        }

        // Repetidos: uma de cada tipo por aluno por dia, no arquivo e no sistema.
        $situacao = $erros !== [] ? 'erro' : ($linha['ocorrencia'] ? 'nova' : 'cadastro');
        if ($situacao === 'nova') {
            $quem = $idAluno ?? "novo:{$chaveNovo}";
            $chave = "{$linha['tipo']}|{$quem}|{$linha['data']}";
            $nomeDoTipo = NOMES_TIPO_OCORRENCIA[$linha['tipo']];
            if (isset($vistas[$chave])) {
                $erros[] = "repetida: a linha {$vistas[$chave]} já tem {$nomeDoTipo} deste aluno nesse dia";
                $situacao = 'erro';
            } else {
                $vistas[$chave] = $rotulo;
                if ($idAluno !== null && isset($escola['registros'][$chave])) {
                    $situacao = 'existente';
                    $avisos[] = "já está registrada no sistema (às {$escola['registros'][$chave]}): fica como está";
                }
            }
        }

        $saida[] = [
            'linha' => $numero,
            'aba' => $aba,
            'situacao' => $situacao,
            'id_aluno' => $idAluno,
            'aluno' => $aluno['nome'] ?? $linha['nome'],
            'turma' => $aluno['turma'] ?? $turmaDoNovo['nome'] ?? $linha['turma'],
            'aluno_novo' => $chaveNovo !== null,
            'turma_nova' => $turmaDoNovo['nova'] ?? false,
            'chave_aluno_novo' => $chaveNovo,
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

    // Só entra o aluno novo (e a turma nova) de alguma linha que vai entrar.
    $alunosQueEntram = [];
    foreach ($saida as $l) {
        if ($l['chave_aluno_novo'] !== null && in_array($l['situacao'], ['nova', 'cadastro'], true)) {
            $alunosQueEntram[$l['chave_aluno_novo']] ??= $alunosNovos[$l['chave_aluno_novo']] + ['primeira' => $rotuloDaLinha($l['linha'], $l['aba'])];
        }
    }
    $turmasQueEntram = [];
    foreach ($alunosQueEntram as $novo) {
        if (isset($turmasNovas[$novo['turma']])) $turmasQueEntram[$novo['turma']] = $turmasNovas[$novo['turma']];
    }
    foreach ($saida as &$l) {
        $novo = $l['chave_aluno_novo'] !== null ? ($alunosQueEntram[$l['chave_aluno_novo']] ?? null) : null;
        if ($novo !== null && $novo['primeira'] === $rotuloDaLinha($l['linha'], $l['aba'])) {
            $l['avisos'][] = isset($turmasQueEntram[$novo['turma']])
                ? "aluno novo, na turma nova {$turmasQueEntram[$novo['turma']]['nome']} (período "
                    . mb_strtolower($turmasQueEntram[$novo['turma']]['periodo']) . '): cadastrado sem data de nascimento e gênero'
                : 'aluno novo: cadastrado sem data de nascimento e gênero';
        }
    }
    unset($l);

    $novas = array_filter($saida, fn($l) => $l['situacao'] === 'nova');
    return [
        'linhas' => $saida,
        'alunos_novos' => array_values(array_map(
            fn($chave, $a) => ['chave' => $chave, 'nome' => $a['nome'], 'turma' => $a['turma']],
            array_keys($alunosQueEntram), $alunosQueEntram
        )),
        'turmas_novas' => array_values(array_map(
            fn($chave, $t) => ['chave' => $chave, 'nome' => $t['nome'], 'periodo' => $t['periodo']],
            array_keys($turmasQueEntram), $turmasQueEntram
        )),
        'resumo' => [
            'novas' => count($novas),
            'existentes' => count(array_filter($saida, fn($l) => $l['situacao'] === 'existente')),
            'com_erro' => count(array_filter($saida, fn($l) => $l['situacao'] === 'erro')),
            'atrasos_novos' => count(array_filter($novas, fn($l) => $l['tipo'] === 'atraso')),
            'saidas_novas' => count(array_filter($novas, fn($l) => $l['tipo'] === 'saida')),
            'alunos_novos' => count($alunosQueEntram),
            'turmas_novas' => count($turmasQueEntram),
            'ja_cadastrados' => $jaCadastrados,
        ],
    ];
}

/**
 * A frase do fim da importação, com plural e concordância:
 * "Importação concluída: 3 entradas atrasadas e 1 saída antecipada registradas."
 * Com cadastros: "...: 1 entrada atrasada registrada; 2 alunos e 1 turma cadastrados."
 */
function mensagemDaImportacaoDeOcorrencias(int $atrasos, int $saidas, int $alunos = 0, int $turmas = 0): string {
    $frases = [];
    $partes = [];
    if ($atrasos > 0) $partes[] = $atrasos . ($atrasos === 1 ? ' entrada atrasada' : ' entradas atrasadas');
    if ($saidas > 0) $partes[] = $saidas . ($saidas === 1 ? ' saída antecipada' : ' saídas antecipadas');
    if ($partes !== []) {
        $frases[] = implode(' e ', $partes) . ' registrada' . ($atrasos + $saidas === 1 ? '' : 's');
    }
    $cadastros = [];
    if ($alunos > 0) $cadastros[] = $alunos . ($alunos === 1 ? ' aluno' : ' alunos');
    if ($turmas > 0) $cadastros[] = $turmas . ($turmas === 1 ? ' turma' : ' turmas');
    if ($cadastros !== []) {
        $frases[] = implode(' e ', $cadastros) . ' cadastrad' . ($alunos === 0 ? 'a' : 'o') . ($alunos + $turmas === 1 ? '' : 's');
    }
    if ($frases === []) {
        return 'Importação concluída: nada novo foi registrado.';
    }
    return 'Importação concluída: ' . implode('; ', $frases) . '.';
}
