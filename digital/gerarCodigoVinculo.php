<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/dispositivo.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Gerado pela coordenação (autenticada) e repassado ao aluno/responsável
 * para digitar no app - é a única credencial usada em vincularDispositivo.php,
 * que não exige token de coordenador (o celular do aluno não tem um).
 * Curto prazo de validade + uso único limitam o risco de um código
 * "vazado" ser usado por outra pessoa.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

try {
    $verificaAluno = $conexao->prepare("SELECT id_aluno FROM tb_aluno WHERE id_aluno = ?");
    $verificaAluno->bindValue(1, $idAluno);
    $verificaAluno->execute();
    if ($verificaAluno->rowCount() === 0) {
        enviarErro("Aluno não encontrado.", 404);
    }

    // Invalida qualquer código anterior ainda não usado para este aluno,
    // para não deixar vários códigos válidos circulando ao mesmo tempo.
    $invalida = $conexao->prepare("UPDATE tb_codigo_vinculo SET usado = TRUE WHERE id_aluno = ? AND usado = FALSE");
    $invalida->bindValue(1, $idAluno);
    $invalida->execute();

    $expiraEm = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $insert = $conexao->prepare("
        INSERT INTO tb_codigo_vinculo (id_aluno, id_coordenacao, codigo, expira_em)
        VALUES (?, ?, ?, ?)
    ");

    // Colisão de código é astronomicamente improvável (33^8 combinações),
    // mas a coluna é UNIQUE - tenta de novo em vez de falhar de primeira.
    $tentativas = 0;
    do {
        $codigo = gerarCodigoVinculo();
        $tentativas++;
        try {
            $insert->bindValue(1, $idAluno);
            $insert->bindValue(2, $idCoordenacao);
            $insert->bindValue(3, $codigo);
            $insert->bindValue(4, $expiraEm);
            $insert->execute();
            break;
        } catch (PDOException $erro) {
            $sqlState = $erro->errorInfo[0] ?? $erro->getCode();
            if ($sqlState !== '23505' || $tentativas >= 3) {
                throw $erro;
            }
        }
    } while ($tentativas < 3);

    enviarSucesso("Código de vínculo gerado.", [
        "codigo" => $codigo,
        "expira_em" => $expiraEm,
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao gerar o código de vínculo.", 500, $erro);
}
