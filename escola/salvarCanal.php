<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/canaisEnvio.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão cadastra ou troca um canal de envio da PRÓPRIA escola:
 *
 *   e-mail:   { canal: "email", provedor: "brevo"|"resend", remetente, nome_remetente?, credencial? }
 *   WhatsApp: { canal: "whatsapp", numero, id_numero, modelo, idioma_modelo?, credencial? }
 *
 * credencial em branco mantém a gravada (mesmo provedor). Antes de gravar,
 * o provedor confere a configuração sem mandar mensagem:
 *   - recusou (chave errada, remetente não verificado, ID de outro número):
 *     422 com o motivo, nada gravado;
 *   - não deu para conferir (provedor fora do ar, chave só de envio):
 *     grava e avisa para usar o envio de teste;
 *   - confirmou: grava como verificado.
 * O e-mail, o número e o ID do número não podem ser de outra escola (409).
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);
$idEscola = $conta['id_escola'];
$dados = json_decode(file_get_contents("php://input"), true);
if (!is_array($dados)) {
    enviarErro("Envie a configuração em JSON.", 422);
}
$tipo = is_string($dados['canal'] ?? null) ? $dados['canal'] : '';
if (!in_array($tipo, CANAIS_DE_ENVIO, true)) {
    enviarErro("Canal inválido: use email ou whatsapp.", 422);
}
if (chaveDosCanaisDeEnvio() === null) {
    enviarErro("O servidor ainda não tem a chave que protege as credenciais (BIOMETRIA_CHAVE). Avise a equipe do BioFrequência.", 503);
}

try {
    $atual = canalGravado($conexao, $idEscola, $tipo);
    [$canal, $erro] = lerCanalDeEnvio($dados, $tipo, $atual);
    if ($erro !== null) {
        enviarErro($erro, 422);
    }
    if (canalDeOutraEscola($conexao, $idEscola, $canal)) {
        enviarErro($tipo === 'email'
            ? "Este e-mail já é usado por outra escola no BioFrequência. Cada escola precisa do próprio remetente."
            : "Este número de WhatsApp já é usado por outra escola no BioFrequência. Cada escola precisa do próprio número.", 409);
    }
    $credencial = $canal['credencial'] ?? credencialDoCanal($conexao, $idEscola, $tipo);
    if ($credencial === null) {
        enviarErro("A chave gravada não pôde ser aberta no servidor. Cole a chave de novo.", 422);
    }
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao conferir o envio automático.", 500, $erro);
}

$conferencia = conferirCanalNoProvedor($canal, $credencial);
if ($conferencia['situacao'] === 'recusado') {
    enviarErro($conferencia['texto'], 422, null, ['conferencia' => $conferencia]);
}

try {
    gravarCanalDeEnvio($conexao, $idEscola, $canal, $canal['credencial'], $conferencia, (int) $idCoordenacao);
    $canais = canaisDaEscolaParaTela($conexao, $idEscola);
} catch (PDOException $erro) {
    if ($erro->getCode() === '23505') {
        enviarErro("Este remetente acabou de ser cadastrado por outra escola. Cada escola precisa do próprio.", 409);
    }
    enviarErro("Erro no servidor ao salvar o envio automático.", 500, $erro);
}
enviarSucesso(
    $conferencia['situacao'] === 'ok' ? "Salvo. " . $conferencia['texto'] : "Salvo, mas ainda não conferido. " . $conferencia['texto'],
    ['canal' => $canais[$tipo], 'conferencia' => $conferencia]
);
