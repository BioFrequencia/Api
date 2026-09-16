<?php
/**
 * Testes unitários puros (sem banco, sem rede) dos helpers da API.
 * Não usa nenhum framework de propósito - são poucos testes, um script
 * simples com asserts é suficiente e roda em qualquer PHP sem depender
 * de Composer dev-dependencies.
 *
 * Rodar: php tests/helpers_test.php
 */

require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/frequencia.php';
require_once __DIR__ . '/../helpers/biometriaFacial.php';

$totalTestes = 0;
$falhas = [];

function afirmar(string $descricao, bool $condicao): void {
    global $totalTestes, $falhas;
    $totalTestes++;
    if (!$condicao) {
        $falhas[] = $descricao;
    }
}

// ---------- campoPreenchido ----------
afirmar('campoPreenchido: string normal', campoPreenchido('João') === true);
afirmar('campoPreenchido: string vazia', campoPreenchido('') === false);
afirmar('campoPreenchido: só espaços', campoPreenchido('   ') === false);
afirmar('campoPreenchido: null', campoPreenchido(null) === false);
afirmar('campoPreenchido: número (não é string)', campoPreenchido(123) === false);

// ---------- emailValido ----------
afirmar('emailValido: email correto', emailValido('a@b.com') === true);
afirmar('emailValido: sem @', emailValido('a.com') === false);
afirmar('emailValido: vazio', emailValido('') === false);
afirmar('emailValido: null', emailValido(null) === false);
afirmar('emailValido: com espaço nas pontas', emailValido('  a@b.com  ') === true);

// ---------- senhaForte ----------
afirmar('senhaForte: 6+ caracteres', senhaForte('senha123') === true);
afirmar('senhaForte: menos de 6', senhaForte('abc') === false);
afirmar('senhaForte: vazia', senhaForte('') === false);
afirmar('senhaForte: limite customizado', senhaForte('12345678', 8) === true);

// ---------- inteiroValido ----------
afirmar('inteiroValido: inteiro positivo', inteiroValido(5) === true);
afirmar('inteiroValido: string numérica', inteiroValido('5') === true);
afirmar('inteiroValido: zero é inválido (ids começam em 1)', inteiroValido(0) === false);
afirmar('inteiroValido: negativo', inteiroValido(-3) === false);
afirmar('inteiroValido: decimal', inteiroValido(1.5) === false);
afirmar('inteiroValido: texto não numérico', inteiroValido('abc') === false);
afirmar('inteiroValido: null', inteiroValido(null) === false);

// ---------- dataNascimentoValida ----------
afirmar('dataNascimentoValida: data válida no passado', dataNascimentoValida('2012-05-10') === true);
afirmar('dataNascimentoValida: data futura', dataNascimentoValida(date('Y-m-d', strtotime('+1 day'))) === false);
afirmar('dataNascimentoValida: 30 de fevereiro (não existe)', dataNascimentoValida('2024-02-30') === false);
afirmar('dataNascimentoValida: formato errado', dataNascimentoValida('10/05/2012') === false);
afirmar('dataNascimentoValida: idade implausível (>120 anos)', dataNascimentoValida('1800-01-01') === false);
afirmar('dataNascimentoValida: vazia', dataNascimentoValida('') === false);
afirmar('dataNascimentoValida: hoje é válido (recém-nascido)', dataNascimentoValida(date('Y-m-d')) === true);

// ---------- calcularFrequencia / calcularStatusFrequencia ----------
afirmar('calcularFrequencia: 0 faltas = 100%', calcularFrequencia(0) === 100);
afirmar('calcularFrequencia: 20 faltas (todos os dias letivos) = 0%', calcularFrequencia(20) === 0);
afirmar('calcularFrequencia: nunca fica negativo mesmo com mais faltas que dias letivos', calcularFrequencia(50) === 0);
afirmar('calcularStatusFrequencia: 100% = Excelente', calcularStatusFrequencia(100) === 'Excelente');
afirmar('calcularStatusFrequencia: 95% = Regular', calcularStatusFrequencia(95) === 'Regular');
afirmar('calcularStatusFrequencia: 87% = Atenção', calcularStatusFrequencia(87) === 'Atenção');
afirmar('calcularStatusFrequencia: 80% = Em risco', calcularStatusFrequencia(80) === 'Em risco');
afirmar('calcularStatusFrequencia: limite exato 85% não é Em risco', calcularStatusFrequencia(85) === 'Atenção');
afirmar('calcularStatusFrequencia: limite exato 98% é Excelente', calcularStatusFrequencia(98) === 'Excelente');

// ---------- calcularFaltasConsecutivas ----------
afirmar('calcularFaltasConsecutivas: lista vazia = 0', calcularFaltasConsecutivas([]) === 0);
afirmar(
    'calcularFaltasConsecutivas: 3 dias seguidos (hoje, ontem, anteontem)',
    calcularFaltasConsecutivas([date('Y-m-d'), date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-2 day'))]) === 3
);
afirmar(
    'calcularFaltasConsecutivas: só uma falta há muito tempo = sequência encerrada (0)',
    calcularFaltasConsecutivas([date('Y-m-d', strtotime('-30 days'))]) === 0
);
afirmar(
    'calcularFaltasConsecutivas: sexta + segunda seguinte conta como consecutivo (tolera fim de semana)',
    calcularFaltasConsecutivas(['2026-09-14', '2026-09-11']) === 2 // segunda, sexta anterior
);
afirmar(
    'calcularFaltasConsecutivas: gap de mais de 3 dias corridos quebra a sequência',
    calcularFaltasConsecutivas([date('Y-m-d'), date('Y-m-d', strtotime('-6 days'))]) === 1
);

// ---------- similaridadeCosseno ----------
afirmar('similaridadeCosseno: vetor idêntico a si mesmo = 1.0', abs(similaridadeCosseno([1, 2, 3], [1, 2, 3]) - 1.0) < 0.0001);
afirmar('similaridadeCosseno: vetores ortogonais = 0.0', abs(similaridadeCosseno([1, 0], [0, 1]) - 0.0) < 0.0001);
afirmar('similaridadeCosseno: vetores opostos = -1.0', abs(similaridadeCosseno([1, 1], [-1, -1]) - (-1.0)) < 0.0001);
afirmar('similaridadeCosseno: tamanhos diferentes = 0.0 (não quebra)', similaridadeCosseno([1, 2], [1, 2, 3]) === 0.0);
afirmar('similaridadeCosseno: vetor de zeros = 0.0 (não divide por zero)', similaridadeCosseno([0, 0], [1, 1]) === 0.0);

// ---------- embeddingValido ----------
afirmar('embeddingValido: array numérico de tamanho razoável', embeddingValido(array_fill(0, 128, 0.5)) === true);
afirmar('embeddingValido: array pequeno demais (< 8)', embeddingValido([1, 2, 3]) === false);
afirmar('embeddingValido: não é array', embeddingValido('não é array') === false);
afirmar('embeddingValido: contém elemento não numérico', embeddingValido([1, 2, 3, 4, 5, 6, 7, 'oito']) === false);

// ---------- resultado ----------
$passou = $totalTestes - count($falhas);
echo "Resultado: $passou/$totalTestes testes passaram.\n";
if (!empty($falhas)) {
    echo "\nFALHARAM:\n";
    foreach ($falhas as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
exit(0);
