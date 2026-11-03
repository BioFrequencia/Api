<?php
require_once __DIR__ . '/tempo.php';

/**
 * Calendário escolar: quando há aula e em que horário.
 *
 * O BioFrequência registra entradas atrasadas e saídas antecipadas, e as
 * duas só existem num dia de aula, dentro do horário da turma. Antes nada
 * disso era conferido: a produção tinha atrasos lançados em sábados e
 * domingos e "entradas atrasadas" de uma turma da manhã às 17h - e todos
 * contavam nas porcentagens.
 *
 * Um dia é letivo quando:
 *   - é de segunda a sexta, não é feriado (nacional ou de São Paulo) e não
 *     está num período "sem aula" do calendário da escola; ou
 *   - está num período "aula extra" da escola (sábado letivo, reposição),
 *     que vale mais que tudo acima.
 *
 * As funções daqui são puras (testadas em tests/helpers_test.php), menos
 * calendarioDaEscola(), que só lê o banco.
 */

const PERIODOS_TURMA = ['Matutino', 'Vespertino', 'Noturno', 'Integral', 'Indefinido'];

/**
 * Horário de cada período quando a turma não tem um próprio (o mais
 * comum na rede estadual). A gestão ajusta o de cada turma em
 * Configurações da escola.
 */
const HORARIO_PADRAO_PERIODO = [
    'Matutino'   => ['07:00', '12:20'],
    'Vespertino' => ['13:00', '18:20'],
    'Noturno'    => ['19:00', '23:00'],
    'Integral'   => ['07:00', '16:00'],
    'Indefinido' => ['07:00', '23:00'],
];

/** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano). */
function domingoDePascoa(int $ano): string {
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
    $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

/**
 * Dias sem aula em toda a rede: feriados nacionais, o feriado estadual de
 * 9 de julho e os pontos facultativos em que a rede estadual não tem aula
 * (Carnaval e Corpus Christi). Feriado municipal, recesso e férias ficam
 * no calendário de cada escola.
 *
 * @return array<string,string> data (Y-m-d) => nome
 */
function feriadosDoAno(int $ano): array {
    static $cache = [];
    if (isset($cache[$ano])) {
        return $cache[$ano];
    }
    $pascoa = new DateTimeImmutable(domingoDePascoa($ano));
    $relativo = fn(int $dias) => $pascoa->modify(sprintf('%+d day', $dias))->format('Y-m-d');

    $feriados = [
        "$ano-01-01"   => 'Confraternização Universal',
        $relativo(-48) => 'Carnaval',
        $relativo(-47) => 'Carnaval',
        $relativo(-2)  => 'Sexta-feira Santa',
        "$ano-04-21"   => 'Tiradentes',
        "$ano-05-01"   => 'Dia do Trabalho',
        $relativo(60)  => 'Corpus Christi',
        "$ano-07-09"   => 'Revolução Constitucionalista',
        "$ano-09-07"   => 'Independência do Brasil',
        "$ano-10-12"   => 'Nossa Senhora Aparecida',
        "$ano-11-02"   => 'Finados',
        "$ano-11-15"   => 'Proclamação da República',
        "$ano-12-25"   => 'Natal',
    ];
    // Feriado nacional desde 2024 (Lei 14.759/2023).
    if ($ano >= 2024) {
        $feriados["$ano-11-20"] = 'Dia Nacional de Zumbi e da Consciência Negra';
    }
    ksort($feriados);
    return $cache[$ano] = $feriados;
}

/**
 * Se há aula na data (Y-m-d) e, se não houver, por quê.
 *
 * @param array $calendarioEscola linhas de tb_calendario_escola
 *        (data_inicio, data_fim, tipo, descricao)
 * @return array{letivo: bool, motivo: ?string}
 */
function situacaoDoDia(string $data, array $calendarioEscola = []): array {
    foreach ($calendarioEscola as $item) {
        if ($item['tipo'] === 'aula_extra' && $data >= $item['data_inicio'] && $data <= $item['data_fim']) {
            return ['letivo' => true, 'motivo' => 'Aula extra: ' . $item['descricao']];
        }
    }
    $diaDaSemana = (int) (new DateTimeImmutable($data))->format('N');
    if ($diaDaSemana >= 6) {
        return ['letivo' => false, 'motivo' => $diaDaSemana === 6 ? 'sábado' : 'domingo'];
    }
    $feriados = feriadosDoAno((int) substr($data, 0, 4));
    if (isset($feriados[$data])) {
        return ['letivo' => false, 'motivo' => 'feriado de ' . $feriados[$data]];
    }
    foreach ($calendarioEscola as $item) {
        if ($item['tipo'] === 'sem_aula' && $data >= $item['data_inicio'] && $data <= $item['data_fim']) {
            return ['letivo' => false, 'motivo' => $item['descricao']];
        }
    }
    return ['letivo' => true, 'motivo' => null];
}

/** Dias letivos (Y-m-d) de $inicio a $fim, inclusive. */
function diasLetivosEntre(string $inicio, string $fim, array $calendarioEscola = []): array {
    $dias = [];
    for ($dia = new DateTimeImmutable($inicio); $dia->format('Y-m-d') <= $fim; $dia = $dia->modify('+1 day')) {
        $data = $dia->format('Y-m-d');
        if (situacaoDoDia($data, $calendarioEscola)['letivo']) {
            $dias[] = $data;
        }
    }
    return $dias;
}

/**
 * Horário efetivo da turma ('HH:MM'): o gravado nela ou, sem ele, o
 * padrão do período.
 *
 * @return array{inicio: string, fim: string}
 */
function horarioDaTurma(?string $periodo, ?string $inicio = null, ?string $fim = null): array {
    $padrao = HORARIO_PADRAO_PERIODO[$periodo] ?? HORARIO_PADRAO_PERIODO['Indefinido'];
    return [
        'inicio' => $inicio !== null && $inicio !== '' ? substr($inicio, 0, 5) : $padrao[0],
        'fim'    => $fim !== null && $fim !== '' ? substr($fim, 0, 5) : $padrao[1],
    ];
}

function minutosDoDia(string $hora): int {
    [$h, $m] = array_map('intval', explode(':', $hora) + [0, 0]);
    return $h * 60 + $m;
}

function horaDeMinutos(int $minutos): string {
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

/** 'HH:MM' válido (00:00 a 23:59). */
function horaValida($valor): bool {
    return is_string($valor) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) === 1;
}

function dataBr(string $data): string {
    return (new DateTimeImmutable($data))->format('d/m/Y');
}

/**
 * Um registro pode existir nesse dia e horário? Devolve a explicação para
 * a coordenação, ou null quando pode.
 *
 *   'atraso' (entrada atrasada): dia letivo, depois do início da aula mais
 *            a tolerância e antes do fim. Chegar dentro da tolerância não
 *            é atraso.
 *   'saida'  (saída antecipada): dia letivo, depois do início e antes do
 *            fim da aula.
 *
 * Ex.: aluno da manhã (07:00 às 12:20) com "entrada atrasada" às 17h é
 * recusado - o caso que existia em produção.
 */
function conferirHorarioDoRegistro(
    string $tipo,
    string $dataLocal,
    string $horaLocal,
    array $situacaoDia,
    array $horario,
    int $tolerancia,
    string $turma
): ?string {
    $nome = $tipo === 'atraso' ? 'entrada atrasada' : 'saída antecipada';
    if (!$situacaoDia['letivo']) {
        return sprintf('Não há aula em %s (%s): não dá para registrar %s nesse dia.', dataBr($dataLocal), $situacaoDia['motivo'], $nome);
    }
    $minuto = minutosDoDia($horaLocal);
    $inicio = minutosDoDia($horario['inicio']);
    $fim = minutosDoDia($horario['fim']);
    if ($minuto < $inicio || $minuto >= $fim) {
        return sprintf(
            'A aula da turma %s é das %s às %s: %s só pode ser registrada nesse horário (informado: %s).',
            $turma, $horario['inicio'], $horario['fim'], $nome, $horaLocal
        );
    }
    if ($tipo === 'atraso' && $minuto <= $inicio + $tolerancia) {
        return sprintf(
            'Chegada até as %s está dentro da tolerância de %d minutos da escola e não conta como entrada atrasada.',
            horaDeMinutos($inicio + $tolerancia), $tolerancia
        );
    }
    return null;
}

/**
 * Como ler uma presença registrada pelo app: 'no_horario', 'atrasada'
 * (depois da tolerância, ainda durante a aula), 'fora_do_horario' (depois
 * do fim da aula) ou 'sem_aula'.
 */
function situacaoDaEntrada(string $horaLocal, array $situacaoDia, array $horario, int $tolerancia): string {
    if (!$situacaoDia['letivo']) {
        return 'sem_aula';
    }
    $minuto = minutosDoDia($horaLocal);
    if ($minuto >= minutosDoDia($horario['fim'])) {
        return 'fora_do_horario';
    }
    return $minuto > minutosDoDia($horario['inicio']) + $tolerancia ? 'atrasada' : 'no_horario';
}

/** Itens do calendário da escola que tocam o intervalo [$inicio, $fim] (Y-m-d). */
function calendarioDaEscola(PDO $conexao, int $idEscola, string $inicio, string $fim): array {
    $stmt = $conexao->prepare("
        SELECT id_calendario, data_inicio::text AS data_inicio, data_fim::text AS data_fim, tipo, descricao
        FROM tb_calendario_escola
        WHERE id_escola = ? AND data_fim >= ?::date AND data_inicio <= ?::date
        ORDER BY data_inicio, id_calendario
    ");
    $stmt->execute([$idEscola, $inicio, $fim]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
