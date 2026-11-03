<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/calendario.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Turmas da escola, com o horário de cada uma: o gravado na turma
 * (horario_inicio/horario_fim, null quando não tem) e o que vale de fato
 * ("horario"), que usa o padrão do período quando a turma não tem um.
 */

$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    // Restrito à escola de quem está logado: antes, qualquer conta via
    // as turmas de todas as escolas da mesma instalação.
    $select = $conexao->prepare("
        SELECT s.id_sala, s.nome_sala, s.periodo_sala,
               to_char(s.horario_inicio, 'HH24:MI') AS horario_inicio, to_char(s.horario_fim, 'HH24:MI') AS horario_fim,
               (SELECT COUNT(*) FROM tb_aluno a WHERE a.id_sala = s.id_sala) AS total_alunos
        FROM tb_sala s WHERE s.id_escola = ? ORDER BY s.nome_sala ASC
    ");
    $select->bindValue(1, $idEscola);
    $select->execute();
    $salas = array_map(function ($sala) {
        $sala['total_alunos'] = (int) $sala['total_alunos'];
        $sala['horario'] = horarioDaTurma($sala['periodo_sala'], $sala['horario_inicio'], $sala['horario_fim']);
        return $sala;
    }, $select->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Salas carregadas com sucesso.", $salas);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as salas.", 500, $erro);
}
