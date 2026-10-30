<?php
/**
 * Cálculo de frequência/status, compartilhado entre filtroAluno.php,
 * alertas.php e dashboard.php - antes cada endpoint que precisava disso
 * tinha sua própria cópia da mesma fórmula (filtroAluno.php chegou a
 * declarar DIAS_LETIVOS_MES e calcularStatus() localmente).
 */

define('DIAS_LETIVOS_MES', 20);

function calcularFrequencia(int $qtdFaltas, int $diasLetivos = DIAS_LETIVOS_MES): int {
    if ($diasLetivos <= 0) return 100;
    return max(0, (int) round(100 - (($qtdFaltas / $diasLetivos) * 100)));
}

function calcularStatusFrequencia(int $frequencia): string {
    if ($frequencia < 85) return "Em risco";
    if ($frequencia < 90) return "Atenção";
    if ($frequencia < 98) return "Regular";
    return "Excelente";
}

/**
 * Quantos dias úteis (segunda a sexta) existem ESTRITAMENTE entre duas
 * datas Y-m-d, em qualquer ordem.
 */
function diasUteisEntre(string $dataA, string $dataB): int {
    $inicio = new DateTimeImmutable(min($dataA, $dataB));
    $fim = new DateTimeImmutable(max($dataA, $dataB));
    $uteis = 0;
    for ($dia = $inicio->modify('+1 day'); $dia < $fim; $dia = $dia->modify('+1 day')) {
        if ((int) $dia->format('N') <= 5) {
            $uteis++;
        }
    }
    return $uteis;
}

/**
 * A partir das datas (Y-m-d, no fuso da escola) de falta de um aluno,
 * estima a sequência atual de faltas consecutivas.
 *
 * Duas faltas são consecutivas quando não há nenhum dia útil entre elas
 * (sexta e a segunda seguinte contam; terça e sexta, não - quarta e
 * quinta foram dias de aula sem falta). A sequência só está "em
 * andamento" se também não houver dia útil sem falta entre a última
 * falta e hoje.
 *
 * Antes a regra era "até 3 dias corridos", o que juntava terça e sexta
 * como consecutivas. Continua sendo uma estimativa: o banco só registra
 * a ausência, não a presença, então um feriado no meio quebra a
 * sequência. A solução completa seria registrar a frequência de todo dia
 * letivo.
 *
 * Datas repetidas contam uma vez só (duas faltas lançadas no mesmo dia
 * são o mesmo dia de ausência).
 */
function calcularFaltasConsecutivas(array $datasFalta, ?string $hoje = null): int {
    $hoje = $hoje ?? date('Y-m-d');
    $datas = array_unique(array_map(fn($d) => substr((string) $d, 0, 10), $datasFalta));
    $datas = array_values(array_filter($datas, fn($d) => $d <= $hoje));
    if (empty($datas)) return 0;
    rsort($datas);

    if (diasUteisEntre($datas[0], $hoje) > 0) {
        return 0;
    }

    $sequencia = 1;
    for ($i = 1; $i < count($datas); $i++) {
        if (diasUteisEntre($datas[$i - 1], $datas[$i]) === 0) {
            $sequencia++;
        } else {
            break;
        }
    }
    return $sequencia;
}
