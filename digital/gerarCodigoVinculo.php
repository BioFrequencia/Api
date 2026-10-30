<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/dispositivo.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Gerado pela coordenação (autenticada) e repassado ao aluno/responsável
 * para digitar no app - é a única credencial usada em vincularDispositivo.php,
 * que não exige token de coordenador (o celular do aluno não tem um).
 * Curto prazo de validade + uso único limitam o risco de um código
 * "vazado" ser usado por outra pessoa.
 *
 * Só para alunos da escola de quem gera: o código dá ao aparelho o
 * direito de enviar ocorrências em nome do aluno, e antes qualquer conta
 * gerava código para aluno de outra escola.
 *
 * A validade é calculada pelo relógio do BANCO (NOW() + 15 minutos), o
 * mesmo usado na conferência em vincularDispositivo.php. Antes era
 * calculada no PHP: no XAMPP em horário de Berlim, os códigos de
 * produção ficaram válidos por 2h15.
 */
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}

exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    // Invalida qualquer código anterior ainda não usado para este aluno,
    // para não deixar vários códigos válidos circulando ao mesmo tempo.
    $invalida = $conexao->prepare("UPDATE tb_codigo_vinculo SET usado = TRUE WHERE id_aluno = ? AND usado = FALSE");
    $invalida->bindValue(1, $idAluno);
    $invalida->execute();

    $insert = $conexao->prepare("
        INSERT INTO tb_codigo_vinculo (id_aluno, id_coordenacao, codigo, expira_em)
        VALUES (?, ?, ?, NOW() + INTERVAL '15 minutes')
        RETURNING expira_em
    ");

    // Colisão de código é astronomicamente improvável (32^8 combinações),
    // mas a coluna é UNIQUE - tenta de novo em vez de falhar de primeira.
    $tentativas = 0;
    do {
        $codigo = gerarCodigoVinculo();
        $tentativas++;
        try {
            $insert->bindValue(1, $idAluno);
            $insert->bindValue(2, $idCoordenacao);
            $insert->bindValue(3, $codigo);
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
        // UTC, como todo horário da API (o site mostra no horário local).
        "expira_em" => $insert->fetchColumn(),
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao gerar o código de vínculo.", 500, $erro);
}
