<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/presenca.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Registro de presença sem a identificação facial do servidor:
 *   - "manual": a coordenação escolhe o aluno na lista (o reconhecimento
 *     falhou, o aluno não tem biometria, etc.);
 *   - "digital": o SDK do leitor de digital identificou o aluno no
 *     aparelho (ver biometria/templatesDigitais.php).
 *
 *   { id_aluno, metodo }
 *
 * Uma presença por aluno por dia: repetir devolve a já registrada.
 * Presença nova depois da tolerância da escola também registra a entrada
 * atrasada ("entrada" na resposta).
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;
$metodo = $dados["metodo"] ?? 'manual';

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!in_array($metodo, ['manual', 'digital'], true)) {
    enviarErro("Método inválido: use manual ou digital (a facial é registrada pela identificação).", 422);
}

$idAluno = (int) $idAluno;
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);

try {
    $presenca = registrarPresenca($conexao, $idAluno, $idCoordenacao, $metodo);
    // Presença nova depois da tolerância vira entrada atrasada (helpers/presenca.php).
    $entrada = $presenca['nova']
        ? conferirEntradaDaPresenca($conexao, $idAluno, (int) $idCoordenacao, $idEscola, $presenca['registrada_em'], $metodo)
        : null;
    enviarSucesso(
        $presenca['nova'] ? mensagemDaPresenca($entrada) : "A presença deste aluno já estava registrada hoje.",
        ["aluno" => dadosDoAluno($conexao, $idAluno), "presenca" => $presenca, "entrada" => $entrada],
        $presenca['nova'] ? 201 : 200
    );
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao registrar a presença.", 500, $erro);
}
