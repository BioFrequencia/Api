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
 * Entradas atrasadas e saídas antecipadas de um dia (padrão: hoje), da
 * escola de quem está logado - a tela "Entradas e saídas" do site.
 *
 *   { data?: "AAAA-MM-DD", id_sala? }
 *
 * Junto vêm se o dia é de aula (e, se não for, por quê) e quantos alunos
 * já têm presença registrada pelo app.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$data = $dados['data'] ?? hojeNaEscola();
$idSala = $dados['id_sala'] ?? null;

if (!dataIsoValida($data)) {
    enviarErro("Informe a data no formato AAAA-MM-DD.", 422);
}
if ($idSala !== null && (!inteiroValido($idSala) || !salaPertenceAEscola($conexao, (int) $idSala, $idEscola))) {
    enviarErro("Turma não encontrada.", 404);
}
$idSala = $idSala === null ? null : (int) $idSala;
$inicioUtc = localParaUtc($data);
$fimUtc = localParaUtc((new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d'));

try {
    $stmt = $conexao->prepare("
        SELECT 'atraso' AS tipo, t.id_atraso AS id, a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala,
               t.dta_atraso AS momento, t.motivo_atraso AS motivo, t.justificado_atraso AS justificado,
               t.observacao_atraso AS observacao, t.origem, NULL AS retirado_por, c.nome_coordenacao AS registrado_por
        FROM tb_atraso t
        JOIN tb_aluno a ON a.id_aluno = t.id_aluno
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_coordenacao c ON c.id_coordenacao = t.id_coordenacao
        WHERE s.id_escola = ? AND t.dta_atraso >= ? AND t.dta_atraso < ? AND (?::int IS NULL OR s.id_sala = ?::int)
        UNION ALL
        SELECT 'saida', r.id_saida, a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala,
               r.dta_saida, r.motivo_saida, r.justificado_saida, r.observacao_saida, NULL, r.retirado_por, c.nome_coordenacao
        FROM tb_saida_antecipada r
        JOIN tb_aluno a ON a.id_aluno = r.id_aluno
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_coordenacao c ON c.id_coordenacao = r.id_coordenacao
        WHERE s.id_escola = ? AND r.dta_saida >= ? AND r.dta_saida < ? AND (?::int IS NULL OR s.id_sala = ?::int)
        ORDER BY momento DESC
    ");
    $parametros = [$idEscola, $inicioUtc, $fimUtc, $idSala, $idSala];
    foreach ([...$parametros, ...$parametros] as $i => $valor) {
        $stmt->bindValue($i + 1, $valor);
    }
    $stmt->execute();
    $registros = array_map(function ($r) {
        $r['id'] = (int) $r['id'];
        $r['id_aluno'] = (int) $r['id_aluno'];
        $r['id_sala'] = (int) $r['id_sala'];
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmtPresencas = $conexao->prepare("
        SELECT COUNT(p.id_presenca) AS presentes, COUNT(a.id_aluno) AS alunos
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_presenca p ON p.id_aluno = a.id_aluno AND p.dia_letivo = ?
        WHERE s.id_escola = ? AND (?::int IS NULL OR s.id_sala = ?::int)
    ");
    $stmtPresencas->bindValue(1, $data);
    $stmtPresencas->bindValue(2, $idEscola);
    $stmtPresencas->bindValue(3, $idSala);
    $stmtPresencas->bindValue(4, $idSala);
    $stmtPresencas->execute();
    $contagem = $stmtPresencas->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Registros do dia carregados.", [
        "data"       => $data,
        "dia"        => situacaoDoDia($data, calendarioDaEscola($conexao, $idEscola, $data, $data)),
        "registros"  => $registros,
        "atrasos"    => count(array_filter($registros, fn($r) => $r['tipo'] === 'atraso')),
        "saidas"     => count(array_filter($registros, fn($r) => $r['tipo'] === 'saida')),
        "presentes"  => (int) $contagem['presentes'],
        "total_alunos" => (int) $contagem['alunos'],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os registros do dia.", 500, $erro);
}
