<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';

/**
 * ATENÇÃO — ENDPOINT QUEBRADO / SCHEMA CONFIRMADO COMO INCOMPATÍVEL
 * -----------------------------------------------------------------
 * Este arquivo assume uma tabela "tb_alunos" (plural) com colunas
 * (id_aluno, nome_aluno, email_aluno, turma_aluno, horario_entrada).
 * CONFIRMADO via schema do Supabase (Database > Schema Visualizer): a
 * tabela real é "tb_aluno" (singular, sem "s"), com apenas as colunas
 * id_aluno, nome_aluno, id_sala, dta_nasc_aluno, genero_aluno - nenhuma
 * das colunas email_aluno/turma_aluno/horario_entrada existe. Além disso,
 * "atraso" no schema real é um evento discreto (tabela tb_atraso, com
 * data/motivo/justificativa - ver marcarAtraso.php), não um horário de
 * entrada comparado a um limite.
 *
 * Esta query vai falhar com "relation tb_alunos does not exist" se for
 * chamada. Mantido no repositório para não apagar histórico, mas:
 *
 *   -> filtroAluno.php já cobre listagem de alunos com faltas/atrasos/
 *      frequência/status usando o schema real (tb_aluno + tb_falta +
 *      tb_atraso).
 *   -> Se um relatório "quem chegou atrasado hoje" for necessário,
 *      recomendo reescrever isso em cima de tb_atraso (dta_atraso = hoje)
 *      em vez de tentar consertar as suposições deste arquivo.
 *
 * Considere este endpoint DESCONTINUADO.
 */

/**
 * Relatório de Alunos + Situação de Atraso
 * -----------------------------------------
 * Segue o mesmo padrão do login da coordenação: PDO, prepared statements,
 * retorno em JSON com "sucesso", "mensagem" e "dados".
 *
 * SUPOSIÇÕES sobre a tabela (ajuste os nomes das colunas se forem diferentes):
 *
 * tb_alunos
 *   id_aluno         (int)
 *   nome_aluno       (varchar)
 *   email_aluno      (varchar)
 *   turma_aluno      (varchar)
 *   horario_entrada  (time)      -> horário que o aluno registrou entrada hoje
 *
 * Horário limite de tolerância definido abaixo em HORARIO_LIMITE.
 * Se preferir, isso pode virar uma coluna configurável no banco depois.
 */

define('HORARIO_LIMITE', '08:00:00');

try {
    // Filtro opcional por turma ou nome (via GET, ex: ?busca=Turma+A)
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

    $sql = "SELECT id_aluno, nome_aluno, email_aluno, turma_aluno, horario_entrada
            FROM tb_alunos";

    $params = [];

    if ($busca !== '') {
        $sql .= " WHERE nome_aluno ILIKE ? OR turma_aluno ILIKE ?";
        $params[] = "%$busca%";
        $params[] = "%$busca%";
    }

    $sql .= " ORDER BY nome_aluno ASC";

    $select = $conexao->prepare($sql);
    $select->execute($params);
    $alunos = $select->fetchAll(PDO::FETCH_ASSOC);

    // Monta o relatório, calculando quem chegou atrasado
    $relatorio = array_map(function ($aluno) {
        $atrasado = false;

        if (!empty($aluno['horario_entrada'])) {
            $atrasado = $aluno['horario_entrada'] > HORARIO_LIMITE;
        }

        return [
            "id"              => $aluno['id_aluno'],
            "nome"            => $aluno['nome_aluno'],
            "email"           => $aluno['email_aluno'],
            "turma"           => $aluno['turma_aluno'],
            "horario_entrada" => $aluno['horario_entrada'],
            "atrasado"        => $atrasado,
            "situacao"        => $atrasado ? "Atrasado" : "Pontual",
        ];
    }, $alunos);

    $total_atrasados = count(array_filter($relatorio, fn($a) => $a['atrasado']));

    enviarSucesso("Relatório gerado com sucesso.", [
        "alunos"          => $relatorio,
        "total_alunos"    => count($relatorio),
        "total_atrasados" => $total_atrasados,
    ]);

} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao gerar o relatório de alunos.", 500, $erro);
}
