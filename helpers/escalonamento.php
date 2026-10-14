<?php
require_once __DIR__ . '/alertas.php';

/**
 * Escalonamento dos alertas.
 *
 * O cálculo de alerta (helpers/alertas.php) diz QUE o aluno precisa de
 * atenção e com que prioridade, mas era sempre recalculado do zero: um
 * aluno que estava em alerta havia três semanas aparecia igual a um que
 * entrou hoje, e nada registrava que a escola já tinha tentado contato.
 * Faltava a parte que a coordenação pediu: a situação ir subindo de
 * nível e, no máximo, avisar o responsável.
 *
 * Os níveis são deliberadamente simples e explicáveis para a
 * coordenação (nada de pontuação oculta):
 *
 *   0  sem alerta
 *   1  Observação   - alerta de prioridade baixa
 *   2  Atenção      - prioridade média, ou baixa persistindo há 7+ dias
 *   3  Prioritário  - prioridade alta, ou média persistindo há 7+ dias
 *   4  Crítico      - alta persistindo há 7+ dias, ou mais de um tipo de
 *                     alerta alto ao mesmo tempo
 *
 * Só o nível 4 dispara contato automático com o responsável. Abaixo
 * disso o sistema sinaliza e a coordenação decide - a mesma regra que
 * vale para o resto do sistema.
 */

const NIVEL_ROTULOS = [
    0 => 'Sem alerta',
    1 => 'Observação',
    2 => 'Atenção',
    3 => 'Prioritário',
    4 => 'Crítico',
];

/** Dias que um alerta precisa persistir para subir de nível. */
const DIAS_PARA_ESCALAR = 7;

function nivelBasePorPrioridade(string $prioridade): int {
    return match ($prioridade) {
        'alta'  => 3,
        'media' => 2,
        'baixa' => 1,
        default => 0,
    };
}

/**
 * Calcula o nível de cada aluno a partir dos alertas ativos e do que já
 * estava gravado em tb_alerta_nivel (para saber há quanto tempo dura).
 * Devolve [id_aluno => ['nivel'=>int,'motivo'=>string,'alertas'=>array]].
 */
function calcularNiveis(PDO $conexao, array $alertas): array {
    $porAluno = [];
    foreach ($alertas as $alerta) {
        $porAluno[(int) $alerta['id_aluno']][] = $alerta;
    }
    if (empty($porAluno)) {
        return [];
    }

    // Quando cada aluno entrou no nível atual - é o que permite dizer
    // "persiste há mais de uma semana" sem guardar histórico diário.
    $desde = [];
    $stmt = $conexao->query("SELECT id_aluno, nivel, atualizado_em FROM tb_alerta_nivel");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $desde[(int) $linha['id_aluno']] = $linha;
    }

    $resultado = [];
    foreach ($porAluno as $idAluno => $alertasDoAluno) {
        $maiorBase = 0;
        $altos = 0;
        foreach ($alertasDoAluno as $alerta) {
            $base = nivelBasePorPrioridade($alerta['prioridade']);
            $maiorBase = max($maiorBase, $base);
            if ($alerta['prioridade'] === 'alta') {
                $altos++;
            }
        }

        $nivel = $maiorBase;
        $motivos = [];

        // Mais de um alerta de prioridade alta ao mesmo tempo (ex.:
        // frequência baixa E faltas consecutivas) já é o caso crítico.
        if ($altos >= 2) {
            $nivel = 4;
            $motivos[] = "$altos alertas de prioridade alta ao mesmo tempo";
        }

        $anterior = $desde[$idAluno] ?? null;
        if ($anterior !== null && (int) $anterior['nivel'] >= $maiorBase && $maiorBase > 0) {
            $diasNoNivel = (int) ((time() - strtotime($anterior['atualizado_em'])) / 86400);
            if ($diasNoNivel >= DIAS_PARA_ESCALAR) {
                $nivel = min(4, max($nivel, $maiorBase + 1));
                $motivos[] = "situação mantida há $diasNoNivel dias";
            }
        }

        $tipos = array_unique(array_column($alertasDoAluno, 'tipo'));
        $motivos[] = count($tipos) === 1
            ? "alerta de " . $tipos[0]
            : count($tipos) . " tipos de alerta ativos";

        $resultado[$idAluno] = [
            'nivel'   => $nivel,
            'rotulo'  => NIVEL_ROTULOS[$nivel],
            'motivo'  => ucfirst(implode('; ', $motivos)) . '.',
            'alertas' => $alertasDoAluno,
        ];
    }
    return $resultado;
}

/** Grava o nível calculado, preservando a data de entrada no nível. */
function gravarNiveis(PDO $conexao, array $niveis): void {
    if (empty($niveis)) {
        return;
    }
    // atualizado_em só muda quando o nível muda: é o relógio que mede
    // "há quanto tempo está assim". Reescrevê-lo a cada consulta
    // zeraria a contagem e o aluno nunca escalaria.
    $stmt = $conexao->prepare("
        INSERT INTO tb_alerta_nivel (id_aluno, nivel, motivo_nivel, atualizado_em)
        VALUES (?, ?, ?, NOW())
        ON CONFLICT (id_aluno) DO UPDATE
        SET nivel = EXCLUDED.nivel,
            motivo_nivel = EXCLUDED.motivo_nivel,
            atualizado_em = CASE
                WHEN tb_alerta_nivel.nivel IS DISTINCT FROM EXCLUDED.nivel
                THEN NOW() ELSE tb_alerta_nivel.atualizado_em END
    ");
    foreach ($niveis as $idAluno => $dados) {
        $stmt->execute([$idAluno, $dados['nivel'], $dados['motivo']]);
    }
}
