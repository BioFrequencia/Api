<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/alertas.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idSala     = $dados["id_sala"] ?? null;      // filtro de turma (opcional)
$tipo       = $dados["tipo"] ?? null;         // faltas | frequencia | atrasos (opcional)
$prioridade = $dados["prioridade"] ?? null;   // alta | media | baixa (opcional)

try {
    $todos = buscarAlertas($conexao);

    if (!empty($idSala)) {
        // id_sala não vem no retorno de buscarAlertas() (só o nome da
        // turma), então o filtro por turma é feito pelo nome - suficiente
        // aqui pois nome_sala já é único no schema atual.
        $stmt = $conexao->prepare("SELECT nome_sala FROM tb_sala WHERE id_sala = ?");
        $stmt->bindParam(1, $idSala);
        $stmt->execute();
        $nomeSala = $stmt->fetchColumn();
        $todos = array_values(array_filter($todos, fn($a) => $a['turma'] === $nomeSala));
    }
    if (!empty($tipo)) {
        $todos = array_values(array_filter($todos, fn($a) => $a['tipo'] === $tipo));
    }
    if (!empty($prioridade)) {
        $todos = array_values(array_filter($todos, fn($a) => $a['prioridade'] === $prioridade));
    }

    enviarSucesso("Alertas carregados com sucesso.", [
        "alertas" => $todos,
        "total"   => count($todos),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os alertas.", 500, $erro);
}
