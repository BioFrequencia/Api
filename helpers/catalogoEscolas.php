<?php
require_once __DIR__ . '/sessao.php';

/**
 * Catálogo das escolas estaduais de São Paulo, dos dados abertos da
 * Secretaria da Educação ("Endereços das escolas", planilha
 * ENDERECO_ESCOLAS_082026: 5.795 escolas, 91 diretorias de ensino, 644
 * municípios). No cadastro, a coordenação escolhe a escola dela aqui.
 *
 *   database/dados/escolas-sp.csv   gerado da planilha por
 *                                   database/dados/converterPlanilha.php
 *   database/importarEscolas.php    grava no banco; o docker-entrypoint.sh
 *                                   chama a cada início do container, e
 *                                   ele só trabalha quando a versão muda
 *
 * A importação nunca apaga nada: insere as escolas novas, atualiza os
 * dados oficiais (endereço, diretoria...) das que já existem e mantém o
 * nome que cada escola já usa no sistema.
 */

const VERSAO_CATALOGO_ESCOLAS = 'SEDUC-SP 08/2026';
const ARQUIVO_CATALOGO_ESCOLAS = __DIR__ . '/../database/dados/escolas-sp.csv';

/** Colunas do CSV, na ordem, e o tipo de cada uma no banco. */
const COLUNAS_CATALOGO = [
    'codigo_cie' => 'int', 'codigo_inep' => 'texto', 'nome_oficial' => 'texto', 'rede' => 'texto',
    'diretoria_ensino' => 'texto', 'municipio' => 'texto', 'codigo_ibge' => 'int', 'distrito' => 'texto',
    'tipo_escola' => 'int', 'endereco' => 'texto', 'numero' => 'texto', 'complemento' => 'texto',
    'bairro' => 'texto', 'cep' => 'texto', 'zona' => 'int', 'latitude' => 'decimal', 'longitude' => 'decimal',
    'codigo_vinculadora' => 'int',
];

/** Siglas e números romanos que ficam em maiúsculas no nome de exibição. */
const SIGLAS_NOME_ESCOLA = [
    'EE', 'EMEF', 'EMEI', 'CEL', 'EPF', 'CIP', 'CI', 'CEEJA', 'CIEJA', 'ETEC', 'CEU', 'SESI', 'CAIC', 'APAE',
    'II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'XI', 'XII', 'XIII', 'XIV', 'XV', 'XVI', 'XVII', 'XVIII', 'XIX', 'XX', 'XXI', 'XXIII',
];
const PALAVRAS_MINUSCULAS_NOME = ['DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'EM', 'NA', 'NO', 'NAS', 'NOS', 'A', 'O', 'AS', 'OS', 'AO', 'AOS', 'COM'];
const ABREVIACOES_NOME_ESCOLA = [
    'PROF' => 'Prof.', 'PROFA' => 'Profa.', 'DR' => 'Dr.', 'DRA' => 'Dra.', 'PE' => 'Pe.', 'ENG' => 'Eng.',
    'STA' => 'Sta.', 'STO' => 'Sto.', 'SR' => 'Sr.', 'SRA' => 'Sra.', 'D' => 'D.',
];

/** Maiúsculas, sem acento e sem pontuação - para busca e comparação. */
function normalizarTextoBusca(string $texto): string {
    $texto = strtr(mb_strtoupper($texto), [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N', 'º' => 'O', 'ª' => 'A',
    ]);
    $texto = preg_replace('/[^A-Z0-9 ]+/', ' ', $texto);
    return trim(preg_replace('/\s+/', ' ', $texto));
}

/**
 * Chave para reconhecer a mesma escola escrita de jeitos diferentes:
 * "E.E. Maria Nilde Mascellani" (cadastrada à mão) e "EE MARIA NILDE
 * MASCELLANI" (planilha) viram "MARIA NILDE MASCELLANI".
 */
function chaveNomeEscola(string $nome): string {
    return preg_replace('/^(E E|EE|ESCOLA ESTADUAL)\s+/', '', normalizarTextoBusca($nome));
}

/**
 * Nome para mostrar na tela a partir do nome oficial em maiúsculas:
 * "FLEURIDES CAVALLINI MENECHINO PROFA" (tipo 8, escola estadual) vira
 * "E.E. Fleurides Cavallini Menechino Profa.". Função pura.
 */
function nomeDeExibicaoEscola(string $nomeOficial, ?int $tipo): string {
    $palavras = preg_split('/\s+/', trim($nomeOficial), -1, PREG_SPLIT_NO_EMPTY);
    $saida = [];
    foreach ($palavras as $i => $palavra) {
        $maiuscula = mb_strtoupper($palavra);
        if (isset(ABREVIACOES_NOME_ESCOLA[$maiuscula])) {
            $saida[] = ABREVIACOES_NOME_ESCOLA[$maiuscula];
        } elseif (in_array($maiuscula, SIGLAS_NOME_ESCOLA, true) || preg_match('/\d/', $palavra)) {
            $saida[] = $maiuscula;
        } elseif ($i > 0 && in_array($maiuscula, PALAVRAS_MINUSCULAS_NOME, true)) {
            $saida[] = mb_strtolower($palavra);
        } else {
            $saida[] = mb_convert_case(mb_strtolower($palavra), MB_CASE_TITLE);
        }
    }
    $nome = implode(' ', $saida);
    // Escola estadual comum: a planilha quase sempre traz o nome sem o "EE".
    if ($tipo === 8) {
        $nome = 'E.E. ' . preg_replace('/^EE\s+/', '', $nome);
    }
    return mb_substr($nome, 0, 150);
}

/** @return array<int, array<string,?string>> linhas do CSV, com as colunas de COLUNAS_CATALOGO */
function lerCatalogoCsv(string $arquivo): array {
    $handle = fopen($arquivo, 'r');
    if ($handle === false) {
        throw new RuntimeException("Não foi possível abrir $arquivo.");
    }
    $cabecalho = fgetcsv($handle, null, ',', '"', '');
    if ($cabecalho !== array_keys(COLUNAS_CATALOGO)) {
        fclose($handle);
        throw new RuntimeException('Cabeçalho do catálogo diferente do esperado.');
    }
    $linhas = [];
    while (($valores = fgetcsv($handle, null, ',', '"', '')) !== false) {
        if ($valores === [null] || count($valores) !== count($cabecalho)) {
            continue;
        }
        $linhas[] = array_combine($cabecalho, array_map(fn($v) => trim((string) $v) === '' ? null : trim((string) $v), $valores));
    }
    fclose($handle);
    return $linhas;
}

function catalogoJaImportado(PDO $conexao, string $versao = VERSAO_CATALOGO_ESCOLAS): bool {
    $stmt = $conexao->prepare("SELECT 1 FROM tb_catalogo_escolas WHERE versao = ?");
    $stmt->execute([$versao]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Grava o catálogo no banco, numa transação só.
 *
 *  1. Escola cadastrada à mão, sem código, cujo nome bate com UMA escola
 *     do catálogo (ex.: a escola 1 de produção) recebe o código - vira a
 *     mesma escola, com as turmas e alunos que já tinha.
 *  2. Upsert por código CIE, em lotes: escola nova entra com o nome de
 *     exibição; a que já existe tem só os dados oficiais atualizados.
 *  3. Registra a versão importada (tb_catalogo_escolas).
 *
 * @return array{versao:string, total:int, inseridas:int, atualizadas:int, vinculadas:int}
 */
function importarCatalogoEscolas(PDO $conexao, ?string $arquivo = null, string $versao = VERSAO_CATALOGO_ESCOLAS): array {
    $linhas = lerCatalogoCsv($arquivo ?? ARQUIVO_CATALOGO_ESCOLAS);
    $resumo = ['versao' => $versao, 'total' => count($linhas), 'inseridas' => 0, 'atualizadas' => 0, 'vinculadas' => 0];

    $codigosPorChave = [];
    foreach ($linhas as $linha) {
        $codigosPorChave[chaveNomeEscola($linha['nome_oficial'])][] = (int) $linha['codigo_cie'];
    }

    $conexao->beginTransaction();
    try {
        $usados = array_fill_keys(array_map('intval', $conexao->query(
            "SELECT codigo_cie FROM tb_escola WHERE codigo_cie IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN)), true);
        $vincular = $conexao->prepare("UPDATE tb_escola SET codigo_cie = ? WHERE id_escola = ? AND codigo_cie IS NULL");
        foreach ($conexao->query("SELECT id_escola, nome_escola FROM tb_escola WHERE codigo_cie IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $escola) {
            $codigos = array_values(array_unique($codigosPorChave[chaveNomeEscola($escola['nome_escola'])] ?? []));
            if (count($codigos) === 1 && !isset($usados[$codigos[0]])) {
                $vincular->execute([$codigos[0], $escola['id_escola']]);
                $usados[$codigos[0]] = true;
                $resumo['vinculadas']++;
            }
        }

        $colunas = array_keys(COLUNAS_CATALOGO);
        $atualizar = implode(', ', array_map(fn($c) => "$c = EXCLUDED.$c", array_diff($colunas, ['codigo_cie'])));
        foreach (array_chunk($linhas, 400) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '(' . implode(', ', array_fill(0, count($colunas) + 1, '?')) . ', NOW())'));
            $valores = [];
            foreach ($lote as $linha) {
                $valores[] = nomeDeExibicaoEscola($linha['nome_oficial'], $linha['tipo_escola'] === null ? null : (int) $linha['tipo_escola']);
                foreach (COLUNAS_CATALOGO as $coluna => $tipo) {
                    $valor = $linha[$coluna];
                    $valores[] = $valor === null ? null : ($tipo === 'int' ? (int) $valor : $valor);
                }
            }
            $stmt = $conexao->prepare("
                INSERT INTO tb_escola (nome_escola, " . implode(', ', $colunas) . ", atualizado_em)
                VALUES $marcadores
                ON CONFLICT (codigo_cie) DO UPDATE SET $atualizar, atualizado_em = NOW()
                RETURNING (xmax = 0) AS inserida
            ");
            $stmt->execute($valores);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $inserida) {
                valorBooleano($inserida) ? $resumo['inseridas']++ : $resumo['atualizadas']++;
            }
        }

        $registro = $conexao->prepare("
            INSERT INTO tb_catalogo_escolas (versao, total) VALUES (?, ?)
            ON CONFLICT (versao) DO UPDATE SET total = EXCLUDED.total, importado_em = NOW()
        ");
        $registro->execute([$versao, $resumo['total']]);
        $conexao->commit();
    } catch (Throwable $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        throw $erro;
    }
    return $resumo;
}
