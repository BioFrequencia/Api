<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Alunos da escola de quem está logado, com a situação da biometria e da
 * presença de hoje - a lista do app da coordenação (cadastro de
 * biometria e registros). Só dados para identificar o aluno na tela:
 * nome, turma e data de nascimento (conferência antes de cadastrar).
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $stmt = $conexao->prepare("
        SELECT a.id_aluno, a.nome_aluno, a.dta_nasc_aluno, s.id_sala, s.nome_sala,
               bf.id_aluno IS NOT NULL AS tem_facial,
               bf.atualizado_em AS facial_atualizada_em,
               (SELECT count(*) FROM tb_biometria_digital bd WHERE bd.id_aluno = a.id_aluno) AS digitais,
               c.nome_responsavel AS consentimento_responsavel,
               c.registrado_em AS consentimento_em,
               p.registrada_em AS presenca_hoje,
               p.metodo AS presenca_metodo
        FROM tb_aluno a
        JOIN tb_sala s ON s.id_sala = a.id_sala
        LEFT JOIN tb_biometria_facial bf ON bf.id_aluno = a.id_aluno
        LEFT JOIN tb_consentimento_biometria c ON c.id_aluno = a.id_aluno
        LEFT JOIN tb_presenca p ON p.id_aluno = a.id_aluno AND p.dia_letivo = ?
        WHERE s.id_escola = ?
        ORDER BY a.nome_aluno
    ");
    $stmt->bindValue(1, hojeNaEscola());
    $stmt->bindValue(2, $idEscola);
    $stmt->execute();

    enviarSucesso("Alunos carregados.", $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os alunos.", 500, $erro);
}
