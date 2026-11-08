<?php
require_once __DIR__ . '/sessao.php';

/**
 * Configuração de cada escola (tb_configuracao_escola, migração 008),
 * alterada pela gestão em Configurações da escola. Escola sem linha na
 * tabela usa os valores padrão daqui.
 *
 *   tolerancia_minutos         chegar até X minutos depois do início não
 *                              é entrada atrasada
 *   limite_alerta_percentual   % dos dias de aula com ocorrência que vira
 *                              alerta e aviso ao responsável
 *   minimo_ocorrencias_alerta  e com pelo menos N ocorrências (1 atraso
 *                              em 2 dias de aula não é "50%" de nada)
 *   janela_alerta_dias         olhando os últimos N dias
 *   intervalo_aviso_dias       no máximo um aviso automático a cada N dias
 *   atraso_pela_presenca       presença registrada pelo app depois da
 *                              tolerância já vira entrada atrasada
 *
 * Avisos automáticos aos responsáveis, um por tipo (migração 011):
 *   aviso_entrada_atrasada     a cada entrada atrasada
 *   aviso_saida_antecipada     a cada saída antecipada
 *   aviso_limite_atrasos       ao passar do limite de entradas atrasadas
 *   aviso_limite_saidas        ao passar do limite de saídas antecipadas
 * As duas chaves de antes continuam para os programas antigos:
 * aviso_cada_registro (algum aviso por registro ligado) e aviso_automatico
 * (algum aviso de limite ligado).
 */

const CONFIGURACAO_PADRAO = [
    'tolerancia_minutos'        => 10,
    'limite_alerta_percentual'  => 20,
    'minimo_ocorrencias_alerta' => 3,
    'janela_alerta_dias'        => 30,
    'intervalo_aviso_dias'      => 7,
    'aviso_entrada_atrasada'    => false,
    'aviso_saida_antecipada'    => false,
    'aviso_limite_atrasos'      => true,
    'aviso_limite_saidas'       => true,
    'aviso_automatico'          => true,
    'aviso_cada_registro'       => false,
    'atraso_pela_presenca'      => true,
];

/** Cada tipo de aviso e a chave antiga que ele substituiu. */
const AVISOS_POR_TIPO = [
    'aviso_entrada_atrasada' => 'aviso_cada_registro',
    'aviso_saida_antecipada' => 'aviso_cada_registro',
    'aviso_limite_atrasos'   => 'aviso_automatico',
    'aviso_limite_saidas'    => 'aviso_automatico',
];

/** As duas chaves antigas, tiradas dos tipos: ligadas se algum tipo delas estiver. */
function comChavesAntigas(array $config): array {
    $config['aviso_cada_registro'] = $config['aviso_entrada_atrasada'] || $config['aviso_saida_antecipada'];
    $config['aviso_automatico'] = $config['aviso_limite_atrasos'] || $config['aviso_limite_saidas'];
    return $config;
}

/** Faixa aceita de cada campo numérico (as mesmas restrições CHECK do banco). */
const FAIXAS_CONFIGURACAO = [
    'tolerancia_minutos'        => [0, 60],
    'limite_alerta_percentual'  => [1, 100],
    'minimo_ocorrencias_alerta' => [1, 30],
    'janela_alerta_dias'        => [7, 120],
    'intervalo_aviso_dias'      => [1, 60],
];

/** Linha do banco (ou nada) -> configuração completa, com os tipos certos. */
function normalizarConfiguracao(?array $linha): array {
    $config = CONFIGURACAO_PADRAO;
    foreach (CONFIGURACAO_PADRAO as $campo => $padrao) {
        if ($linha === null || !array_key_exists($campo, $linha) || $linha[$campo] === null) {
            continue;
        }
        $config[$campo] = is_bool($padrao) ? valorBooleano($linha[$campo]) : (int) $linha[$campo];
    }
    // Linha sem os tipos (banco sem a migração 011): valem as chaves antigas.
    foreach (AVISOS_POR_TIPO as $tipo => $antiga) {
        if ($linha !== null && ($linha[$tipo] ?? null) === null && ($linha[$antiga] ?? null) !== null) {
            $config[$tipo] = valorBooleano($linha[$antiga]);
        }
    }
    return comChavesAntigas($config);
}

/**
 * Lê uma alteração parcial vinda do site (só os campos enviados mudam).
 * Função pura.
 *
 * @return array{0: array, 1: ?string} [configuração nova, erro]
 */
function lerConfiguracao(array $dados, array $atual): array {
    $nova = $atual;
    foreach (FAIXAS_CONFIGURACAO as $campo => [$minimo, $maximo]) {
        if (!array_key_exists($campo, $dados)) {
            continue;
        }
        $valor = $dados[$campo];
        if (!is_int($valor) && !(is_string($valor) && ctype_digit($valor))) {
            return [$atual, "Valor inválido em $campo."];
        }
        $valor = (int) $valor;
        if ($valor < $minimo || $valor > $maximo) {
            return [$atual, "O campo $campo deve ficar entre $minimo e $maximo."];
        }
        $nova[$campo] = $valor;
    }
    $booleanos = [...array_keys(AVISOS_POR_TIPO), 'aviso_automatico', 'aviso_cada_registro', 'atraso_pela_presenca'];
    foreach ($booleanos as $campo) {
        if (array_key_exists($campo, $dados) && !is_bool($dados[$campo])) {
            return [$atual, "Valor inválido em $campo (use verdadeiro ou falso)."];
        }
    }
    // Programa antigo: só conhece as duas chaves e manda todas a cada
    // "Salvar". Elas valem para os dois tipos só quando MUDARAM - senão a
    // escolha por tipo feita no site seria desfeita a cada gravação.
    $tiposDaAntiga = ['aviso_cada_registro' => ['aviso_entrada_atrasada', 'aviso_saida_antecipada'],
                      'aviso_automatico' => ['aviso_limite_atrasos', 'aviso_limite_saidas']];
    foreach ($tiposDaAntiga as $antiga => $tipos) {
        $mandouTipo = array_key_exists($tipos[0], $dados) || array_key_exists($tipos[1], $dados);
        if (array_key_exists($antiga, $dados) && !$mandouTipo && $dados[$antiga] !== $atual[$antiga]) {
            $nova[$tipos[0]] = $nova[$tipos[1]] = $dados[$antiga];
        }
    }
    foreach ([...array_keys(AVISOS_POR_TIPO), 'atraso_pela_presenca'] as $campo) {
        if (array_key_exists($campo, $dados)) {
            $nova[$campo] = $dados[$campo];
        }
    }
    return [comChavesAntigas($nova), null];
}

function configuracaoDaEscola(PDO $conexao, int $idEscola): array {
    $stmt = $conexao->prepare("SELECT * FROM tb_configuracao_escola WHERE id_escola = ?");
    $stmt->execute([$idEscola]);
    return normalizarConfiguracao($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}

function salvarConfiguracaoDaEscola(PDO $conexao, int $idEscola, array $config, int $idCoordenacao): void {
    // Booleanos como texto 'true'/'false' (ver helpers/registroEvento.php).
    $texto = fn(bool $v) => $v ? 'true' : 'false';
    $stmt = $conexao->prepare("
        INSERT INTO tb_configuracao_escola (
            id_escola, tolerancia_minutos, limite_alerta_percentual, minimo_ocorrencias_alerta, janela_alerta_dias,
            intervalo_aviso_dias, aviso_automatico, aviso_cada_registro, atraso_pela_presenca,
            aviso_entrada_atrasada, aviso_saida_antecipada, aviso_limite_atrasos, aviso_limite_saidas, atualizado_em, atualizado_por
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ON CONFLICT (id_escola) DO UPDATE SET
            tolerancia_minutos = EXCLUDED.tolerancia_minutos,
            limite_alerta_percentual = EXCLUDED.limite_alerta_percentual,
            minimo_ocorrencias_alerta = EXCLUDED.minimo_ocorrencias_alerta,
            janela_alerta_dias = EXCLUDED.janela_alerta_dias,
            intervalo_aviso_dias = EXCLUDED.intervalo_aviso_dias,
            aviso_automatico = EXCLUDED.aviso_automatico,
            aviso_cada_registro = EXCLUDED.aviso_cada_registro,
            atraso_pela_presenca = EXCLUDED.atraso_pela_presenca,
            aviso_entrada_atrasada = EXCLUDED.aviso_entrada_atrasada,
            aviso_saida_antecipada = EXCLUDED.aviso_saida_antecipada,
            aviso_limite_atrasos = EXCLUDED.aviso_limite_atrasos,
            aviso_limite_saidas = EXCLUDED.aviso_limite_saidas,
            atualizado_em = NOW(),
            atualizado_por = EXCLUDED.atualizado_por
    ");
    $config = comChavesAntigas($config);
    $stmt->execute([
        $idEscola,
        $config['tolerancia_minutos'],
        $config['limite_alerta_percentual'],
        $config['minimo_ocorrencias_alerta'],
        $config['janela_alerta_dias'],
        $config['intervalo_aviso_dias'],
        $texto($config['aviso_automatico']),
        $texto($config['aviso_cada_registro']),
        $texto($config['atraso_pela_presenca']),
        $texto($config['aviso_entrada_atrasada']),
        $texto($config['aviso_saida_antecipada']),
        $texto($config['aviso_limite_atrasos']),
        $texto($config['aviso_limite_saidas']),
        $idCoordenacao,
    ]);
}
