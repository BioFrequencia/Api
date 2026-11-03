<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/catalogoEscolas.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Painel da equipe do sistema (ADMIN_EMAILS): números gerais de todas as
 * escolas e a situação do catálogo. Só contagens - nenhum dado de aluno.
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);

try {
    $numero = function (string $sql, array $parametros = []) use ($conexao): int {
        $stmt = $conexao->prepare($sql);
        $stmt->execute($parametros);
        return (int) $stmt->fetchColumn();
    };
    $inicioMes = inicioDoMesUtc();

    $catalogo = $conexao->query("SELECT versao, total, importado_em FROM tb_catalogo_escolas ORDER BY importado_em DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;

    enviarSucesso("Resumo do sistema.", [
        "escolas_catalogo"      => $numero("SELECT COUNT(*) FROM tb_escola WHERE codigo_cie IS NOT NULL"),
        "escolas_em_uso"        => $numero("SELECT COUNT(DISTINCT id_escola) FROM tb_coordenacao WHERE situacao = 'ativo'"),
        "escolas_fora_catalogo" => $numero("SELECT COUNT(*) FROM tb_escola WHERE codigo_cie IS NULL"),
        "contas_ativas"         => $numero("SELECT COUNT(*) FROM tb_coordenacao WHERE situacao = 'ativo'"),
        // Pendentes que já confirmaram o e-mail: as que a equipe já pode aprovar.
        "contas_pendentes"      => $numero("SELECT COUNT(*) FROM tb_coordenacao WHERE situacao = 'pendente' AND email_verificado_em IS NOT NULL"),
        "contas_sem_email"      => $numero("SELECT COUNT(*) FROM tb_coordenacao WHERE situacao = 'pendente' AND email_verificado_em IS NULL"),
        "alunos"                => $numero("SELECT COUNT(*) FROM tb_aluno"),
        "turmas"                => $numero("SELECT COUNT(*) FROM tb_sala"),
        "atrasos_mes"           => $numero("SELECT COUNT(*) FROM tb_atraso WHERE dta_atraso >= ?", [$inicioMes]),
        "saidas_mes"            => $numero("SELECT COUNT(*) FROM tb_saida_antecipada WHERE dta_saida >= ?", [$inicioMes]),
        "presencas_mes"         => $numero("SELECT COUNT(*) FROM tb_presenca WHERE registrada_em >= ?", [$inicioMes]),
        "chamados_abertos"      => $numero("SELECT COUNT(*) FROM tb_chamado_suporte WHERE status IN ('aberto', 'em_andamento')"),
        "avisos_pendentes"      => $numero("SELECT COUNT(*) FROM tb_notificacao WHERE status_notificacao = 'Pendente'"),
        "catalogo"              => $catalogo,
        "catalogo_versao_atual" => VERSAO_CATALOGO_ESCOLAS,
        "administradores"       => count(emailsAdministradores()),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar o resumo.", 500, $erro);
}
