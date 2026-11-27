<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../helpers/diagnosticoEnvio.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Diagnóstico do envio de mensagens aos responsáveis - só a equipe do
 * sistema (404 para as demais contas).
 *
 *   { verificar_dominio?: true }   consulta (só leitura) o domínio do
 *                                  remetente no Resend
 *   -> { email: {configurado, ...}, whatsapp: {...}, avisos: [...],
 *        ultimos_30_dias: {canal: {enviadas, avisadas_por_fora, pendentes, erros, ultimo_erro, ultimo_erro_em}},
 *        canais_das_escolas: {canal: {escolas, verificadas, com_erro}},
 *        dominio?: {situacao, texto} }
 *
 * Nenhum valor de variável de ambiente sai daqui: só se está configurada.
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$nomes = ['EMAIL_API_URL', 'EMAIL_API_TOKEN', 'EMAIL_REMETENTE', 'SMTP_REMETENTE', 'WHATSAPP_API_URL', 'WHATSAPP_TOKEN', 'WHATSAPP_TEMPLATE', 'PROVEDORES_FALSOS_URL'];
$ambiente = [];
foreach ($nomes as $nome) {
    $ambiente[$nome] = (string) getenv($nome);
}
$tem = fn(string $nome) => trim($ambiente[$nome]) !== '';

try {
    $stmt = $conexao->query("
        SELECT canal,
               COUNT(*) FILTER (WHERE status_notificacao = 'Enviado' AND confirmado_em IS NULL) AS enviadas,
               COUNT(*) FILTER (WHERE status_notificacao = 'Enviado' AND confirmado_em IS NOT NULL) AS avisadas_por_fora,
               COUNT(*) FILTER (WHERE status_notificacao = 'Pendente') AS pendentes,
               COUNT(*) FILTER (WHERE status_notificacao = 'Erro') AS erros
        FROM tb_notificacao
        WHERE COALESCE(criado_em, data_envio_notificacao) >= NOW() - INTERVAL '30 days'
          AND canal IN ('email', 'whatsapp')
        GROUP BY canal
    ");
    $porCanal = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $porCanal[$linha['canal']] = array_map('intval', array_diff_key($linha, ['canal' => true]));
    }
    $ultimo = $conexao->prepare("
        SELECT erro_envio, COALESCE(criado_em, data_envio_notificacao) AS quando
        FROM tb_notificacao
        WHERE canal = ? AND status_notificacao = 'Erro' AND erro_envio IS NOT NULL
        ORDER BY COALESCE(criado_em, data_envio_notificacao) DESC NULLS LAST, id_notificacao DESC
        LIMIT 1
    ");
    $ultimos30 = [];
    foreach (['email', 'whatsapp'] as $canal) {
        $ultimo->execute([$canal]);
        $erro = $ultimo->fetch(PDO::FETCH_ASSOC) ?: null;
        $ultimos30[$canal] = ($porCanal[$canal] ?? ['enviadas' => 0, 'avisadas_por_fora' => 0, 'pendentes' => 0, 'erros' => 0]) + [
            'ultimo_erro'    => $erro['erro_envio'] ?? null,
            'ultimo_erro_em' => $erro['quando'] ?? null,
        ];
    }
    // Escolas com canal próprio (migração 015): quantas, conferidas e com erro.
    $canaisDasEscolas = ['email' => ['escolas' => 0, 'verificadas' => 0, 'com_erro' => 0], 'whatsapp' => ['escolas' => 0, 'verificadas' => 0, 'com_erro' => 0]];
    foreach ($conexao->query("
        SELECT canal, COUNT(*) AS escolas, COUNT(verificado_em) AS verificadas, COUNT(ultimo_erro) AS com_erro
        FROM tb_canal_envio GROUP BY canal
    ")->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $canaisDasEscolas[$linha['canal']] = array_map('intval', array_diff_key($linha, ['canal' => true]));
    }
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao montar o diagnóstico do envio.", 500, $erro);
}

$resposta = [
    'email' => [
        'configurado' => ($tem('EMAIL_API_URL') && $tem('EMAIL_API_TOKEN') && $tem('EMAIL_REMETENTE')) || $tem('SMTP_REMETENTE'),
        'provedor'    => $tem('EMAIL_API_URL') ? (parse_url($ambiente['EMAIL_API_URL'], PHP_URL_HOST) ?: 'endereço inválido') : ($tem('SMTP_REMETENTE') ? 'mail() do servidor' : null),
        'dominio_remetente' => dominioDoRemetente($ambiente['EMAIL_REMETENTE']),
    ],
    'whatsapp' => [
        'configurado' => $tem('WHATSAPP_API_URL') && $tem('WHATSAPP_TOKEN'),
        'modelo'      => $tem('WHATSAPP_TEMPLATE'),
    ],
    'avisos' => avisosDaConfiguracaoDeEnvio($ambiente),
    'ultimos_30_dias' => $ultimos30,
    'canais_das_escolas' => $canaisDasEscolas,
];
if (($dados['verificar_dominio'] ?? false) === true) {
    $resposta['dominio'] = verificarDominioNoResend();
}
enviarSucesso("Diagnóstico do envio carregado.", $resposta);
