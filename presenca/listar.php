<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Presenças de um dia (padrão: hoje, no fuso da escola), da escola de quem
 * está logado - opcionalmente de uma turma. Devolve quem está presente
 * (horário e método) e quem ainda não foi registrado, para o site e para
 * a tela "Registros" do app.
 *
 *   { data?: "AAAA-MM-DD", id_sala?: number }
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$data = $dados["data"] ?? hojeNaEscola();
$idSala = $dados["id_sala"] ?? null;

if (!dataIsoValida($data)) {
    enviarErro("Informe a data no formato AAAA-MM-DD.", 422);
}
if ($idSala !== null && !inteiroValido($idSala)) {
    enviarErro("Turma inválida.", 422);
}
if ($idSala !== null && !salaPertenceAEscola($conexao, $idSala, $idEscola)) {
    enviarErro("Turma não encontrada.", 404);
}

try {
    $stmt = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala,
               p.id_presenca, p.registrada_em, p.metodo
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_presenca p ON p.id_aluno = a.id_aluno AND p.dia_letivo = ?
        WHERE s.id_escola = ? AND (?::int IS NULL OR s.id_sala = ?::int)
        ORDER BY p.registrada_em DESC NULLS LAST, a.nome_aluno
    ");
    $stmt->bindValue(1, $data);
    $stmt->bindValue(2, $idEscola);
    $stmt->bindValue(3, $idSala === null ? null : (int) $idSala);
    $stmt->bindValue(4, $idSala === null ? null : (int) $idSala);
    $stmt->execute();
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $presentes = array_values(array_filter($linhas, fn($l) => $l['id_presenca'] !== null));
    $ausentes = array_values(array_map(
        fn($l) => ["id_aluno" => $l['id_aluno'], "nome_aluno" => $l['nome_aluno'], "id_sala" => $l['id_sala'], "nome_sala" => $l['nome_sala']],
        array_filter($linhas, fn($l) => $l['id_presenca'] === null)
    ));

    enviarSucesso("Presenças carregadas.", [
        "data" => $data,
        "total_alunos" => count($linhas),
        "presentes" => $presentes,
        "ausentes" => $ausentes,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as presenças.", 500, $erro);
}
