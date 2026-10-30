<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/alertas.php';
require_once __DIR__ . '/../helpers/escalonamento.php';
require_once __DIR__ . '/../helpers/contatoResponsavel.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idSala     = $dados["id_sala"] ?? null;      // filtro de turma (opcional)
$tipo       = $dados["tipo"] ?? null;         // faltas | frequencia | atrasos (opcional)
$prioridade = $dados["prioridade"] ?? null;   // alta | media | baixa (opcional)

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $todos = buscarAlertas($conexao, $idEscola);

    // Nível de cada aluno e, no nível máximo, contato com o responsável.
    // Calculado aqui (e não num cron) porque esta é a tela que a
    // coordenação abre todo dia - sem agendador para manter no ar.
    $niveis = calcularNiveis($conexao, $todos);
    gravarNiveis($conexao, $niveis, $idEscola);

    $contatos = [];
    foreach ($niveis as $idAluno => $dadosNivel) {
        if ($dadosNivel['nivel'] >= 4) {
            $nome = $dadosNivel['alertas'][0]['nome_aluno'];
            $resumo = contatarResponsaveisNivelMaximo(
                $conexao, (int) $idAluno, $nome, $dadosNivel['motivo'], $dadosNivel['nivel']
            );
            if ($resumo['enviados'] > 0 || $resumo['na_fila'] > 0 || $resumo['falhas'] > 0) {
                $contatos[] = ["id_aluno" => (int) $idAluno, "nome_aluno" => $nome] + $resumo;
            }
        }
    }

    // Enriquece cada alerta com o nível do aluno, para a tela mostrar.
    foreach ($todos as &$alerta) {
        $dadosNivel = $niveis[(int) $alerta['id_aluno']] ?? null;
        $alerta['nivel'] = $dadosNivel['nivel'] ?? 0;
        $alerta['nivel_rotulo'] = $dadosNivel['rotulo'] ?? NIVEL_ROTULOS[0];
    }
    unset($alerta);

    if (!empty($idSala)) {
        // Pelo id da turma (antes era pelo nome, que se repete entre
        // escolas e entre períodos - "1A" de manhã e "1A" à tarde).
        $todos = array_values(array_filter($todos, fn($a) => $a['id_sala'] === (int) $idSala));
    }
    if (!empty($tipo)) {
        $todos = array_values(array_filter($todos, fn($a) => $a['tipo'] === $tipo));
    }
    if (!empty($prioridade)) {
        $todos = array_values(array_filter($todos, fn($a) => $a['prioridade'] === $prioridade));
    }

    enviarSucesso("Alertas carregados com sucesso.", [
        "alertas"  => $todos,
        "total"    => count($todos),
        "contatos" => $contatos,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os alertas.", 500, $erro);
}
