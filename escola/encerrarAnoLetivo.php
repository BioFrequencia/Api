<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/anoLetivo.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão encerra o ano letivo (ver helpers/anoLetivo.php).
 *
 *   {
 *     turmas:   [{ id_sala, destino: "manter" | "concluiu" | "transferido" | id_sala }],
 *     excecoes: [{ id_aluno, destino: ... }]   (opcional: quem repete, quem foi transferido)
 *   }
 *   -> { movidos, sairam, biometrias_apagadas }
 *
 * Quem sai não é apagado: some das listas e do app, com o histórico
 * guardado; a biometria dele é apagada. Dá para trazer alguém de volta
 * na ficha do aluno (user/situacaoAluno.php).
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$turmasPedidas = is_array($dados['turmas'] ?? null) ? $dados['turmas'] : null;
$excecoes = is_array($dados['excecoes'] ?? []) ? ($dados['excecoes'] ?? []) : null;
if ($turmasPedidas === null || $excecoes === null) {
    enviarErro("Informe o que acontece com cada turma.", 400);
}

try {
    $turmas = turmasComAlunosAtivos($conexao, $conta['id_escola']);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as turmas.", 500, $erro);
}
$alunosPorTurma = array_map(fn($turma) => array_column($turma['alunos'], 'id_aluno'), $turmas);
[$plano, $erro] = planoDoEncerramento($alunosPorTurma, $turmasPedidas, $excecoes);
if ($erro !== null) {
    enviarErro($erro, 422);
}

try {
    $resultado = aplicarEncerramento($conexao, $plano);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao encerrar o ano letivo. Nada foi alterado.", 500, $erro);
}
$partes = [];
if ($resultado['movidos'] > 0) {
    $partes[] = $resultado['movidos'] . ($resultado['movidos'] === 1 ? ' aluno mudou de turma' : ' alunos mudaram de turma');
}
if ($resultado['sairam'] > 0) {
    $partes[] = $resultado['sairam'] . ($resultado['sairam'] === 1 ? ' saiu da escola' : ' saíram da escola');
}
enviarSucesso("Ano letivo encerrado: " . implode(' e ', $partes) . ".", $resultado);
