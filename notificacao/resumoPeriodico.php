<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/tempo.php';
require_once __DIR__ . '/../helpers/resumoPeriodico.php';

/**
 * Resumo periódico às famílias (ver helpers/resumoPeriodico.php). Chamado
 * pelo agendador, não por uma conta: o cabeçalho X-Chave-Agendador tem de
 * bater com AGENDADOR_CHAVE do servidor (16+ caracteres). Sem a variável,
 * responde 503 e não manda nada.
 *
 *   POST (corpo vazio)  -> { escolas, alunos, enviados, na_fila, falhas, repetidos, sem_contato }
 *
 * O dia é sempre o de hoje no fuso da escola: quem tem a chave não
 * consegue mandar o resumo de outro período. Só nos testes (variável
 * AGENDADOR_ACEITA_DATA=1, nunca em produção) o corpo pode trazer
 * { data: "AAAA-MM-DD" }.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    enviarErro("Use POST.", 405);
}
$configurada = getenv('AGENDADOR_CHAVE');
if ($configurada === false || strlen(trim($configurada)) < 16) {
    enviarErro("O resumo periódico não está configurado neste servidor (AGENDADOR_CHAVE).", 503);
}
if (!chaveDoAgendadorConfere($configurada, $_SERVER['HTTP_X_CHAVE_AGENDADOR'] ?? null)) {
    enviarErro("Chave do agendador inválida.", 401);
}

$hoje = hojeNaEscola();
if (getenv('AGENDADOR_ACEITA_DATA') === '1') {
    $corpo = json_decode(file_get_contents("php://input"), true) ?? [];
    if (isset($corpo['data']) && dataIsoValida($corpo['data'])) {
        $hoje = $corpo['data'];
    }
}

try {
    $resultado = rodarResumoPeriodico($conexao, $hoje);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao montar o resumo.", 500, $erro);
}
enviarSucesso("Resumo periódico processado.", $resultado);
