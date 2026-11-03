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
 * A gestão altera uma turma: nome, período e horário.
 *
 *   { id_sala, nome_sala?, periodo_sala?, horario_inicio?: "HH:MM" | null, horario_fim?: "HH:MM" | null }
 *
 * horário null volta ao padrão do período. O horário decide o que é
 * entrada atrasada e saída antecipada; mudar não altera os registros já
 * feitos.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idSala = $dados['id_sala'] ?? null;
if (!inteiroValido($idSala) || !salaPertenceAEscola($conexao, (int) $idSala, $conta['id_escola'])) {
    enviarErro("Turma não encontrada.", 404);
}

try {
    $stmt = $conexao->prepare("
        SELECT nome_sala, periodo_sala, to_char(horario_inicio, 'HH24:MI') AS horario_inicio, to_char(horario_fim, 'HH24:MI') AS horario_fim
        FROM tb_sala WHERE id_sala = ?
    ");
    $stmt->execute([(int) $idSala]);
    $atual = $stmt->fetch(PDO::FETCH_ASSOC);

    $nome = array_key_exists('nome_sala', $dados) ? $dados['nome_sala'] : $atual['nome_sala'];
    $periodo = array_key_exists('periodo_sala', $dados) ? $dados['periodo_sala'] : $atual['periodo_sala'];
    $inicio = array_key_exists('horario_inicio', $dados) ? $dados['horario_inicio'] : $atual['horario_inicio'];
    $fim = array_key_exists('horario_fim', $dados) ? $dados['horario_fim'] : $atual['horario_fim'];

    if (!campoPreenchido($nome) || mb_strlen(trim($nome)) > 50) {
        enviarErro("Informe o nome da turma (até 50 caracteres).", 422);
    }
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

    $repetida = $conexao->prepare("SELECT 1 FROM tb_sala WHERE id_escola = ? AND lower(nome_sala) = lower(?) AND id_sala <> ?");
    $repetida->execute([$conta['id_escola'], $nome, (int) $idSala]);
    if ($repetida->fetch()) {
        enviarErro("Já existe outra turma com esse nome.", 409);
    }

    $grava = $conexao->prepare("UPDATE tb_sala SET nome_sala = ?, periodo_sala = ?, horario_inicio = ?, horario_fim = ? WHERE id_sala = ?");
    $grava->execute([$nome, $periodo, $inicio, $fim, (int) $idSala]);

    enviarSucesso("Turma atualizada.", [
        "id_sala" => (int) $idSala, "nome_sala" => $nome, "periodo_sala" => $periodo,
        "horario_inicio" => $inicio, "horario_fim" => $fim, "horario" => $horario,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao atualizar a turma.", 500, $erro);
}
