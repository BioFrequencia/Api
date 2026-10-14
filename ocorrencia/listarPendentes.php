<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Ocorrências enviadas pelo app que a coordenação precisa revisar.
 * Restrito à escola de quem está logado (ver helpers/escola.php).
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados  = json_decode(file_get_contents("php://input"), true) ?? [];
$status = $dados["status"] ?? 'pendente';
if (!in_array($status, ['pendente', 'aprovado', 'rejeitado', 'todos'], true)) {
    enviarErro("Situação inválida.", 422);
}

try {
    $filtroStatus = $status === 'todos' ? '' : ' AND o.status = :status';
    $sql = "
        SELECT o.id_ocorrencia, o.id_aluno, a.nome_aluno, s.nome_sala,
               o.tipo, o.motivo, o.observacao, o.responsavel_ciente,
               o.status, o.criado_em, o.revisado_em, o.observacao_revisao,
               c.nome_coordenacao AS revisado_por
        FROM tb_ocorrencia_pendente o
        JOIN tb_aluno a ON a.id_aluno = o.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        LEFT JOIN tb_coordenacao c ON c.id_coordenacao = o.id_coordenacao_revisao
        WHERE s.id_escola = :escola $filtroStatus
        ORDER BY o.criado_em DESC
        LIMIT 200
    ";
    $stmt = $conexao->prepare($sql);
    $stmt->bindValue(':escola', $idEscola, PDO::PARAM_INT);
    if ($status !== 'todos') {
        $stmt->bindValue(':status', $status);
    }
    $stmt->execute();
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ocorrencias = array_map(function ($o) {
        return [
            "id_ocorrencia"      => (int) $o['id_ocorrencia'],
            "id_aluno"           => (int) $o['id_aluno'],
            "nome_aluno"         => $o['nome_aluno'],
            "turma"              => $o['nome_sala'],
            "tipo"               => $o['tipo'],
            "motivo"             => $o['motivo'],
            "observacao"         => $o['observacao'],
            "responsavel_ciente" => (bool) $o['responsavel_ciente'],
            "status"             => $o['status'],
            "criado_em"          => $o['criado_em'],
            "revisado_em"        => $o['revisado_em'],
            "revisado_por"       => $o['revisado_por'],
            "observacao_revisao" => $o['observacao_revisao'],
        ];
    }, $registros);

    enviarSucesso("Ocorrências carregadas com sucesso.", [
        "ocorrencias" => $ocorrencias,
        "total"       => count($ocorrencias),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao listar as ocorrências.", 500, $erro);
}
