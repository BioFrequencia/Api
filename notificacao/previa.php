<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/mensagemManual.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Prévia da mensagem manual, sem enviar nada: a mensagem montada para cada
 * responsável e canal, com o contato e como ela sai.
 *
 *   { id_aluno, mensagem, destinos: [{id_responsavel, canal: "whatsapp"|"email"}] }
 *   -> { envios: [{id_responsavel, nome, parentesco, canal, contato, assunto, mensagem,
 *                  como: "automatico"|"link"|"fila"}] }
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!inteiroValido($dados['id_aluno'] ?? null)) {
    enviarErro("Escolha o aluno.", 422);
}
[$texto, $erro] = conferirTextoDaMensagem($dados['mensagem'] ?? null);
if ($erro !== null) {
    enviarErro($erro, 422);
}
[$destinos, $erro] = lerDestinos($dados['destinos'] ?? null);
if ($erro !== null) {
    enviarErro($erro, 422);
}

try {
    $aluno = alunoParaMensagem($conexao, (int) $dados['id_aluno'], $idEscola);
    $escola = nomeDaEscolaParaAviso($conexao, $idEscola);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao montar a prévia.", 500, $erro);
}
if ($aluno === null) {
    enviarErro("Aluno não encontrado nesta escola.", 404);
}
$montado = montarEnvios($aluno, $aluno['responsaveis'], $destinos, $texto, $escola, hojeNaEscola(), provedoresConfigurados());
if ($montado['erros'] !== []) {
    enviarErro(implode(' ', $montado['erros']), 422, null, ['erros' => $montado['erros']]);
}
enviarSucesso("Prévia pronta: confira e confirme para enviar.", ['envios' => $montado['envios']]);
