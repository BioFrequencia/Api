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
 * A partir das datas (Y-m-d) de falta de um aluno, da mais recente para
 * a mais antiga, estima a sequência atual de faltas consecutivas.
 *
 * Limitação conhecida: o banco só registra o evento negativo (falta),
 * não existe uma tabela de presença positiva - então um "gap" de até 3
 * dias corridos é tolerado como fim de semana, mas isso também tolera
 * (incorretamente) um único dia útil isolado em que o aluno esteve
 * presente entre duas faltas não consecutivas. Documentado como
 * pendência no relatório final; a forma correta de resolver isso é
 * registrar frequência positiva por dia letivo, não só ausências.
 */
function calcularFaltasConsecutivas(array $datasFaltaDesc): int {
    if (empty($datasFaltaDesc)) return 0;

    $hoje = new DateTime('today');
    $maisRecente = new DateTime($datasFaltaDesc[0]);
    if ((int)$hoje->diff($maisRecente)->format('%a') > 3) return 0;

    $sequencia = 1;
    $anterior = $maisRecente;
    for ($i = 1; $i < count($datasFaltaDesc); $i++) {
        $atual = new DateTime($datasFaltaDesc[$i]);
        $gap = (int)$anterior->diff($atual)->format('%a');
        if ($gap <= 3) {
            $sequencia++;
            $anterior = $atual;
        } else {
            break;
        }
    }
    return $sequencia;
}
