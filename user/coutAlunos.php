<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';

/**
 * ATENÇÃO — ENDPOINT PROVAVELMENTE QUEBRADO / SCHEMA NÃO CONFIRMADO
 * -----------------------------------------------------------------
 * Este arquivo assume uma tabela "tb_alunos" (plural) com colunas
 * (id_aluno, nome_aluno, email_aluno, turma_aluno, horario_entrada).
 * Em todo o resto da API (addaluno.php, filtroAluno.php, marcarFalta.php)
 * a tabela real usada é "tb_aluno" (singular), sem essas colunas de
 * email/horário de entrada. É bem provável que "tb_alunos" não exista
 * no banco e que esta query falhe com "relation does not exist".
 *
 * Não tenho acesso ao banco real neste momento para confirmar o schema,
 * então não posso corrigir isso com segurança sem adivinhar nomes de
 * coluna (o que o autor original também sinalizou como suposição no
 * comentário abaixo). Mantive o arquivo para não apagar histórico, mas:
 *
 *   -> filtroAluno.php já cobre listagem de alunos com faltas/atrasos/
 *      frequência/status usando o schema confirmado (tb_aluno).
 *   -> Se este relatório específico (atraso por horário de entrada) for
 *      necessário, me diga a estrutura real da tabela que eu implemento
 *      corretamente em cima de tb_aluno.
 *
 * Até lá, considere este endpoint como NÃO TESTADO / NÃO CONFIÁVEL.
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
