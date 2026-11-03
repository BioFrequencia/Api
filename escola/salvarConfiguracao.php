<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/configuracaoEscola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão altera a configuração da escola. Só os campos enviados mudam:
 *
 *   { tolerancia_minutos?, limite_alerta_percentual?, minimo_ocorrencias_alerta?,
 *     janela_alerta_dias?, intervalo_aviso_dias?, aviso_automatico?,
 *     aviso_cada_registro?, atraso_pela_presenca? }
 *
 * Faixas e significado de cada campo em helpers/configuracaoEscola.php.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true);
if (!is_array($dados)) {
    enviarErro("Envie a configuração em JSON.", 422);
}

try {
    [$nova, $erro] = lerConfiguracao($dados, configuracaoDaEscola($conexao, $conta['id_escola']));
    if ($erro !== null) {
        enviarErro($erro, 422);
    }
    salvarConfiguracaoDaEscola($conexao, $conta['id_escola'], $nova, (int) $idCoordenacao);
    enviarSucesso("Configuração da escola salva.", $nova);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao salvar a configuração.", 500, $erro);
}
