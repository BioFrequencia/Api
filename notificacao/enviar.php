<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/mensagemManual.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A coordenação envia a mensagem (a mesma da prévia):
 *
 *   { id_aluno, mensagem, destinos: [{id_responsavel, canal}], chave }
 *   -> { resultados: [{id_notificacao, id_responsavel, nome, canal, contato,
 *                      status: "enviada"|"pendente"|"erro", repetido, link}],
 *        resumo: {enviadas, pendentes, erros, repetidas} }
 *
 * "chave": gerada pela tela a cada mensagem nova. O mesmo pedido de novo
 * (clique duplo, conexão que caiu) não manda outra vez - devolve o que já
 * foi feito (helpers/mensagemManual.php). Fica no histórico com quem
 * enviou, a data, o horário e a situação.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

if (!inteiroValido($dados['id_aluno'] ?? null)) {
    enviarErro("Escolha o aluno.", 422);
}
if (!chaveDeEnvioValida($dados['chave'] ?? null)) {
    enviarErro("Envio sem chave válida: abra a prévia de novo.", 422);
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
    enviarErro("Erro no servidor ao enviar a mensagem.", 500, $erro);
}
if ($aluno === null) {
    enviarErro("Aluno não encontrado nesta escola.", 404);
}
$montado = montarEnvios($aluno, $aluno['responsaveis'], $destinos, $texto, $escola, hojeNaEscola(), provedoresConfigurados());
if ($montado['erros'] !== []) {
    enviarErro(implode(' ', $montado['erros']), 422, null, ['erros' => $montado['erros']]);
}

try {
    $saida = registrarEEntregar($conexao, $aluno['id'], (int) $idCoordenacao, $dados['chave'], $montado['envios'], $aluno['responsaveis']);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao enviar a mensagem. Nada foi enviado.", 500, $erro);
}
enviarSucesso(mensagemDoEnvio($saida['resumo']), $saida);
