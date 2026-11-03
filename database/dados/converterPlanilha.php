<?php
/**
 * Converte a planilha de endereços das escolas da SEDUC-SP (.xlsx, dados
 * abertos) no CSV que database/importarEscolas.php importa.
 *
 *   php database/dados/converterPlanilha.php ENDERECO_ESCOLAS_082026.xlsx
 *
 * Grava database/dados/escolas-sp.csv. Quando sair uma planilha nova,
 * rode de novo e troque VERSAO_CATALOGO_ESCOLAS em
 * helpers/catalogoEscolas.php: o próximo início do container importa a
 * versão nova sozinho.
 *
 * Lê o .xlsx com PharData (zip nativo do PHP), sem extensão zip nem
 * biblioteca de planilha.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../helpers/catalogoEscolas.php';

$entrada = $argv[1] ?? null;
if ($entrada === null || !is_file($entrada)) {
    fwrite(STDERR, "Uso: php database/dados/converterPlanilha.php <planilha.xlsx>\n");
    exit(2);
}

// Coluna da planilha da SEDUC -> coluna do catálogo.
const DE_PARA = [
    'CODESC' => 'codigo_cie', 'CODESCMEC' => 'codigo_inep', 'NOMESC' => 'nome_oficial', 'NOMEDEP' => 'rede',
    'URE' => 'diretoria_ensino', 'MUN' => 'municipio', 'CD_IBGE' => 'codigo_ibge', 'DISTR' => 'distrito',
    'TIPOESC' => 'tipo_escola', 'ENDESC' => 'endereco', 'NUMESC' => 'numero', 'COMPLEND' => 'complemento',
    'BAIESC' => 'bairro', 'CEP' => 'cep', 'ZONA' => 'zona', 'DS_LATITUDE' => 'latitude',
    'DS_LONGITUDE' => 'longitude', 'CODVINC' => 'codigo_vinculadora',
];

// PharData decide o formato pela extensão: uma cópia .zip da planilha.
$copia = sys_get_temp_dir() . '/planilha-escolas-' . bin2hex(random_bytes(4)) . '.zip';
copy($entrada, $copia);
$zip = new PharData($copia);
$ler = fn(string $caminho) => file_get_contents($zip[$caminho]->getPathname());

$compartilhadas = [];
$xml = simplexml_load_string($ler('xl/sharedStrings.xml'));
foreach ($xml->si as $si) {
    $texto = (string) $si->t;
    foreach ($si->r as $trecho) {
        $texto .= (string) $trecho->t;
    }
    $compartilhadas[] = $texto;
}

$planilha = simplexml_load_string($ler('xl/worksheets/sheet1.xml'));
$linhas = [];
foreach ($planilha->sheetData->row as $row) {
    $valores = [];
    foreach ($row->c as $celula) {
        preg_match('/^[A-Z]+/', (string) $celula['r'], $m);
        $coluna = 0;
        foreach (str_split($m[0]) as $letra) {
            $coluna = $coluna * 26 + (ord($letra) - 64);
        }
        $valor = (string) $celula->v;
        $valores[$coluna - 1] = (string) $celula['t'] === 's' ? $compartilhadas[(int) $valor] : $valor;
    }
    $linhas[] = $valores;
}
unset($zip);
@unlink($copia);

$cabecalho = array_map('trim', array_shift($linhas));
$indices = [];
foreach (DE_PARA as $origem => $destino) {
    $indice = array_search($origem, $cabecalho, true);
    if ($indice === false) {
        fwrite(STDERR, "Coluna $origem não encontrada na planilha.\n");
        exit(1);
    }
    $indices[$destino] = $indice;
}

$saida = fopen(__DIR__ . '/escolas-sp.csv', 'w');
fputcsv($saida, array_keys(COLUNAS_CATALOGO), ',', '"', '');
$total = 0;
foreach ($linhas as $valores) {
    $linha = [];
    foreach (array_keys(COLUNAS_CATALOGO) as $coluna) {
        $linha[$coluna] = preg_replace('/\s+/', ' ', trim((string) ($valores[$indices[$coluna]] ?? '')));
    }
    if ($linha['codigo_cie'] === '' || $linha['nome_oficial'] === '') {
        continue;
    }
    $linha['cep'] = preg_replace('/\D/', '', $linha['cep']);
    foreach (['latitude', 'longitude'] as $coordenada) {
        $linha[$coordenada] = is_numeric($linha[$coordenada]) ? number_format((float) $linha[$coordenada], 6, '.', '') : '';
    }
    fputcsv($saida, array_values($linha), ',', '"', '');
    $total++;
}
fclose($saida);
echo "$total escolas gravadas em database/dados/escolas-sp.csv\n";
