<?php
/**
 * Regras da conta da coordenação (sessões, preferências e suporte) que
 * não dependem de banco - testadas em tests/helpers_test.php.
 */

/** Sessão do site: 2 horas. */
const DURACAO_SESSAO_SITE = 60 * 120;
/** Site com "Lembrar de mim": 30 dias (a sessão pode ser encerrada antes, em Minha conta). */
const DURACAO_SESSAO_LEMBRAR = 60 * 60 * 24 * 30;
/** Sessão do app de biometria, num tablet da escola durante o turno: 12 horas. */
const DURACAO_SESSAO_APP = 60 * 60 * 12;

const TEMAS_ACEITOS = ['claro', 'escuro', 'sistema'];

/** Preferências de quem ainda não salvou nenhuma. */
const PREFERENCIAS_PADRAO = [
    'tema'            => 'sistema',
    'sons_ativos'     => true,
    'volume_sons'     => 70,
    'som_confirmacao' => true,
    'som_notificacao' => true,
    'som_erro'        => true,
];

const CATEGORIAS_CHAMADO = ['duvida', 'problema', 'biometria', 'conta', 'sugestao'];

/** Quantos chamados uma coordenação pode abrir em 24 horas. */
const LIMITE_CHAMADOS_POR_DIA = 10;

function duracaoDaSessao(string $cliente, bool $lembrar): int {
    if ($cliente === 'app') {
        return DURACAO_SESSAO_APP;
    }
    return $lembrar ? DURACAO_SESSAO_LEMBRAR : DURACAO_SESSAO_SITE;
}

/**
 * Nome do aparelho na lista de dispositivos conectados ("Chrome no
 * Windows"). Só o navegador e o sistema, tirados do User-Agent - nada que
 * identifique a pessoa. A ordem dos testes importa: o Edge também diz
 * "Chrome", o Chrome também diz "Safari" e o Android também diz "Linux".
 */
function descreverDispositivo(?string $userAgent, string $cliente): string {
    if ($cliente === 'app') {
        return 'App BioFrequência (Android)';
    }
    $ua = (string) $userAgent;
    $navegador = match (true) {
        str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
        str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'Navegador',
    };
    $sistema = match (true) {
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'CrOS') => 'ChromeOS',
        str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => null,
    };
    return $sistema === null ? $navegador : "$navegador no $sistema";
}

/**
 * Preferências enviadas pelo site, conferidas campo a campo. O que não
 * veio continua como está em $atuais.
 *
 * @return array{0: ?array, 1: ?string} [preferências, mensagem de erro]
 */
function lerPreferencias(array $dados, array $atuais): array {
    $resultado = $atuais;
    if (array_key_exists('tema', $dados)) {
        if (!in_array($dados['tema'], TEMAS_ACEITOS, true)) {
            return [null, 'Tema inválido: use claro, escuro ou sistema.'];
        }
        $resultado['tema'] = $dados['tema'];
    }
    foreach (['sons_ativos', 'som_confirmacao', 'som_notificacao', 'som_erro'] as $campo) {
        if (array_key_exists($campo, $dados)) {
            if (!is_bool($dados[$campo])) {
                return [null, "O campo $campo precisa ser verdadeiro ou falso."];
            }
            $resultado[$campo] = $dados[$campo];
        }
    }
    if (array_key_exists('volume_sons', $dados)) {
        $volume = $dados['volume_sons'];
        if (!is_int($volume) || $volume < 0 || $volume > 100) {
            return [null, 'O volume precisa ser um número inteiro de 0 a 100.'];
        }
        $resultado['volume_sons'] = $volume;
    }
    return [$resultado, null];
}

/**
 * Chamado de suporte enviado pelo site, conferido e com espaços aparados.
 *
 * @return array{0: ?array, 1: ?string} [chamado, mensagem de erro]
 */
function lerChamado(array $dados): array {
    $categoria = $dados['categoria'] ?? null;
    $assunto = is_string($dados['assunto'] ?? null) ? trim($dados['assunto']) : '';
    $mensagem = is_string($dados['mensagem'] ?? null) ? trim($dados['mensagem']) : '';
    if (!in_array($categoria, CATEGORIAS_CHAMADO, true)) {
        return [null, 'Escolha a categoria do chamado.'];
    }
    if (mb_strlen($assunto) < 3 || mb_strlen($assunto) > 120) {
        return [null, 'O assunto precisa ter de 3 a 120 caracteres.'];
    }
    if (mb_strlen($mensagem) < 10 || mb_strlen($mensagem) > 4000) {
        return [null, 'Descreva o pedido com 10 a 4000 caracteres.'];
    }
    return [['categoria' => $categoria, 'assunto' => $assunto, 'mensagem' => $mensagem], null];
}
