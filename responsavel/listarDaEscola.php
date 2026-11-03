<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Responsáveis da escola numa chamada só: cada responsável com os alunos
 * que acompanha (e o parentesco), e a lista de alunos ainda sem nenhum
 * responsável - quem a escola não consegue avisar.
 *
 *   { busca?: nome, CPF, telefone ou nome do aluno }
 *
 * Antes a tela de Responsáveis do site carregava a lista de alunos e
 * depois chamava listarResponsaveis.php uma vez por aluno, em sequência:
 * com 200 alunos eram 200 idas ao servidor.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$busca = is_string($dados['busca'] ?? null) ? mb_strtolower(trim($dados['busca'])) : '';
$buscaDigitos = preg_replace('/\D/', '', $busca);

try {
    $stmt = $conexao->prepare("
        SELECT r.id_responsavel, r.nome_responsavel, r.cpf_responsavel, r.telefone_responsavel, r.email_responsavel,
               r.genero_responsavel, r.canal_preferido,
               a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala, ar.parentesco
        FROM tb_aluno_responsavel ar
        JOIN tb_responsavel r ON r.id_responsavel = ar.id_responsavel
        JOIN tb_aluno a ON a.id_aluno = ar.id_aluno
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE s.id_escola = ?
        ORDER BY r.nome_responsavel, r.id_responsavel, a.nome_aluno
    ");
    $stmt->execute([$idEscola]);

    $responsaveis = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $id = (int) $linha['id_responsavel'];
        $responsaveis[$id] ??= [
            "id_responsavel"       => $id,
            "nome_responsavel"     => $linha['nome_responsavel'],
            "cpf_responsavel"      => $linha['cpf_responsavel'],
            "telefone_responsavel" => $linha['telefone_responsavel'],
            "email_responsavel"    => $linha['email_responsavel'],
            "genero_responsavel"   => $linha['genero_responsavel'],
            "canal_preferido"      => $linha['canal_preferido'],
            "alunos"               => [],
        ];
        $responsaveis[$id]['alunos'][] = [
            "id_aluno"   => (int) $linha['id_aluno'],
            "nome_aluno" => $linha['nome_aluno'],
            "id_sala"    => (int) $linha['id_sala'],
            "nome_sala"  => $linha['nome_sala'],
            "parentesco" => $linha['parentesco'],
        ];
    }

    if ($busca !== '') {
        $responsaveis = array_filter($responsaveis, function ($r) use ($busca, $buscaDigitos) {
            $nomes = mb_strtolower($r['nome_responsavel'] . ' ' . implode(' ', array_column($r['alunos'], 'nome_aluno')));
            return str_contains($nomes, $busca)
                || ($buscaDigitos !== '' && (str_contains((string) $r['cpf_responsavel'], $buscaDigitos)
                    || str_contains((string) $r['telefone_responsavel'], $buscaDigitos)));
        });
    }

    $semResponsavel = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, s.id_sala, s.nome_sala
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE s.id_escola = ? AND NOT EXISTS (SELECT 1 FROM tb_aluno_responsavel ar WHERE ar.id_aluno = a.id_aluno)
        ORDER BY s.nome_sala, a.nome_aluno
    ");
    $semResponsavel->execute([$idEscola]);

    enviarSucesso("Responsáveis carregados.", [
        "responsaveis"    => array_values($responsaveis),
        "sem_responsavel" => array_map(fn($a) => ['id_aluno' => (int) $a['id_aluno'], 'id_sala' => (int) $a['id_sala']] + $a,
            $semResponsavel->fetchAll(PDO::FETCH_ASSOC)),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os responsáveis.", 500, $erro);
}
