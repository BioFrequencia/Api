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
 * Nível de UM aluno (função pura, testada em tests/helpers_test.php).
 *
 * $anterior é a linha de tb_alerta_nivel (nivel, atualizado_em em UTC)
 * ou null. A escalada é de um degrau acima da prioridade (no máximo 4) e
 * vale enquanto a situação persistir. Duas correções em relação à versão
 * anterior, ambas com efeito direto no contato automático com a família:
 *  - depois de escalar, o nível caía de volta no dia seguinte (o relógio
 *    "há quantos dias" recomeça quando o nível muda), e subia de novo
 *    uma semana depois - oscilava 3 -> 4 -> 3 -> 4;
 *  - um aluno que saía do alerta ficava com o nível antigo gravado e, ao
 *    voltar a ter alerta semanas depois, escalava na hora (o "desde"
 *    antigo contava como persistência). Isso é tratado em gravarNiveis(),
 *    que zera o nível de quem não tem mais alerta.
 */
function calcularNivelAluno(array $alertasDoAluno, ?array $anterior, ?int $agora = null): array {
    $agora = $agora ?? time();
    $maiorBase = 0;
    $altos = 0;
    foreach ($alertasDoAluno as $alerta) {
        $maiorBase = max($maiorBase, nivelBasePorPrioridade($alerta['prioridade']));
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

    if ($anterior !== null && $maiorBase > 0) {
        $nivelAnterior = (int) $anterior['nivel'];
        $nivelEscalado = min(4, $maiorBase + 1);
        $diasNoNivel = (int) (($agora - strtotime($anterior['atualizado_em'])) / 86400);

        if ($nivelAnterior === $nivelEscalado && $nivelEscalado > $nivel) {
            // Já estava neste nível e a situação continua: mantém.
            $nivel = $nivelEscalado;
            $motivos[] = "nível mantido enquanto a situação persistir";
        } elseif ($nivelAnterior >= $maiorBase && $diasNoNivel >= DIAS_PARA_ESCALAR && $nivelEscalado > $nivel) {
            $nivel = $nivelEscalado;
            $motivos[] = "situação mantida há $diasNoNivel dias";
        }
    }

    $tipos = array_values(array_unique(array_column($alertasDoAluno, 'tipo')));
    if (count($tipos) === 1) {
        $motivos[] = "alerta de " . $tipos[0];
    } elseif (count($tipos) > 1) {
        $motivos[] = count($tipos) . " tipos de alerta ativos";
    }

    return [
        'nivel'  => $nivel,
        'rotulo' => NIVEL_ROTULOS[$nivel],
        'motivo' => ucfirst(implode('; ', $motivos)) . '.',
    ];
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
    // Só dos alunos em alerta (antes lia a tabela inteira, de todas as
    // escolas, a cada abertura da tela).
    $desde = [];
    $stmt = $conexao->prepare("SELECT id_aluno, nivel, atualizado_em FROM tb_alerta_nivel WHERE id_aluno = ANY(?::int[])");
    $stmt->execute(['{' . implode(',', array_map('intval', array_keys($porAluno))) . '}']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $desde[(int) $linha['id_aluno']] = $linha;
    }

    $resultado = [];
    foreach ($porAluno as $idAluno => $alertasDoAluno) {
        $resultado[$idAluno] = calcularNivelAluno($alertasDoAluno, $desde[$idAluno] ?? null)
            + ['alertas' => $alertasDoAluno];
    }
    return $resultado;
}

/**
 * Grava o nível calculado, preservando a data de entrada no nível, e
 * zera o nível dos alunos da escola que não têm mais alerta - sem isso
 * o nível antigo ficava gravado e contava como "persistência" quando o
 * aluno voltasse a ter alerta.
 */
function gravarNiveis(PDO $conexao, array $niveis, int $idEscola): void {
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

    $zerar = $conexao->prepare("
        UPDATE tb_alerta_nivel n
        SET nivel = 0, motivo_nivel = NULL, atualizado_em = NOW()
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE n.id_aluno = a.id_aluno
          AND s.id_escola = ?
          AND n.nivel <> 0
          AND NOT (n.id_aluno = ANY(?::int[]))
    ");
    $zerar->execute([$idEscola, '{' . implode(',', array_map('intval', array_keys($niveis))) . '}']);
}
