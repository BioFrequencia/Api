<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/canaisEnvio.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão manda uma mensagem de teste pelo canal da PRÓPRIA escola, para
 * um e-mail ou celular que ela digita (o dela):
 *
 *   { canal: "email"|"whatsapp", destino } -> { situacao: "enviado"|"falhou", motivo }
 *
 * Sai pelo mesmo caminho dos avisos (mesmo remetente e modelo). "Enviado"
 * quer dizer que o provedor aceitou; vale conferir se chegou. O resultado
 * fica no canal (verificado ou o último erro). Um teste a cada 15 s.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);
$idEscola = $conta['id_escola'];
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$tipo = is_string($dados['canal'] ?? null) ? $dados['canal'] : '';
if (!in_array($tipo, CANAIS_DE_ENVIO, true)) {
    enviarErro("Canal inválido: use email ou whatsapp.", 422);
}
$destino = is_string($dados['destino'] ?? null) ? trim($dados['destino']) : '';
if ($tipo === 'email') {
    if (!emailValido($destino)) {
        enviarErro("Informe o e-mail que vai receber o teste.", 422);
    }
} else {
    $destino = telefoneParaWhatsapp($destino) ?? '';
    if ($destino === '') {
        enviarErro("Informe o celular que vai receber o teste, com DDD.", 422);
    }
}

try {
    // A trava do intervalo é tomada antes do envio: dois cliques não mandam dois testes.
    $trava = $conexao->prepare("
        UPDATE tb_canal_envio SET ultimo_teste_em = NOW()
        WHERE id_escola = ? AND canal = ?
          AND (ultimo_teste_em IS NULL OR ultimo_teste_em < NOW() - make_interval(secs => ?::int))
        RETURNING id_canal
    ");
    $trava->execute([$idEscola, $tipo, ESPERA_ENTRE_TESTES_SEGUNDOS]);
    if ($trava->fetchColumn() === false) {
        if (canalGravado($conexao, $idEscola, $tipo) === null) {
            enviarErro("Configure o " . ($tipo === 'email' ? 'e-mail' : 'WhatsApp') . " da escola antes de testar.", 409);
        }
        enviarErro("Acabamos de mandar um teste. Espere alguns segundos antes de outro.", 429);
    }
    $canal = canaisParaEntrega($conexao, $idEscola)[$tipo];
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao preparar o teste.", 500, $erro);
}

$texto = "Mensagem de teste do envio automático do BioFrequência ({$canal['nome_escola']}). "
    . "Se chegou, os avisos aos responsáveis vão sair por aqui.";
[$resultado, $motivo] = $tipo === 'email'
    ? enviarEmailPeloCanal($canal, $destino, 'Teste do envio automático - BioFrequência', $texto, null, null, null)
    : enviarWhatsappPeloCanal($canal, $destino, $texto);

try {
    $conexao->prepare("
        UPDATE tb_canal_envio
        SET verificado_em = CASE WHEN ?::boolean THEN NOW() ELSE verificado_em END, ultimo_erro = ?
        WHERE id_escola = ? AND canal = ?
    ")->execute([$resultado === ENVIO_OK ? 'true' : 'false', $resultado === ENVIO_OK ? null : $motivo, $idEscola, $tipo]);
} catch (PDOException $erro) {
    error_log('[BioFrequenciaApi] Resultado do teste não gravado: ' . $erro->getMessage());
}
if ($resultado !== ENVIO_OK) {
    enviarErro($motivo ?? "O provedor recusou o teste.", 422, null, ['situacao' => 'falhou', 'motivo' => $motivo]);
}
enviarSucesso("O provedor aceitou o teste. Confira se a mensagem chegou.", ['situacao' => 'enviado', 'motivo' => null]);
