<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Desconecta aparelhos da conta. O token deles deixa de valer na próxima
 * chamada (o app volta para o login; o site também).
 *
 *   { id_sessao: "..." }  uma sessão - pode ser a deste próprio aparelho
 *   { outras: true }      todas, menos a deste aparelho
 */
$idCoordenacao = (int) autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idSessao = $dados["id_sessao"] ?? null;
$outras = ($dados["outras"] ?? false) === true;

if (!$outras && (!is_string($idSessao) || preg_match('/^[0-9a-f]{32}$/', $idSessao) !== 1)) {
    enviarErro("Informe o aparelho a desconectar.", 422);
}

try {
    if ($outras) {
        $quantas = encerrarSessoes($conexao, $idCoordenacao, null, sessaoAtual());
        enviarSucesso($quantas === 1 ? "1 aparelho desconectado." : "$quantas aparelhos desconectados.", ['encerradas' => $quantas]);
    }
    if (encerrarSessoes($conexao, $idCoordenacao, $idSessao) === 0) {
        enviarErro("Este aparelho já estava desconectado.", 404);
    }
    enviarSucesso("Aparelho desconectado.", ['encerradas' => 1, 'era_este' => $idSessao === sessaoAtual()]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao desconectar o aparelho.", 500, $erro);
}
