<?php
/**
 * Importação de turmas e alunos por planilha: tudo o que não depende do
 * arquivo nem do banco. Os nomes de coluna aceitos, a leitura de cada
 * célula (datas e horários do Excel, períodos, gêneros, canais) e a
 * conferência das linhas contra o que a escola já tem. Funções puras,
 * testadas em tests/helpers_test.php.
 *
 *   helpers/planilha.php        lê o arquivo (PhpSpreadsheet) e gera o modelo
 *   helpers/importacaoEscola.php busca o que a escola já tem e grava
 *   importacao/*.php            os endpoints (modelo, prévia, confirmação)
 *
 * A planilha tem duas abas, "Turmas" e "Alunos" (uma delas pode ficar
 * vazia). O aluno pode estar numa turma que já existe na escola ou numa
 * turma nova da aba Turmas do mesmo arquivo. Nada cruza escolas: tudo é
 * conferido e gravado na escola de quem importa.
 *
 * Os campos são os que o banco já tem (turma: nome, período e horário;
 * aluno: nome, turma, nascimento e gênero; responsável: nome, CPF,
 * telefone, e-mail, parentesco e canal). Não há matrícula/RA no banco:
 * o aluno repetido é reconhecido pelo nome + data de nascimento na escola.
 */

require_once __DIR__ . '/validacao.php';
require_once __DIR__ . '/validacaoResponsavel.php';
require_once __DIR__ . '/calendario.php';

const IMPORTACAO_TAMANHO_MAXIMO = 2 * 1024 * 1024; // 2 MB
const IMPORTACAO_MAX_TURMAS = 200;
const IMPORTACAO_MAX_ALUNOS = 2000;
const IMPORTACAO_EXTENSOES = ['xlsx', 'xls', 'ods', 'csv'];

/** Os períodos que a importação aceita (os do banco, sem o "Indefinido"). */
const PERIODOS_IMPORTACAO = ['Matutino', 'Vespertino', 'Noturno', 'Integral'];

/**
 * Colunas de cada aba: chave interna => nomes aceitos no cabeçalho (já
 * normalizados: minúsculas, sem acento). O primeiro nome é o do modelo.
 */
const COLUNAS_TURMAS = [
    'turma' => ['turma', 'nome da turma', 'sala', 'nome da sala', 'turma/sala'],
    'periodo' => ['periodo', 'turno'],
    'inicio' => ['inicio da aula', 'inicio', 'horario de inicio', 'hora de inicio', 'entrada'],
    'fim' => ['fim da aula', 'fim', 'horario de fim', 'hora de fim', 'termino', 'saida'],
];
const COLUNAS_ALUNOS = [
    'nome' => ['nome completo', 'nome do aluno', 'aluno', 'nome', 'nome do estudante', 'estudante'],
    'turma' => ['turma', 'sala', 'turma/sala', 'nome da turma'],
    'nascimento' => ['data de nascimento', 'nascimento', 'data nascimento', 'data de nasc', 'dt nascimento', 'data nasc'],
    'genero' => ['genero', 'sexo'],
    'responsavel' => ['responsavel', 'nome do responsavel'],
    'cpf' => ['cpf do responsavel', 'cpf'],
    'telefone' => ['telefone do responsavel', 'telefone', 'celular', 'whatsapp', 'telefone (whatsapp)'],
    'email' => ['e-mail do responsavel', 'email do responsavel', 'e-mail', 'email'],
    'parentesco' => ['parentesco'],
    'canal' => ['avisar por', 'canal', 'canal de aviso', 'avisos por'],
];
const COLUNAS_OBRIGATORIAS = [
    'turmas' => ['turma', 'periodo'],
    'alunos' => ['nome', 'turma', 'nascimento', 'genero'],
];

/** "Período " => "periodo", "E-MAIL" => "e-mail": para comparar cabeçalhos e nomes. */
function normalizarTexto($valor): string {
    $texto = mb_strtolower(trim((string) $valor));
    $texto = strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'º' => 'o', 'ª' => 'a',
    ]);
    return preg_replace('/\s+/u', ' ', $texto);
}

/** Nome para achar repetidos: "  Ana  Lúcia " e "ana lucia" são o mesmo. */
function chaveDeNome($nome): string {
    return normalizarTexto($nome);
}

/** Texto da célula como a pessoa vê (número inteiro sem ".0"); vazio = "". */
function textoDaCelula($valor): string {
    if ($valor === null) return '';
    if (is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
        return (string) (int) $valor;
    }
    if ($valor instanceof DateTimeInterface) return $valor->format('Y-m-d');
    return trim(preg_replace('/\s+/u', ' ', (string) $valor));
}

/** true quando todas as células da linha estão em branco. */
function linhaEmBranco(array $valores): bool {
    foreach ($valores as $valor) {
        if (textoDaCelula($valor) !== '') return false;
    }
    return true;
}

/**
 * Liga cada coluna conhecida à posição dela no cabeçalho. Devolve
 * ['mapa' => [chave => índice], 'faltando' => [nomes do modelo],
 *  'ignoradas' => [cabeçalhos que não são de nenhuma coluna]].
 */
function mapearCabecalho(array $cabecalho, array $colunas, array $obrigatorias): array {
    $mapa = [];
    $ignoradas = [];
    foreach ($cabecalho as $indice => $titulo) {
        $nome = normalizarTexto($titulo);
        if ($nome === '') continue;
        $achou = null;
        foreach ($colunas as $chave => $aceitos) {
            if (!isset($mapa[$chave]) && in_array($nome, $aceitos, true)) {
                $achou = $chave;
                break;
            }
        }
        if ($achou === null) {
            $ignoradas[] = trim((string) $titulo);
            continue;
        }
        $mapa[$achou] = $indice;
    }
    $faltando = [];
    foreach ($obrigatorias as $chave) {
        if (!isset($mapa[$chave])) $faltando[] = ucfirst($colunas[$chave][0]);
    }
    return ['mapa' => $mapa, 'faltando' => $faltando, 'ignoradas' => $ignoradas];
}

/** Os valores de uma linha por chave ("turma" => "1º A"), pelo mapa do cabeçalho. */
function valoresDaLinha(array $linha, array $mapa): array {
    $valores = [];
    foreach ($mapa as $chave => $indice) {
        $valores[$chave] = $linha[$indice] ?? null;
    }
    return $valores;
}

/** "manhã", "MATUTINO" => "Matutino"; null quando não é um período aceito. */
function lerPeriodo($valor): ?string {
    $texto = normalizarTexto(textoDaCelula($valor));
    $sinonimos = [
        'matutino' => 'Matutino', 'manha' => 'Matutino', 'da manha' => 'Matutino',
        'vespertino' => 'Vespertino', 'tarde' => 'Vespertino', 'da tarde' => 'Vespertino',
        'noturno' => 'Noturno', 'noite' => 'Noturno', 'da noite' => 'Noturno',
        'integral' => 'Integral', 'periodo integral' => 'Integral',
    ];
    return $sinonimos[$texto] ?? null;
}

/** "F", "feminino" => "Feminino"; "M", "masc" => "Masculino"; null se não der. */
function lerGeneroAluno($valor): ?string {
    $texto = normalizarTexto(textoDaCelula($valor));
    if (in_array($texto, ['f', 'fem', 'feminino'], true)) return 'Feminino';
    if (in_array($texto, ['m', 'masc', 'masculino'], true)) return 'Masculino';
    return null;
}

/** Canal de aviso do responsável; em branco vale WhatsApp (como no cadastro). */
function lerCanal($valor): ?string {
    $texto = normalizarTexto(textoDaCelula($valor));
    if ($texto === '' || in_array($texto, ['whatsapp', 'whats', 'wpp', 'zap'], true)) return 'whatsapp';
    if (in_array($texto, ['e-mail', 'email'], true)) return 'email';
    if (in_array($texto, ['ambos', 'os dois', 'whatsapp e e-mail', 'whatsapp e email', 'e-mail e whatsapp', 'email e whatsapp'], true)) return 'ambos';
    return null;
}

/**
 * Horário da aula: "07:00", "7:30", "7h", "07h30" ou a fração do dia que
 * o Excel guarda (0,291666 = 07:00). Devolve [ "HH:MM" ou null, erro ou null ].
 */
function lerHora($valor): array {
    if ($valor === null || textoDaCelula($valor) === '') return [null, null];
    if ($valor instanceof DateTimeInterface) return [$valor->format('H:i'), null];
    if (is_int($valor) || is_float($valor)) {
        $fracao = fmod((float) $valor, 1.0);
        if ($fracao < 0) return [null, 'horário inválido'];
        $minutos = (int) round($fracao * 24 * 60) % (24 * 60);
        return [sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60), null];
    }
    $texto = normalizarTexto($valor);
    if (preg_match('/^(\d{1,2})(?:[:h](\d{2}))?h?$/', $texto, $partes)) {
        $hora = (int) $partes[1];
        $minuto = isset($partes[2]) && $partes[2] !== '' ? (int) $partes[2] : 0;
        if ($hora <= 23 && $minuto <= 59) return [sprintf('%02d:%02d', $hora, $minuto), null];
    }
    return [null, 'horário inválido (use HH:MM, ex.: 07:00)'];
}

/**
 * Data de nascimento: o número que o Excel guarda nas datas (dias desde
 * 30/12/1899), "dd/mm/aaaa" (ou com "-" e "."), "aaaa-mm-dd" ou um
 * DateTime. Devolve [ "aaaa-mm-dd" ou null, erro ou null ].
 */
function lerDataDeNascimento($valor): array {
    if ($valor === null || textoDaCelula($valor) === '') return [null, 'falta a data de nascimento'];
    $data = null;
    if ($valor instanceof DateTimeInterface) {
        $data = $valor->format('Y-m-d');
    } elseif (is_int($valor) || is_float($valor) || (is_string($valor) && preg_match('/^\d{4,5}(\.0+)?$/', trim($valor)))) {
        $dias = (int) floor((float) $valor);
        if ($dias >= 1 && $dias < 2958466) {
            $data = (new DateTimeImmutable('1899-12-30'))->modify("+{$dias} days")->format('Y-m-d');
        }
    } else {
        $texto = trim((string) $valor);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $texto, $p)) {
            $data = sprintf('%04d-%02d-%02d', $p[3], $p[2], $p[1]);
        } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $texto, $p)) {
            $data = sprintf('%04d-%02d-%02d', $p[1], $p[2], $p[3]);
        }
    }
    if ($data === null || !dataNascimentoValida($data)) {
        return [null, 'data de nascimento inválida (use dd/mm/aaaa; não pode ser futura)'];
    }
    return [$data, null];
}

/**
 * Uma linha da aba Turmas, conferida sozinha. Devolve os valores já
 * arrumados e a lista de erros (vazia quando a linha serve).
 */
function conferirLinhaDeTurma(array $valores): array {
    $erros = [];
    $nome = textoDaCelula($valores['turma'] ?? null);
    if ($nome === '') {
        $erros[] = 'falta o nome da turma';
    } elseif (mb_strlen($nome) > 50) {
        $erros[] = 'nome da turma com mais de 50 caracteres';
    }
    $textoPeriodo = textoDaCelula($valores['periodo'] ?? null);
    $periodo = lerPeriodo($textoPeriodo);
    if ($textoPeriodo === '') {
        $erros[] = 'falta o período';
    } elseif ($periodo === null) {
        $erros[] = "período \"{$textoPeriodo}\" desconhecido (use Matutino, Vespertino, Noturno ou Integral)";
    }
    [$inicio, $erroInicio] = lerHora($valores['inicio'] ?? null);
    [$fim, $erroFim] = lerHora($valores['fim'] ?? null);
    if ($erroInicio !== null) $erros[] = 'início da aula: ' . $erroInicio;
    if ($erroFim !== null) $erros[] = 'fim da aula: ' . $erroFim;
    if ($erroInicio === null && $erroFim === null) {
        if (($inicio === null) !== ($fim === null)) {
            $erros[] = 'informe o início e o fim da aula (ou deixe os dois em branco para usar o horário do período)';
        } elseif ($inicio !== null && minutosDoDia($fim) <= minutosDoDia($inicio)) {
            $erros[] = 'o fim da aula precisa ser depois do início';
        }
    }
    return ['nome' => $nome, 'periodo' => $periodo, 'inicio' => $inicio, 'fim' => $fim, 'erros' => $erros];
}

/**
 * Uma linha da aba Alunos, conferida sozinha (sem saber o que a escola
 * já tem). O responsável é opcional: se alguma coluna dele vier
 * preenchida, nome, CPF e telefone passam a ser obrigatórios, como no
 * cadastro de responsável.
 */
function conferirLinhaDeAluno(array $valores): array {
    $erros = [];
    $nome = textoDaCelula($valores['nome'] ?? null);
    if ($nome === '') {
        $erros[] = 'falta o nome do aluno';
    } elseif (mb_strlen($nome) > 150) {
        $erros[] = 'nome do aluno com mais de 150 caracteres';
    }
    $turma = textoDaCelula($valores['turma'] ?? null);
    if ($turma === '') $erros[] = 'falta a turma';
    [$nascimento, $erroData] = lerDataDeNascimento($valores['nascimento'] ?? null);
    if ($erroData !== null) $erros[] = $erroData;
    $textoGenero = textoDaCelula($valores['genero'] ?? null);
    $genero = lerGeneroAluno($textoGenero);
    if ($textoGenero === '') {
        $erros[] = 'falta o gênero';
    } elseif ($genero === null) {
        $erros[] = "gênero \"{$textoGenero}\" desconhecido (use Feminino ou Masculino)";
    }

    $responsavel = null;
    $camposDoResponsavel = ['responsavel', 'cpf', 'telefone', 'email', 'parentesco', 'canal'];
    $algumPreenchido = false;
    foreach ($camposDoResponsavel as $campo) {
        if (textoDaCelula($valores[$campo] ?? null) !== '') $algumPreenchido = true;
    }
    if ($algumPreenchido) {
        $nomeResponsavel = textoDaCelula($valores['responsavel'] ?? null);
        $cpf = somenteDigitos(textoDaCelula($valores['cpf'] ?? null));
        // CPF e telefone digitados como número perdem os zeros da frente no Excel.
        if ($cpf !== '' && strlen($cpf) < 11) $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);
        $telefone = somenteDigitos(textoDaCelula($valores['telefone'] ?? null));
        $email = textoDaCelula($valores['email'] ?? null);
        $textoCanal = textoDaCelula($valores['canal'] ?? null);
        $canal = lerCanal($textoCanal);
        [$parentesco, $erroParentesco] = lerParentesco(textoDaCelula($valores['parentesco'] ?? null));
        if ($nomeResponsavel === '') {
            $erros[] = 'falta o nome do responsável';
        } elseif (mb_strlen($nomeResponsavel) > 150) {
            $erros[] = 'nome do responsável com mais de 150 caracteres';
        }
        if (!cpfValido($cpf)) $erros[] = 'CPF do responsável inválido';
        if (!telefoneValido($telefone)) $erros[] = 'telefone do responsável inválido (10 ou 11 dígitos, com DDD)';
        if ($email !== '' && (!emailValido($email) || mb_strlen($email) > 150)) $erros[] = 'e-mail do responsável inválido';
        if ($canal === null) {
            $erros[] = "\"avisar por\" desconhecido: \"{$textoCanal}\" (use WhatsApp, E-mail ou Ambos)";
        } elseif ($canal !== 'whatsapp' && $email === '') {
            $erros[] = 'para avisar por e-mail, informe o e-mail do responsável';
        }
        if ($erroParentesco !== null) $erros[] = mb_strtolower($erroParentesco);
        $responsavel = [
            'nome' => $nomeResponsavel,
            'cpf' => $cpf,
            'telefone' => $telefone,
            'email' => $email === '' ? null : mb_strtolower($email),
            'parentesco' => $parentesco,
            'canal' => $canal ?? 'whatsapp',
        ];
    }
    return [
        'nome' => $nome,
        'turma' => $turma,
        'nascimento' => $nascimento,
        'genero' => $genero,
        'responsavel' => $responsavel,
        'erros' => $erros,
    ];
}

/**
 * A prévia completa: cada linha das duas abas conferida sozinha e contra
 * o que a escola já tem e as outras linhas do arquivo.
 *
 *   $abas: ['turmas' => [[numeroDaLinha, valoresPorChave], ...], 'alunos' => [...]]
 *   $escola: [
 *     'turmas'       => [chaveDeNome => ['id_sala' => int, 'nome_sala' => string]],
 *     'alunos'       => [chaveDeNome . '|' . nascimento => true],
 *     'responsaveis' => [cpf => ['id_responsavel' => int, 'nome' => string, 'telefone' => string,
 *                                'email' => ?string, 'na_escola' => bool, 'vinculos' => int]],
 *     'telefones'    => [telefone => cpf],   (de qualquer escola: são únicos no banco)
 *     'emails'       => [email => cpf],
 *   ]
 *
 * Cada linha sai com 'situacao': 'nova'/'novo' (vai ser cadastrada),
 * 'existente' (já está na escola: fica de fora, não é erro) ou 'erro'.
 */
function montarPreviaDaImportacao(array $abas, array $escola): array {
    $turmas = [];
    $turmasDoArquivo = []; // chave => índice em $turmas
    foreach ($abas['turmas'] ?? [] as [$numero, $valores]) {
        $linha = conferirLinhaDeTurma($valores) + ['linha' => $numero, 'avisos' => []];
        $chave = chaveDeNome($linha['nome']);
        if ($linha['nome'] !== '' && isset($turmasDoArquivo[$chave])) {
            $linha['erros'][] = 'turma repetida na planilha (linha ' . $turmas[$turmasDoArquivo[$chave]]['linha'] . ')';
        }
        if ($linha['erros'] !== []) {
            $linha['situacao'] = 'erro';
        } elseif (isset($escola['turmas'][$chave])) {
            $linha['situacao'] = 'existente';
            $linha['avisos'][] = 'a escola já tem esta turma: ela fica como está';
        } else {
            $linha['situacao'] = 'nova';
        }
        if ($linha['nome'] !== '' && !isset($turmasDoArquivo[$chave])) {
            $turmasDoArquivo[$chave] = count($turmas);
        }
        $turmas[] = $linha;
    }

    $alunos = [];
    $alunosDoArquivo = [];       // chave nome|nascimento => linha
    $cpfsDoArquivo = [];         // cpf => ['linha' => n, 'nome' => ..., 'telefone' => ..., 'email' => ...]
    $telefonesDoArquivo = [];    // telefone => cpf
    $emailsDoArquivo = [];       // email => cpf
    foreach ($abas['alunos'] ?? [] as [$numero, $valores]) {
        $linha = conferirLinhaDeAluno($valores) + ['linha' => $numero, 'avisos' => []];
        $chaveTurma = chaveDeNome($linha['turma']);
        if ($linha['turma'] !== '') {
            if (isset($escola['turmas'][$chaveTurma])) {
                $linha['turma'] = $escola['turmas'][$chaveTurma]['nome_sala'];
            } elseif (isset($turmasDoArquivo[$chaveTurma])) {
                $turmaDaAba = $turmas[$turmasDoArquivo[$chaveTurma]];
                $linha['turma'] = $turmaDaAba['nome'];
                if ($turmaDaAba['situacao'] === 'erro') {
                    $linha['erros'][] = "a turma \"{$turmaDaAba['nome']}\" tem erro na aba Turmas (linha {$turmaDaAba['linha']})";
                }
            } else {
                $linha['erros'][] = "a turma \"{$linha['turma']}\" não existe na escola nem na aba Turmas";
            }
        }
        $chaveAluno = chaveDeNome($linha['nome']) . '|' . $linha['nascimento'];
        if ($linha['nome'] !== '' && $linha['nascimento'] !== null && isset($alunosDoArquivo[$chaveAluno])) {
            $linha['erros'][] = 'aluno repetido na planilha (linha ' . $alunosDoArquivo[$chaveAluno] . ')';
        }

        $responsavel = $linha['responsavel'];
        if ($responsavel !== null && $linha['erros'] === []) {
            $cpf = $responsavel['cpf'];
            $existente = $escola['responsaveis'][$cpf] ?? null;
            if (isset($cpfsDoArquivo[$cpf])) {
                // Irmãos: o mesmo responsável em mais de uma linha.
                $primeira = $cpfsDoArquivo[$cpf];
                $responsavel['situacao'] = 'mesmo';
                $linha['avisos'][] = "responsável da linha {$primeira['linha']}: só vincula a este aluno";
                if (chaveDeNome($primeira['nome']) !== chaveDeNome($responsavel['nome']) || $primeira['telefone'] !== $responsavel['telefone']) {
                    $linha['avisos'][] = 'o nome ou o telefone do responsável é diferente da primeira linha: vale o da primeira';
                }
            } elseif ($existente !== null && $existente['na_escola']) {
                $responsavel['situacao'] = 'vincular';
                $responsavel['id_responsavel'] = $existente['id_responsavel'];
                $linha['avisos'][] = "{$existente['nome']} já é responsável na escola: só vincula a este aluno";
            } elseif ($existente !== null && $existente['vinculos'] > 0) {
                $linha['erros'][] = 'este CPF já é responsável em outra escola (procure o suporte do sistema)';
            } else {
                $responsavel['situacao'] = $existente !== null ? 'reaproveitar' : 'novo';
                if ($existente !== null) $responsavel['id_responsavel'] = $existente['id_responsavel'];
                $donoTelefone = $escola['telefones'][$responsavel['telefone']] ?? $telefonesDoArquivo[$responsavel['telefone']] ?? null;
                if ($donoTelefone !== null && $donoTelefone !== $cpf) {
                    $linha['erros'][] = 'o telefone do responsável já é de outro responsável';
                }
                if ($responsavel['email'] !== null) {
                    $donoEmail = $escola['emails'][$responsavel['email']] ?? $emailsDoArquivo[$responsavel['email']] ?? null;
                    if ($donoEmail !== null && $donoEmail !== $cpf) {
                        $linha['erros'][] = 'o e-mail do responsável já é de outro responsável';
                    }
                }
            }
            $linha['responsavel'] = $responsavel;
        }

        if ($linha['erros'] !== []) {
            $linha['situacao'] = 'erro';
        } elseif (isset($escola['alunos'][$chaveAluno])) {
            $linha['situacao'] = 'existente';
            $linha['avisos'] = ['a escola já tem um aluno com este nome e esta data de nascimento: o cadastro fica como está'];
        } else {
            $linha['situacao'] = 'novo';
            // Só quem vai entrar "ocupa" o CPF, o telefone e o e-mail para as linhas de baixo.
            if ($responsavel !== null && !isset($cpfsDoArquivo[$responsavel['cpf']])) {
                $cpfsDoArquivo[$responsavel['cpf']] = ['linha' => $numero] + $responsavel;
                $telefonesDoArquivo[$responsavel['telefone']] = $responsavel['cpf'];
                if ($responsavel['email'] !== null) $emailsDoArquivo[$responsavel['email']] = $responsavel['cpf'];
            }
        }
        if ($linha['nome'] !== '' && $linha['nascimento'] !== null && !isset($alunosDoArquivo[$chaveAluno])) {
            $alunosDoArquivo[$chaveAluno] = $numero;
        }
        $alunos[] = $linha;
    }

    $conta = fn(array $linhas, string $situacao) => count(array_filter($linhas, fn($l) => $l['situacao'] === $situacao));
    $responsaveis = array_filter(array_column(array_filter($alunos, fn($l) => $l['situacao'] === 'novo'), 'responsavel'));
    return [
        'turmas' => $turmas,
        'alunos' => $alunos,
        'resumo' => [
            'turmas_novas' => $conta($turmas, 'nova'),
            'turmas_existentes' => $conta($turmas, 'existente'),
            'turmas_com_erro' => $conta($turmas, 'erro'),
            'alunos_novos' => $conta($alunos, 'novo'),
            'alunos_existentes' => $conta($alunos, 'existente'),
            'alunos_com_erro' => $conta($alunos, 'erro'),
            'responsaveis_novos' => count(array_filter($responsaveis, fn($r) => in_array($r['situacao'], ['novo', 'reaproveitar'], true))),
            'responsaveis_vinculados' => count(array_filter($responsaveis, fn($r) => in_array($r['situacao'], ['vincular', 'mesmo'], true))),
        ],
    ];
}

/**
 * A frase do fim da importação, com plural e concordância:
 * "Importação concluída: 2 turmas, 3 alunos e 1 responsável cadastrados."
 * Só turmas: "1 turma cadastrada". Só alunos: "1 aluno cadastrado".
 */
function mensagemDaImportacao(int $turmas, int $alunos, int $responsaveis): string {
    $partes = [];
    if ($turmas > 0) $partes[] = $turmas . ($turmas === 1 ? ' turma' : ' turmas');
    if ($alunos > 0) $partes[] = $alunos . ($alunos === 1 ? ' aluno' : ' alunos');
    if ($responsaveis > 0) $partes[] = $responsaveis . ($responsaveis === 1 ? ' responsável' : ' responsáveis');
    if ($partes === []) {
        return 'Importação concluída: nada novo foi cadastrado.';
    }
    $ultima = array_pop($partes);
    $lista = $partes === [] ? $ultima : implode(', ', $partes) . ' e ' . $ultima;
    $soTurmas = $alunos === 0 && $responsaveis === 0;
    $umaCoisaSo = $turmas + $alunos + $responsaveis === 1;
    return "Importação concluída: $lista cadastrad" . ($soTurmas ? 'a' : 'o') . ($umaCoisaSo ? '' : 's') . '.';
}
