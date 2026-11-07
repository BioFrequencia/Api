<?php
/**
 * Os arquivos das importações por planilha, com o PhpSpreadsheet
 * (vendor/): conferir o upload, ler as abas e gerar os modelos para
 * download - o de turmas e alunos (importacao/) e o de entradas atrasadas
 * e saídas antecipadas registradas sem internet (registro/). A
 * conferência das linhas fica em helpers/importacao.php e
 * helpers/importacaoOcorrencias.php.
 *
 * Segurança na leitura: só .xlsx, .xls, .ods e .csv de até 2 MB, com o
 * começo do arquivo conferido (um .xlsx é um zip; um .xls, um arquivo do
 * Office antigo); o leitor é o do formato declarado (sem adivinhar),
 * lê só os dados (sem estilos) e só as primeiras 20 colunas e as linhas
 * do limite de cada importação - um arquivo pequeno que se expande demais
 * não derruba o servidor. Fórmulas não são calculadas: vale o resultado
 * que o próprio Excel guardou. O PhpSpreadsheet recusa XML com DOCTYPE
 * (proteção contra XXE).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/importacao.php';
require_once __DIR__ . '/importacaoOcorrencias.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Problema no arquivo que a pessoa consegue resolver (a mensagem vai para a tela). */
final class PlanilhaInvalida extends RuntimeException {}

const PLANILHA_MAX_COLUNAS = 20;
/** Linhas em que o cabeçalho pode estar (a planilha pode ter um título em cima). */
const PLANILHA_LINHAS_DO_CABECALHO = 10;

/** Lê só o pedaço da aba que interessa (memória limitada mesmo com arquivo grande). */
final class FiltroDaImportacao implements IReadFilter {
    public function __construct(private int $maxLinhas) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
        return $row <= $this->maxLinhas + PLANILHA_LINHAS_DO_CABECALHO
            && Coordinate::columnIndexFromString($columnAddress) <= PLANILHA_MAX_COLUNAS;
    }
}

/**
 * Confere o arquivo enviado no campo "arquivo" (multipart/form-data).
 * Devolve ['caminho', 'extensao', 'nome', 'hash'] ou lança PlanilhaInvalida.
 */
function arquivoDaImportacao(?array $arquivo): array {
    if ($arquivo === null || !isset($arquivo['error'])) {
        throw new PlanilhaInvalida('Escolha o arquivo da planilha.');
    }
    if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new PlanilhaInvalida('A planilha passa de 2 MB. Divida em arquivos menores.');
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        throw new PlanilhaInvalida('O arquivo não chegou inteiro. Tente enviar de novo.');
    }
    if ($arquivo['size'] > IMPORTACAO_TAMANHO_MAXIMO) {
        throw new PlanilhaInvalida('A planilha passa de 2 MB. Divida em arquivos menores.');
    }
    $nome = basename((string) $arquivo['name']);
    $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if (!in_array($extensao, IMPORTACAO_EXTENSOES, true)) {
        throw new PlanilhaInvalida('Envie uma planilha do Excel (.xlsx ou .xls), do LibreOffice (.ods) ou um .csv.');
    }
    $inicio = (string) file_get_contents($arquivo['tmp_name'], false, null, 0, 8);
    $assinaturaCerta = match ($extensao) {
        'xlsx', 'ods' => str_starts_with($inicio, "PK\x03\x04"),
        'xls' => str_starts_with($inicio, "\xD0\xCF\x11\xE0"),
        'csv' => !str_contains($inicio, "\0"),
    };
    if (!$assinaturaCerta) {
        throw new PlanilhaInvalida("O arquivo não é um .{$extensao} de verdade. Abra no Excel e salve de novo.");
    }
    return ['caminho' => $arquivo['tmp_name'], 'extensao' => $extensao, 'nome' => $nome, 'hash' => hash_file('sha256', $arquivo['tmp_name'])];
}

/** O valor que a pessoa vê na célula, sem calcular fórmula. */
function valorDaCelula(Worksheet $aba, int $coluna, int $linha) {
    if (!$aba->cellExists([$coluna, $linha])) return null;
    $celula = $aba->getCell([$coluna, $linha]);
    $valor = $celula->isFormula() ? $celula->getOldCalculatedValue() : $celula->getValue();
    if ($valor instanceof RichText) return $valor->getPlainText();
    return $valor;
}

/**
 * Lê as abas Turmas e Alunos. Uma aba vale pelo nome ("Turmas", "Alunos")
 * ou, sem nome conhecido (um .csv, por exemplo), pelo cabeçalho. Devolve
 * ['turmas' => [[linha, valores]], 'alunos' => [...], 'avisos' => [...]].
 */
/**
 * Abre a planilha com o leitor do formato declarado (sem adivinhar), só
 * dados, só o pedaço de cada aba que interessa ($maxLinhas de dados).
 */
function abrirPlanilhaDaImportacao(string $caminho, string $extensao, int $maxLinhas): Spreadsheet {
    try {
        $leitor = IOFactory::createReader(match ($extensao) {
            'xlsx' => 'Xlsx',
            'xls' => 'Xls',
            'ods' => 'Ods',
            'csv' => 'Csv',
        });
        if ($leitor instanceof Csv) {
            $leitor->setInputEncoding(Csv::GUESS_ENCODING);
        }
        $leitor->setReadDataOnly(true);
        $leitor->setReadEmptyCells(false);
        $leitor->setReadFilter(new FiltroDaImportacao($maxLinhas));
        return $leitor->load($caminho);
    } catch (Throwable $erro) {
        error_log('[BioFrequenciaApi] planilha ilegível: ' . $erro->getMessage());
        throw new PlanilhaInvalida('Não foi possível ler a planilha. Abra no Excel, confira e salve de novo.');
    }
}

/**
 * Acha o cabeçalho da aba: a primeira linha (entre as 10 primeiras) com as
 * colunas obrigatórias de um dos $perfis (nome => [colunas, obrigatórias]).
 * Devolve [perfil, mapeado, linha do cabeçalho] ou null.
 */
function cabecalhoDaAba(Worksheet $aba, array $perfis, int $ultimaLinha): ?array {
    for ($linha = 1; $linha <= min(PLANILHA_LINHAS_DO_CABECALHO, $ultimaLinha); $linha++) {
        $titulos = [];
        for ($coluna = 1; $coluna <= PLANILHA_MAX_COLUNAS; $coluna++) {
            $titulos[$coluna] = textoDaCelula(valorDaCelula($aba, $coluna, $linha));
        }
        foreach ($perfis as $perfil => [$colunas, $obrigatorias]) {
            $mapeado = mapearCabecalho($titulos, $colunas, $obrigatorias);
            if ($mapeado['faltando'] === []) {
                return [$perfil, $mapeado, $linha];
            }
        }
    }
    return null;
}

/** As linhas preenchidas abaixo do cabeçalho: [[número da linha, valores por chave]]. */
function linhasAbaixoDoCabecalho(Worksheet $aba, array $mapa, int $linhaDoCabecalho, int $ultimaLinha, int $limite): array {
    $linhas = [];
    for ($linha = $linhaDoCabecalho + 1; $linha <= $ultimaLinha; $linha++) {
        $valores = [];
        foreach ($mapa as $chave => $coluna) {
            $valores[$chave] = valorDaCelula($aba, $coluna, $linha);
        }
        if (linhaEmBranco($valores)) continue;
        if (count($linhas) >= $limite) {
            throw new PlanilhaInvalida("A aba \"{$aba->getTitle()}\" passa de {$limite} linhas. Divida a importação em arquivos menores.");
        }
        $linhas[] = [$linha, $valores];
    }
    return $linhas;
}

function lerAbasDaImportacao(string $caminho, string $extensao): array {
    $planilha = abrirPlanilhaDaImportacao($caminho, $extensao, IMPORTACAO_MAX_ALUNOS);

    $resultado = ['turmas' => [], 'alunos' => [], 'avisos' => []];
    $achou = ['turmas' => false, 'alunos' => false];
    foreach ($planilha->getAllSheets() as $aba) {
        $nomeDaAba = normalizarTexto($aba->getTitle());
        if (str_contains($nomeDaAba, 'instru') || str_contains($nomeDaAba, 'lista')) continue;
        if (str_contains($nomeDaAba, 'ocorrenc')) {
            throw new PlanilhaInvalida('Este é o modelo de entradas atrasadas e saídas antecipadas: importe em Registros > Importar planilha.');
        }
        $tipoPeloNome = str_contains($nomeDaAba, 'turma') ? 'turmas' : (str_contains($nomeDaAba, 'aluno') ? 'alunos' : null);

        $ultimaLinha = min($aba->getHighestDataRow(), IMPORTACAO_MAX_ALUNOS + PLANILHA_LINHAS_DO_CABECALHO);
        $perfis = [];
        foreach ($tipoPeloNome !== null ? [$tipoPeloNome] : ['alunos', 'turmas'] as $candidato) {
            $perfis[$candidato] = [$candidato === 'turmas' ? COLUNAS_TURMAS : COLUNAS_ALUNOS, COLUNAS_OBRIGATORIAS[$candidato]];
        }
        $achado = cabecalhoDaAba($aba, $perfis, $ultimaLinha);
        [$tipo, $cabecalho, $linhaDoCabecalho] = $achado ?? [null, null, 0];
        if ($cabecalho === null) {
            if ($tipoPeloNome !== null && $ultimaLinha > 1) {
                $colunas = $tipoPeloNome === 'turmas' ? COLUNAS_TURMAS : COLUNAS_ALUNOS;
                $faltando = array_map(fn($c) => ucfirst($colunas[$c][0]), COLUNAS_OBRIGATORIAS[$tipoPeloNome]);
                throw new PlanilhaInvalida("A aba \"{$aba->getTitle()}\" precisa das colunas: " . implode(', ', $faltando) . '. Use o modelo para conferir os nomes.');
            }
            continue;
        }
        if ($achou[$tipo]) {
            $resultado['avisos'][] = "A aba \"{$aba->getTitle()}\" foi ignorada: o arquivo já tem uma aba de {$tipo}.";
            continue;
        }
        $achou[$tipo] = true;
        if ($cabecalho['ignoradas'] !== []) {
            $resultado['avisos'][] = "Colunas ignoradas na aba \"{$aba->getTitle()}\": " . implode(', ', $cabecalho['ignoradas']) . '.';
        }

        $limite = $tipo === 'turmas' ? IMPORTACAO_MAX_TURMAS : IMPORTACAO_MAX_ALUNOS;
        $resultado[$tipo] = linhasAbaixoDoCabecalho($aba, $cabecalho['mapa'], $linhaDoCabecalho, $ultimaLinha, $limite);
    }
    $planilha->disconnectWorksheets();
    if (!$achou['turmas'] && !$achou['alunos']) {
        throw new PlanilhaInvalida('Não achamos as abas Turmas e Alunos nem um cabeçalho conhecido. Baixe o modelo e use as mesmas colunas.');
    }
    if ($resultado['turmas'] === [] && $resultado['alunos'] === []) {
        throw new PlanilhaInvalida('A planilha está vazia: preencha a aba Turmas, a aba Alunos ou as duas.');
    }
    return $resultado;
}

/**
 * Lê a aba de entradas atrasadas e saídas antecipadas: a primeira aba
 * (fora Instruções e Listas) com Data, Horário e Tipo no cabeçalho - a
 * aba Alunos do modelo, só de consulta, não tem essas colunas. Devolve
 * ['linhas' => [[linha, valores]], 'avisos' => [...]].
 */
function lerOcorrenciasDaImportacao(string $caminho, string $extensao): array {
    $planilha = abrirPlanilhaDaImportacao($caminho, $extensao, IMPORTACAO_MAX_OCORRENCIAS);

    $resultado = ['linhas' => [], 'avisos' => []];
    $achou = false;
    $pareceTurmas = false;
    foreach ($planilha->getAllSheets() as $aba) {
        $nomeDaAba = normalizarTexto($aba->getTitle());
        if (str_contains($nomeDaAba, 'instru') || str_contains($nomeDaAba, 'lista')) continue;
        $pareceTurmas = $pareceTurmas || str_contains($nomeDaAba, 'turma');

        $ultimaLinha = min($aba->getHighestDataRow(), IMPORTACAO_MAX_OCORRENCIAS + PLANILHA_LINHAS_DO_CABECALHO);
        $achado = cabecalhoDaAba($aba, ['ocorrencias' => [COLUNAS_OCORRENCIAS, COLUNAS_OBRIGATORIAS_OCORRENCIAS]], $ultimaLinha);
        if ($achado === null) continue;
        [, $cabecalho, $linhaDoCabecalho] = $achado;
        if ($achou) {
            $resultado['avisos'][] = "A aba \"{$aba->getTitle()}\" foi ignorada: o arquivo já tem uma aba de ocorrências.";
            continue;
        }
        $achou = true;
        if (!isset($cabecalho['mapa']['codigo']) && !isset($cabecalho['mapa']['nome'])) {
            throw new PlanilhaInvalida("A aba \"{$aba->getTitle()}\" precisa da coluna Código do aluno ou da coluna Nome do aluno. Use o modelo para conferir os nomes.");
        }
        if ($cabecalho['ignoradas'] !== []) {
            $resultado['avisos'][] = "Colunas ignoradas na aba \"{$aba->getTitle()}\": " . implode(', ', $cabecalho['ignoradas']) . '.';
        }
        $resultado['linhas'] = linhasAbaixoDoCabecalho($aba, $cabecalho['mapa'], $linhaDoCabecalho, $ultimaLinha, IMPORTACAO_MAX_OCORRENCIAS);
    }
    $planilha->disconnectWorksheets();
    if (!$achou) {
        throw new PlanilhaInvalida($pareceTurmas
            ? 'Este é o modelo de turmas e alunos: importe em Turmas e alunos > Importar planilha.'
            : 'Não achamos a aba Ocorrências: ela precisa das colunas Data, Horário e Tipo. Baixe o modelo de entradas e saídas e use as mesmas colunas.');
    }
    if ($resultado['linhas'] === []) {
        throw new PlanilhaInvalida('A aba Ocorrências está vazia: preencha uma linha por entrada atrasada ou saída antecipada.');
    }
    return $resultado;
}

/** Cabeçalho do modelo: negrito branco no índigo do BioFrequência. */
function estilizarCabecalho(Worksheet $aba, array $titulos, array $larguras): void {
    foreach ($titulos as $indice => $titulo) {
        $coluna = Coordinate::stringFromColumnIndex($indice + 1);
        $aba->setCellValue("{$coluna}1", $titulo);
        $aba->getColumnDimension($coluna)->setWidth($larguras[$indice]);
    }
    $ultima = Coordinate::stringFromColumnIndex(count($titulos));
    $estilo = $aba->getStyle("A1:{$ultima}1");
    $estilo->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF452FF6');
    $estilo->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $aba->getRowDimension(1)->setRowHeight(22);
    $aba->freezePane('A2');
}

/** Lista suspensa numa faixa de células (com ou sem aceitar outro valor digitado). */
function listaNaFaixa(Worksheet $aba, string $faixa, string $formula, string $ajuda, bool $soDaLista = true): void {
    $validacao = new DataValidation();
    $validacao->setType(DataValidation::TYPE_LIST);
    $validacao->setAllowBlank(true);
    $validacao->setShowDropDown(true);
    $validacao->setShowInputMessage(true);
    $validacao->setPrompt($ajuda);
    $validacao->setShowErrorMessage($soDaLista);
    $validacao->setErrorStyle(DataValidation::STYLE_STOP);
    $validacao->setErrorTitle('Valor fora da lista');
    $validacao->setError($ajuda);
    $validacao->setFormula1($formula);
    $aba->setDataValidation($faixa, $validacao);
}

/**
 * O modelo para download: Instruções, Turmas e Alunos, com listas de
 * período, gênero, canal e das turmas que a escola já tem. CPF e
 * telefone ficam como texto (o Excel não come o zero da frente) e a data
 * de nascimento como data.
 */
function modeloDaImportacao(array $turmasDaEscola): Spreadsheet {
    $planilha = new Spreadsheet();
    $planilha->getProperties()->setCreator('BioFrequência')->setTitle('Modelo de importação de turmas e alunos');

    $instrucoes = $planilha->getActiveSheet();
    $instrucoes->setTitle('Instruções');
    $instrucoes->getColumnDimension('A')->setWidth(110);
    $texto = [
        ['Importar turmas e alunos no BioFrequência', true],
        ['Preencha a aba Turmas, a aba Alunos ou as duas. Uma linha por turma ou por aluno; não mude os nomes das colunas.', false],
        ['Depois, em Turmas e alunos > Importar planilha, escolha este arquivo: o sistema mostra uma prévia e só cadastra quando você confirmar.', false],
        ['', false],
        ['Aba Turmas', true],
        ['Turma (obrigatório): o nome como aparece no sistema, com a série. Ex.: 1º A, 6º B, 3ª Série C.', false],
        ['Período (obrigatório): Matutino, Vespertino, Noturno ou Integral.', false],
        ['Início da aula e Fim da aula (opcionais, juntos): HH:MM. Em branco, vale o horário padrão do período.', false],
        ['', false],
        ['Aba Alunos', true],
        ['Nome completo, Turma, Data de nascimento (dd/mm/aaaa) e Gênero (Feminino ou Masculino) são obrigatórios.', false],
        ['A turma pode ser uma que a escola já tem (lista abaixo) ou uma nova da aba Turmas deste arquivo.', false],
        ['Responsável (opcional): preencha nome, CPF e telefone com DDD; e-mail, parentesco e "Avisar por" (WhatsApp, E-mail ou Ambos) se quiser.', false],
        ['Irmãos com o mesmo responsável: repita o mesmo CPF nas linhas de cada um. Um responsável que já está na escola só é vinculado.', false],
        ['', false],
        ['O que acontece na importação', true],
        ['Turma ou aluno que a escola já tem (aluno: mesmo nome e mesma data de nascimento) fica como está - importar de novo não duplica.', false],
        ['Linhas com erro aparecem na prévia com o motivo e não entram; as outras são cadastradas juntas, de uma vez.', false],
        ['', false],
        ['Turmas que a escola já tem', true],
    ];
    foreach ($turmasDaEscola === [] ? ['(nenhuma ainda)'] : $turmasDaEscola as $turma) {
        $texto[] = ['• ' . $turma, false];
    }
    foreach ($texto as $indice => [$linha, $titulo]) {
        $celula = 'A' . ($indice + 1);
        $instrucoes->setCellValue($celula, $linha);
        $instrucoes->getStyle($celula)->getAlignment()->setWrapText(true);
        if ($titulo) {
            $instrucoes->getStyle($celula)->getFont()->setBold(true)->setSize($indice === 0 ? 14 : 12)->getColor()->setARGB('FF452FF6');
        }
    }

    // Listas usadas nas listas suspensas (aba escondida).
    $listas = $planilha->createSheet();
    $listas->setTitle('Listas');
    foreach (array_values($turmasDaEscola) as $indice => $turma) {
        $listas->setCellValueExplicit('A' . ($indice + 1), $turma, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $listas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

    $turmas = $planilha->createSheet(1);
    $turmas->setTitle('Turmas');
    estilizarCabecalho($turmas, ['Turma', 'Período', 'Início da aula', 'Fim da aula'], [22, 16, 16, 16]);
    $fimTurmas = IMPORTACAO_MAX_TURMAS + 1;
    listaNaFaixa($turmas, "B2:B{$fimTurmas}", '"Matutino,Vespertino,Noturno,Integral"', 'Matutino, Vespertino, Noturno ou Integral.');
    $turmas->getStyle("C2:D{$fimTurmas}")->getNumberFormat()->setFormatCode('hh:mm');

    $alunos = $planilha->createSheet(2);
    $alunos->setTitle('Alunos');
    estilizarCabecalho($alunos, [
        'Nome completo', 'Turma', 'Data de nascimento', 'Gênero', 'Responsável', 'CPF do responsável',
        'Telefone do responsável', 'E-mail do responsável', 'Parentesco', 'Avisar por',
    ], [34, 14, 19, 13, 30, 19, 22, 30, 14, 14]);
    $fimAlunos = IMPORTACAO_MAX_ALUNOS + 1;
    $alunos->getStyle("C2:C{$fimAlunos}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    $alunos->getStyle("F2:G{$fimAlunos}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    listaNaFaixa($alunos, "D2:D{$fimAlunos}", '"Feminino,Masculino"', 'Feminino ou Masculino.');
    listaNaFaixa($alunos, "J2:J{$fimAlunos}", '"WhatsApp,E-mail,Ambos"', 'WhatsApp, E-mail ou Ambos (em branco: WhatsApp).');
    if ($turmasDaEscola !== []) {
        $ultima = count($turmasDaEscola);
        // Aceita digitar outra: pode ser uma turma nova da aba Turmas.
        listaNaFaixa($alunos, "B2:B{$fimAlunos}", "Listas!\$A\$1:\$A\${$ultima}", 'Uma turma da escola ou uma nova da aba Turmas.', false);
    }

    $planilha->setActiveSheetIndex(0);
    return $planilha;
}

/** Linhas da aba Ocorrências que já vêm com a busca do nome e da turma pelo código. */
const OCORRENCIAS_LINHAS_PRONTAS = 500;

/**
 * O modelo das entradas atrasadas e saídas antecipadas registradas sem
 * internet: Instruções, Ocorrências (para preencher) e Alunos (consulta:
 * código, nome, turma e período de todos os alunos da escola). Na aba
 * Ocorrências, digitar o código traz o nome e a turma (PROCV), a data é
 * data, o horário é hora e Tipo e Justificada? têm lista suspensa.
 *
 * @param array $alunos id => ['nome', 'turma', 'periodo'] (alunosDaEscolaParaOcorrencias)
 */
function modeloDeOcorrencias(array $alunos, string $hojeLocal): Spreadsheet {
    $planilha = new Spreadsheet();
    $planilha->getProperties()->setCreator('BioFrequência')->setTitle('Modelo de entradas atrasadas e saídas antecipadas');
    $fim = OCORRENCIAS_LINHAS_PRONTAS + 1;

    $instrucoes = $planilha->getActiveSheet();
    $instrucoes->setTitle('Instruções');
    $instrucoes->getColumnDimension('A')->setWidth(110);
    $texto = [
        ['Entradas atrasadas e saídas antecipadas sem internet', true],
        ['Use esta planilha quando estiver sem internet. Quando a conexão voltar, importe o arquivo em Registros > Importar planilha (no site ou no programa do computador): o sistema mostra uma prévia e só registra quando você confirmar.', false],
        ['', false],
        ['Aba Ocorrências', true],
        ['Uma linha por entrada atrasada ou saída antecipada. Não mude os nomes das colunas.', false],
        ['Código do aluno: o número da aba Alunos. Ao digitar o código, o nome e a turma aparecem sozinhos - confira se é o aluno certo. Sem o código, escreva o nome completo e a turma.', false],
        ['Data (dd/mm/aaaa) e Horário (HH:MM) do que aconteceu. Precisa ser dia de aula e dentro do horário da turma; entrada atrasada só conta depois da tolerância da escola.', false],
        ['Tipo: Entrada atrasada ou Saída antecipada.', false],
        ['Opcionais: Motivo / justificativa (até 150 caracteres), Justificada? (Sim ou Não; em branco = Não), Observação e, só na saída antecipada, Quem buscou o aluno.', false],
        ['As linhas 2 a ' . $fim . ' já buscam o nome e a turma pelo código. Para mais linhas, copie a última para baixo.', false],
        ['', false],
        ['O que acontece na importação', true],
        ['O que já está registrado no sistema (mesmo aluno, mesmo dia e mesmo tipo) fica como está - importar de novo não duplica.', false],
        ['Linhas com erro aparecem na prévia com o motivo e não entram; as outras entram juntas, de uma vez.', false],
        ['Os responsáveis não recebem um aviso por registro importado (chegaria atrasado). O aviso de quando o aluno passa do limite da escola continua valendo.', false],
        ['', false],
        ['Alunos', true],
        [sprintf('A aba Alunos tem os %d alunos da escola em %s. Aluno cadastrado depois disso: baixe o modelo de novo ou escreva o nome e a turma.', count($alunos), dataBr($hojeLocal)), false],
    ];
    foreach ($texto as $indice => [$linha, $titulo]) {
        $celula = 'A' . ($indice + 1);
        $instrucoes->setCellValue($celula, $linha);
        $instrucoes->getStyle($celula)->getAlignment()->setWrapText(true);
        if ($titulo) {
            $instrucoes->getStyle($celula)->getFont()->setBold(true)->setSize($indice === 0 ? 14 : 12)->getColor()->setARGB('FF452FF6');
        }
    }

    $ocorrencias = $planilha->createSheet(1);
    $ocorrencias->setTitle('Ocorrências');
    estilizarCabecalho(
        $ocorrencias,
        array_map(fn($chave) => COLUNAS_OCORRENCIAS[$chave][0], array_keys(COLUNAS_OCORRENCIAS)),
        [15, 34, 12, 13, 10, 19, 30, 13, 34, 24]
    );
    for ($linha = 2; $linha <= $fim; $linha++) {
        $ocorrencias->setCellValue("B{$linha}", "=IF(\$A{$linha}=\"\",\"\",IFERROR(VLOOKUP(\$A{$linha},Alunos!\$A:\$C,2,FALSE),\"\"))");
        $ocorrencias->setCellValue("C{$linha}", "=IF(\$A{$linha}=\"\",\"\",IFERROR(VLOOKUP(\$A{$linha},Alunos!\$A:\$C,3,FALSE),\"\"))");
    }
    $ocorrencias->getStyle("A2:A{$fim}")->getNumberFormat()->setFormatCode('0');
    $ocorrencias->getStyle("D2:D{$fim}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    $ocorrencias->getStyle("E2:E{$fim}")->getNumberFormat()->setFormatCode('hh:mm');
    listaNaFaixa($ocorrencias, "F2:F{$fim}", '"Entrada atrasada,Saída antecipada"', 'Entrada atrasada ou Saída antecipada.');
    listaNaFaixa($ocorrencias, "H2:H{$fim}", '"Sim,Não"', 'Sim ou Não (em branco: Não).');

    $consulta = $planilha->createSheet(2);
    $consulta->setTitle('Alunos');
    estilizarCabecalho($consulta, ['Código do aluno', 'Nome do aluno', 'Turma', 'Período'], [15, 40, 14, 14]);
    $linha = 2;
    foreach ($alunos as $id => $aluno) {
        $consulta->setCellValue("A{$linha}", $id);
        $consulta->setCellValueExplicit("B{$linha}", $aluno['nome'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $consulta->setCellValueExplicit("C{$linha}", $aluno['turma'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $consulta->setCellValueExplicit("D{$linha}", (string) $aluno['periodo'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $linha++;
    }
    if ($alunos !== []) {
        $consulta->setAutoFilter('A1:D' . ($linha - 1));
    }

    $planilha->setActiveSheetIndex(0);
    return $planilha;
}
