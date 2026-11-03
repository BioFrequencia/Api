<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/calendario.php';
require_once __DIR__ . '/../helpers/configuracaoEscola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Tela "Escola" do site: os dados da escola (do catálogo da SEDUC), a
 * configuração (tolerância e avisos), o horário de cada turma e o
 * calendário do ano - feriados automáticos e os dias próprios da escola.
 *
 *   { ano?: 2026 }
 *
 * Toda conta da escola vê; só a gestão altera ("pode_editar").
 */
$idCoordenacao = autenticar();
$conta = contaDaCoordenacao($conexao, $idCoordenacao);
$idEscola = $conta['id_escola'];

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$ano = isset($dados['ano']) && inteiroValido($dados['ano']) ? (int) $dados['ano'] : (int) substr(hojeNaEscola(), 0, 4);
if ($ano < 2020 || $ano > 2100) {
    enviarErro("Ano inválido.", 422);
}

try {
    $stmtEscola = $conexao->prepare("
        SELECT id_escola, nome_escola, nome_oficial, codigo_cie, codigo_inep, rede, diretoria_ensino, municipio,
               distrito, endereco, numero, complemento, bairro, cep, latitude, longitude
        FROM tb_escola WHERE id_escola = ?
    ");
    $stmtEscola->execute([$idEscola]);
    $escola = $stmtEscola->fetch(PDO::FETCH_ASSOC);

    $stmtTurmas = $conexao->prepare("
        SELECT s.id_sala, s.nome_sala, s.periodo_sala,
               to_char(s.horario_inicio, 'HH24:MI') AS horario_inicio, to_char(s.horario_fim, 'HH24:MI') AS horario_fim,
               (SELECT COUNT(*) FROM tb_aluno a WHERE a.id_sala = s.id_sala) AS total_alunos
        FROM tb_sala s WHERE s.id_escola = ? ORDER BY s.nome_sala
    ");
    $stmtTurmas->execute([$idEscola]);
    $turmas = array_map(function ($turma) {
        $turma['id_sala'] = (int) $turma['id_sala'];
        $turma['total_alunos'] = (int) $turma['total_alunos'];
        $turma['horario'] = horarioDaTurma($turma['periodo_sala'], $turma['horario_inicio'], $turma['horario_fim']);
        return $turma;
    }, $stmtTurmas->fetchAll(PDO::FETCH_ASSOC));

    $calendario = calendarioDaEscola($conexao, $idEscola, "$ano-01-01", "$ano-12-31");
    $feriados = [];
    foreach (feriadosDoAno($ano) as $data => $nome) {
        $feriados[] = ["data" => $data, "nome" => $nome, "dia_da_semana" => (int) (new DateTimeImmutable($data))->format('N')];
    }

    enviarSucesso("Configuração da escola carregada.", [
        "escola"          => $escola,
        "configuracao"    => configuracaoDaEscola($conexao, $idEscola),
        "padroes"         => CONFIGURACAO_PADRAO,
        "faixas"          => FAIXAS_CONFIGURACAO,
        "horario_padrao"  => array_map(fn($h) => ['inicio' => $h[0], 'fim' => $h[1]], HORARIO_PADRAO_PERIODO),
        "turmas"          => $turmas,
        "ano"             => $ano,
        "calendario"      => array_map(fn($c) => ['id_calendario' => (int) $c['id_calendario']] + $c, $calendario),
        "feriados"        => $feriados,
        "hoje"            => ["data" => hojeNaEscola()] + situacaoDoDia(hojeNaEscola(), calendarioDaEscola($conexao, $idEscola, hojeNaEscola(), hojeNaEscola())),
        "papel"           => $conta['papel'],
        "pode_editar"     => $conta['papel'] === 'gestor',
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar a configuração da escola.", 500, $erro);
}
