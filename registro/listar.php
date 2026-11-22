<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/calendario.php';
require_once __DIR__ . '/../helpers/cancelamento.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Entradas atrasadas e saídas antecipadas de um dia (padrão: hoje), da
 * escola de quem está logado - a tela "Entradas e saídas" do site.
 *
 *   { data?: "AAAA-MM-DD", id_sala?, incluir_cancelados? }
 *
 * Cancelados (migração 012) só vêm com incluir_cancelados: true, com quem
 * cancelou, quando e o motivo; as contagens são sempre só dos ativos. Cada
 * registro diz se esta conta pode cancelá-lo (pode_cancelar).
 *
 * Junto vêm se o dia é de aula (e, se não for, por quê) e quantos alunos
 * já têm presença registrada pelo app.
 */
$idCoordenacao = autenticar();
$conta = contaDaCoordenacao($conexao, $idCoordenacao);
$idEscola = (int) $conta['id_escola'];

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$data = $dados['data'] ?? hojeNaEscola();
$idSala = $dados['id_sala'] ?? null;
$incluirCancelados = ($dados['incluir_cancelados'] ?? false) === true;

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
               t.observacao_atraso AS observacao, t.origem, NULL AS retirado_por, c.nome_coordenacao AS registrado_por,
               t.id_coordenacao, to_char(t.criado_em, 'YYYY-MM-DD HH24:MI:SS') AS criado_em,
               t.cancelado_em, t.motivo_cancelamento, cc.nome_coordenacao AS cancelado_por
        FROM tb_atraso t
        JOIN tb_aluno a ON a.id_aluno = t.id_aluno
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_coordenacao c ON c.id_coordenacao = t.id_coordenacao
        LEFT JOIN tb_coordenacao cc ON cc.id_coordenacao = t.cancelado_por
        WHERE s.id_escola = ? AND t.dta_atraso >= ? AND t.dta_atraso < ? AND (?::int IS NULL OR s.id_sala = ?::int)
          AND (?::boolean OR t.cancelado_em IS NULL)
        UNION ALL
        SELECT 'saida', r.id_saida, a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala,
               r.dta_saida, r.motivo_saida, r.justificado_saida, r.observacao_saida, NULL, r.retirado_por, c.nome_coordenacao,
               r.id_coordenacao, to_char(r.criado_em, 'YYYY-MM-DD HH24:MI:SS'),
               r.cancelado_em, r.motivo_cancelamento, cc.nome_coordenacao
        FROM tb_saida_antecipada r
        JOIN tb_aluno a ON a.id_aluno = r.id_aluno
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_coordenacao c ON c.id_coordenacao = r.id_coordenacao
        LEFT JOIN tb_coordenacao cc ON cc.id_coordenacao = r.cancelado_por
        WHERE s.id_escola = ? AND r.dta_saida >= ? AND r.dta_saida < ? AND (?::int IS NULL OR s.id_sala = ?::int)
          AND (?::boolean OR r.cancelado_em IS NULL)
        ORDER BY momento DESC
    ");
    $parametros = [$idEscola, $inicioUtc, $fimUtc, $idSala, $idSala, $incluirCancelados ? 'true' : 'false'];
    foreach ([...$parametros, ...$parametros] as $i => $valor) {
        $stmt->bindValue($i + 1, $valor);
    }
    $stmt->execute();
    $agora = agoraUtc();
    $registros = array_map(function ($r) use ($conta, $agora) {
        $r['id'] = (int) $r['id'];
        $r['id_aluno'] = (int) $r['id_aluno'];
        $r['id_sala'] = (int) $r['id_sala'];
        $r['cancelado'] = $r['cancelado_em'] !== null;
        $r['pode_cancelar'] = !$r['cancelado'] && podeCancelarRegistro($conta, $r, $agora);
        unset($r['id_coordenacao'], $r['criado_em']);
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    $ativos = array_filter($registros, fn($r) => !$r['cancelado']);

    $stmtPresencas = $conexao->prepare("
        SELECT COUNT(p.id_presenca) AS presentes, COUNT(a.id_aluno) AS alunos
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_presenca p ON p.id_aluno = a.id_aluno AND p.dia_letivo = ? AND p.cancelado_em IS NULL
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
        "atrasos"    => count(array_filter($ativos, fn($r) => $r['tipo'] === 'atraso')),
        "saidas"     => count(array_filter($ativos, fn($r) => $r['tipo'] === 'saida')),
        "pode_restaurar" => $conta['papel'] === 'gestor',
        "presentes"  => (int) $contagem['presentes'],
        "total_alunos" => (int) $contagem['alunos'],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os registros do dia.", 500, $erro);
}
