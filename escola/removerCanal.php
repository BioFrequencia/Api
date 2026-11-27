<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/canaisEnvio.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão tira um canal da PRÓPRIA escola: { canal: "email"|"whatsapp" }.
 * A credencial é apagada do banco; os avisos passam a sair pelo canal do
 * sistema ou a ficar na fila (WhatsApp: com o link para mandar à mão).
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$tipo = is_string($dados['canal'] ?? null) ? $dados['canal'] : '';
if (!in_array($tipo, CANAIS_DE_ENVIO, true)) {
    enviarErro("Canal inválido: use email ou whatsapp.", 422);
}
try {
    $stmt = $conexao->prepare("DELETE FROM tb_canal_envio WHERE id_escola = ? AND canal = ?");
    $stmt->execute([$conta['id_escola'], $tipo]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao remover o canal.", 500, $erro);
}
if ($stmt->rowCount() === 0) {
    enviarErro("Este canal não estava configurado.", 404);
}
enviarSucesso($tipo === 'email' ? "E-mail da escola removido." : "WhatsApp da escola removido.");
