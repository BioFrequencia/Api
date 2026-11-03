<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/indicadores.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Entradas atrasadas e saídas antecipadas de UM aluno num período (a
 * ficha do aluno e o relatório individual), e o indicador dele na janela
 * de alerta da escola: quantos dias de aula, quantas ocorrências, a taxa
 * e o limite - o mesmo número que decide o aviso ao responsável.
 *
 * As faltas antigas (de antes de o sistema deixar de registrar faltas)
 * não aparecem mais: a frequência fica na Sala do Futuro.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
// Datas do período no calendário da escola (padrão: mês corrente).
$dataInicio = $dados["data_inicio"] ?? inicioDoMesLocal();
$dataFim = $dados["data_fim"] ?? hojeNaEscola();

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
// Antes qualquer texto ia direto para a consulta e virava erro 500.
if (!dataIsoValida($dataInicio) || !dataIsoValida($dataFim)) {
    enviarErro("Informe o período no formato AAAA-MM-DD.", 422);
}
if ($dataInicio > $dataFim) {
    enviarErro("A data inicial não pode ser depois da final.", 422);
}
// Limites do período em UTC: do início do primeiro dia ao início do dia
// seguinte ao último (no fuso da escola).
$limiteInicio = localParaUtc($dataInicio);
$limiteFim = localParaUtc((new DateTimeImmutable($dataFim))->modify('+1 day')->format('Y-m-d'));

try {
    // Só o histórico de aluno da própria escola. Junto, a situação da
    // biometria (consentimento, facial, digitais), para o painel
    // "Biometria" da página do aluno no site.
    $stmtAluno = $conexao->prepare("
        SELECT al.id_aluno, al.nome_aluno, s.id_sala, s.nome_sala, s.periodo_sala,
               to_char(s.horario_inicio, 'HH24:MI') AS horario_inicio, to_char(s.horario_fim, 'HH24:MI') AS horario_fim,
               c.nome_responsavel AS consentimento_responsavel, c.registrado_em AS consentimento_em,
               bf.atualizado_em AS facial_atualizada_em, bf.amostras AS facial_amostras,
               (SELECT count(*) FROM tb_biometria_digital bd WHERE bd.id_aluno = al.id_aluno) AS digitais
        FROM tb_aluno al
        JOIN tb_sala s ON s.id_sala = al.id_sala
        LEFT JOIN tb_consentimento_biometria c ON c.id_aluno = al.id_aluno
        LEFT JOIN tb_biometria_facial bf ON bf.id_aluno = al.id_aluno
        WHERE al.id_aluno = ? AND s.id_escola = ?
    ");
    $stmtAluno->bindValue(1, $idAluno);
    $stmtAluno->bindValue(2, escolaDaCoordenacao($conexao, $idCoordenacao));
    $stmtAluno->execute();
    $aluno = $stmtAluno->fetch(PDO::FETCH_ASSOC);

    if (!$aluno) {
        enviarErro("Aluno não encontrado.", 404);
    }

    $stmtAtrasos = $conexao->prepare("
        SELECT t.id_atraso AS id, t.dta_atraso AS data, t.motivo_atraso AS motivo, t.justificado_atraso AS justificado,
               t.observacao_atraso AS observacao, t.origem, c.nome_coordenacao AS registrado_por
        FROM tb_atraso t LEFT JOIN tb_coordenacao c ON c.id_coordenacao = t.id_coordenacao
        WHERE t.id_aluno = ? AND t.dta_atraso >= ? AND t.dta_atraso < ?
        ORDER BY t.dta_atraso DESC
    ");
    $stmtAtrasos->execute([$idAluno, $limiteInicio, $limiteFim]);
    $atrasos = $stmtAtrasos->fetchAll(PDO::FETCH_ASSOC);

    $stmtSaidas = $conexao->prepare("
        SELECT r.id_saida AS id, r.dta_saida AS data, r.motivo_saida AS motivo, r.justificado_saida AS justificado,
               r.observacao_saida AS observacao, r.retirado_por, c.nome_coordenacao AS registrado_por
        FROM tb_saida_antecipada r LEFT JOIN tb_coordenacao c ON c.id_coordenacao = r.id_coordenacao
        WHERE r.id_aluno = ? AND r.dta_saida >= ? AND r.dta_saida < ?
        ORDER BY r.dta_saida DESC
    ");
    $stmtSaidas->execute([$idAluno, $limiteInicio, $limiteFim]);
    $saidas = $stmtSaidas->fetchAll(PDO::FETCH_ASSOC);

    $idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
    $config = configuracaoDaEscola($conexao, $idEscola);
    $indicador = indicadoresDaJanela($conexao, $idEscola, $config, null, null, (int) $idAluno)['alunos'][(int) $idAluno] ?? null;

    enviarSucesso("Histórico carregado com sucesso.", [
        "aluno"     => $aluno,
        "periodo"   => ["inicio" => $dataInicio, "fim" => $dataFim],
        "atrasos"   => $atrasos,
        "saidas"    => $saidas,
        "indicador" => $indicador === null ? null : $indicador + [
            "situacao"   => situacaoDoAluno($indicador['prioridade_atrasos'], $indicador['prioridade_saidas'], $indicador['dias_letivos']),
            "limite"     => $config['limite_alerta_percentual'],
            "minimo"     => $config['minimo_ocorrencias_alerta'],
            "janela_dias" => $config['janela_alerta_dias'],
        ],
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar o histórico do aluno.", 500, $erro);
}
