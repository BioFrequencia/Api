<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/conta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Abre um chamado de suporte. A equipe do sistema responde pelo banco
 * (status e resposta em tb_chamado_suporte) e a coordenação acompanha na
 * tela Suporte do site.
 *
 *   { categoria: "duvida" | "problema" | "biometria" | "conta" | "sugestao",
 *     assunto, mensagem }
 */
$idCoordenacao = (int) autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
[$chamado, $erro] = lerChamado(is_array($dados) ? $dados : []);
if ($erro !== null) {
    enviarErro($erro, 422);
}

try {
    $hoje = $conexao->prepare("
        SELECT count(*) FROM tb_chamado_suporte
        WHERE id_coordenacao = ? AND criado_em > NOW() - INTERVAL '1 day'
    ");
    $hoje->execute([$idCoordenacao]);
    if ((int) $hoje->fetchColumn() >= LIMITE_CHAMADOS_POR_DIA) {
        enviarErro("Você já abriu " . LIMITE_CHAMADOS_POR_DIA . " chamados nas últimas 24 horas. Aguarde a resposta dos anteriores.", 429);
    }

    $insere = $conexao->prepare("
        INSERT INTO tb_chamado_suporte (id_coordenacao, categoria, assunto, mensagem)
        VALUES (?, ?, ?, ?)
        RETURNING id_chamado, categoria, assunto, mensagem, status, resposta, respondido_em, criado_em, atualizado_em
    ");
    $insere->execute([$idCoordenacao, $chamado['categoria'], $chamado['assunto'], $chamado['mensagem']]);

    enviarSucesso("Chamado aberto. A resposta aparece aqui em Suporte.", $insere->fetch(PDO::FETCH_ASSOC), 201);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao abrir o chamado.", 500, $erro);
}
