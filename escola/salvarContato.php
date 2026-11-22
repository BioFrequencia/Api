<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/contatoEscola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão altera o contato da PRÓPRIA escola com os responsáveis:
 *
 *   { email_contato?, telefone_contato? }   (ausente: não muda; vazio: apaga)
 *   -> { email, telefone, atualizado_em, atualizado_por }
 *
 * A escola é a da conta logada - não há como mandar o id de outra.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true);
if (!is_array($dados)) {
    enviarErro("Envie o contato em JSON.", 422);
}
try {
    $idEscola = (int) $conta['id_escola'];
    [$novo, $erro] = lerContatoDaEscola($dados, contatoDaEscola($conexao, $idEscola));
    if ($erro !== null) {
        enviarErro($erro, 422);
    }
    salvarContatoDaEscola($conexao, $idEscola, $novo, (int) $idCoordenacao);
    enviarSucesso("Contato da escola salvo.", contatoDaEscola($conexao, $idEscola));
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao salvar o contato da escola.", 500, $erro);
}
