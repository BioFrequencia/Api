<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/alertas.php';
require_once __DIR__ . '/../helpers/escalonamento.php';
require_once __DIR__ . '/../helpers/avisos.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Alertas da escola: alunos com muitas entradas atrasadas ou saídas
 * antecipadas nos últimos dias (helpers/alertas.php).
 *
 *   { id_sala?, tipo?: atrasos | saidas, prioridade?: alta | media | baixa }
 *
 * Também é o "reforço" dos avisos automáticos: o aviso por limite sai
 * logo depois de cada registro, mas se naquela hora o aluno ainda não
 * tinha responsável (ou o provedor estava fora), ele sai aqui - com a
 * mesma trava de um aviso por intervalo (helpers/avisos.php).
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idSala     = $dados["id_sala"] ?? null;
$tipo       = $dados["tipo"] ?? null;
$prioridade = $dados["prioridade"] ?? null;

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $config = configuracaoDaEscola($conexao, $idEscola);
    $janela = indicadoresDaJanela($conexao, $idEscola, $config);
    $todos = buscarAlertas($conexao, $idEscola, $config, $janela);

    // Nível de cada aluno (há quanto tempo a situação dura), para a tela.
    $niveis = calcularNiveis($conexao, $todos);
    gravarNiveis($conexao, $niveis, $idEscola);

    $avisos = [];
    foreach (array_unique(array_column($todos, 'id_aluno')) as $idAluno) {
        $resumo = avisarSeAtingiuLimite($conexao, $idEscola, (int) $idAluno, $config, $janela['alunos'][$idAluno]);
        foreach ($resumo as $tipoAviso => $r) {
            if (($r['enviados'] ?? 0) + ($r['na_fila'] ?? 0) + ($r['falhas'] ?? 0) > 0) {
                $avisos[] = ["id_aluno" => (int) $idAluno, "nome_aluno" => $janela['alunos'][$idAluno]['nome_aluno'], "tipo" => $tipoAviso] + $r;
            }
        }
    }

    foreach ($todos as &$alerta) {
        $dadosNivel = $niveis[(int) $alerta['id_aluno']] ?? null;
        $alerta['nivel'] = $dadosNivel['nivel'] ?? 0;
        $alerta['nivel_rotulo'] = $dadosNivel['rotulo'] ?? NIVEL_ROTULOS[0];
    }
    unset($alerta);

    if (!empty($idSala)) {
        $todos = array_values(array_filter($todos, fn($a) => $a['id_sala'] === (int) $idSala));
    }
    if (!empty($tipo)) {
        $todos = array_values(array_filter($todos, fn($a) => $a['tipo'] === $tipo));
    }
    if (!empty($prioridade)) {
        $todos = array_values(array_filter($todos, fn($a) => $a['prioridade'] === $prioridade));
    }

    enviarSucesso("Alertas carregados com sucesso.", [
        "alertas"    => $todos,
        "total"      => count($todos),
        "avisos"     => $avisos,
        "limite"     => $config['limite_alerta_percentual'],
        "janela"     => ["inicio" => $janela['inicio'], "fim" => $janela['fim'], "dias" => $config['janela_alerta_dias'], "dias_letivos" => $janela['dias_letivos']],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os alertas.", 500, $erro);
}
