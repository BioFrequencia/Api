<?php
/**
 * Relógio e fuso horário da API.
 *
 * O banco (Supabase) roda em UTC e as colunas de data são TIMESTAMP sem
 * fuso: NOW() grava o horário UTC. O PHP precisa usar o MESMO relógio -
 * senão uma conta feita no PHP e comparada com um valor gravado pelo
 * banco dá errado. Foi o que aconteceu com os códigos de vínculo: no
 * XAMPP (date.timezone=Europe/Berlin) "agora + 15 minutos" virou, no
 * banco em UTC, um código válido por 2h15 (conferido nos registros de
 * tb_codigo_vinculo de produção).
 *
 * Regras daqui para frente:
 *  1. O PHP usa UTC, fixado aqui, independente do php.ini da máquina.
 *  2. Tudo que é gravado ou comparado com colunas do banco está em UTC.
 *  3. "Dia" e "mês" para a escola são os do fuso da escola
 *     (America/Sao_Paulo): uma falta lançada às 22h de terça é de terça,
 *     e não da quarta, como seria em UTC.
 */
date_default_timezone_set('UTC');

const FUSO_ESCOLA = 'America/Sao_Paulo';

function fusoEscola(): DateTimeZone {
    return new DateTimeZone(FUSO_ESCOLA);
}

function agoraUtc(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

/** Data de hoje (Y-m-d) no fuso da escola. */
function hojeNaEscola(?DateTimeImmutable $agora = null): string {
    return ($agora ?? agoraUtc())->setTimezone(fusoEscola())->format('Y-m-d');
}

/** Data (Y-m-d) no fuso da escola de um horário gravado em UTC. */
function dataNaEscola(string $momentoUtc): string {
    return (new DateTimeImmutable($momentoUtc, new DateTimeZone('UTC')))
        ->setTimezone(fusoEscola())
        ->format('Y-m-d');
}

/**
 * Converte um horário local da escola ('Y-m-d' = meia-noite, ou
 * 'Y-m-d H:i[:s]') no texto UTC usado nas colunas do banco.
 */
function localParaUtc(string $momentoLocal): string {
    return (new DateTimeImmutable($momentoLocal, fusoEscola()))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

/**
 * Início de um mês no fuso da escola, convertido para UTC (para comparar
 * com as colunas). 0 = mês corrente, 1 = mês passado, -1 = próximo mês.
 */
function inicioDoMesUtc(int $mesesAtras = 0, ?DateTimeImmutable $agora = null): string {
    $inicio = ($agora ?? agoraUtc())
        ->setTimezone(fusoEscola())
        ->modify('first day of this month')
        ->setTime(0, 0);
    if ($mesesAtras !== 0) {
        $inicio = $inicio->modify(sprintf('%+d month', -$mesesAtras));
    }
    return $inicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Mesmo mês de inicioDoMesUtc(), como data local (Y-m-01) - para rótulos. */
function inicioDoMesLocal(int $mesesAtras = 0, ?DateTimeImmutable $agora = null): string {
    return (new DateTimeImmutable(inicioDoMesUtc($mesesAtras, $agora), new DateTimeZone('UTC')))
        ->setTimezone(fusoEscola())
        ->format('Y-m-d');
}

/** Início (00:00 no fuso da escola) de N dias atrás, em UTC. */
function inicioDoDiaUtc(int $diasAtras = 0, ?DateTimeImmutable $agora = null): string {
    $local = ($agora ?? agoraUtc())->setTimezone(fusoEscola())->setTime(0, 0);
    if ($diasAtras !== 0) {
        $local = $local->modify(sprintf('%+d day', -$diasAtras));
    }
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Expressão SQL que dá a data, no fuso da escola, de uma coluna
 * TIMESTAMP gravada em UTC. Usada para agrupar e deduplicar por dia.
 * $coluna vem sempre do próprio código (nunca do cliente).
 */
function sqlDataNaEscola(string $coluna): string {
    return "((" . $coluna . ") AT TIME ZONE 'UTC' AT TIME ZONE '" . FUSO_ESCOLA . "')::date";
}

/** true para 'Y-m-d' de uma data que existe no calendário. */
function dataIsoValida($valor): bool {
    if (!is_string($valor)) return false;
    $dt = DateTime::createFromFormat('!Y-m-d', $valor);
    return $dt !== false && $dt->format('Y-m-d') === $valor;
}
