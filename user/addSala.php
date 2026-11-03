<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/calendario.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Cadastro de turma pela coordenação, sempre na escola de quem está
 * logado. Antes não existia: as turmas só entravam direto no banco, e uma
 * coordenação nova (que agora ganha uma escola própria e vazia) não teria
 * como começar.
 *
 *   { nome_sala, periodo_sala: Matutino | Vespertino | Noturno | Integral | Indefinido,
 *     horario_inicio?: "HH:MM", horario_fim?: "HH:MM" }
 *
 * Sem horário, vale o padrão do período (helpers/calendario.php) - é o
 * horário que decide se uma entrada é atrasada ou uma saída é antecipada.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$nome = $dados["nome_sala"] ?? null;
$periodo = $dados["periodo_sala"] ?? 'Indefinido';
$inicio = $dados["horario_inicio"] ?? null;
$fim = $dados["horario_fim"] ?? null;

if (!campoPreenchido($nome) || mb_strlen(trim($nome)) > 50) {
    enviarErro("Informe o nome da turma (até 50 caracteres).", 422);
}
// Os mesmos valores do CHECK chk_periodo_sala do banco (migração 008).
if (!in_array($periodo, PERIODOS_TURMA, true)) {
    enviarErro("Período inválido: use Matutino, Vespertino, Noturno, Integral ou Indefinido.", 422);
}
if (($inicio !== null && !horaValida($inicio)) || ($fim !== null && !horaValida($fim))) {
    enviarErro("Horário inválido: use HH:MM (ex.: 07:00).", 422);
}
$horario = horarioDaTurma($periodo, $inicio, $fim);
if (minutosDoDia($horario['fim']) <= minutosDoDia($horario['inicio'])) {
    enviarErro("O fim da aula precisa ser depois do início.", 422);
}

$nome = preg_replace('/\s+/u', ' ', trim($nome));

try {
    $existe = $conexao->prepare("SELECT 1 FROM tb_sala WHERE id_escola = ? AND lower(nome_sala) = lower(?)");
    $existe->bindValue(1, $idEscola);
    $existe->bindValue(2, $nome);
    $existe->execute();
    if ($existe->fetch()) {
        enviarErro("Já existe uma turma com esse nome.", 409);
    }

    $insere = $conexao->prepare("
        INSERT INTO tb_sala (nome_sala, periodo_sala, id_escola, horario_inicio, horario_fim) VALUES (?, ?, ?, ?, ?)
        RETURNING id_sala, nome_sala, periodo_sala
    ");
    $insere->execute([$nome, $periodo, $idEscola, $inicio, $fim]);

    enviarSucesso("Turma cadastrada.", $insere->fetch(PDO::FETCH_ASSOC) + ["horario" => $horario], 201);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao cadastrar a turma.", 500, $erro);
}
