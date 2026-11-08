<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/mensagemManual.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * "Tentar de novo" numa mensagem que deu erro no envio (o provedor recusou
 * ou não respondeu): { id_notificacao } -> { id_notificacao, status }.
 *
 * A linha é tomada num UPDATE só (de 'Erro' para 'Pendente') antes de
 * sair: dois cliques ou duas pessoas ao mesmo tempo não mandam duas
 * vezes. No e-mail vai a mesma chave de idempotência do primeiro envio -
 * se ele tinha saído e só a resposta se perdeu, o provedor não repete.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
if (!inteiroValido($dados['id_notificacao'] ?? null)) {
    enviarErro("Informe a mensagem.", 422);
}
$idNotificacao = (int) $dados['id_notificacao'];

try {
    $toma = $conexao->prepare("
        UPDATE tb_notificacao n SET status_notificacao = ?
        FROM tb_aluno a, tb_sala s
        WHERE n.id_notificacao = ? AND n.status_notificacao = ?
          AND a.id_aluno = n.id_aluno AND s.id_sala = a.id_sala AND s.id_escola = ?
        RETURNING n.id_notificacao, n.id_responsavel, n.canal, n.mensagem, n.assunto, a.nome_aluno
    ");
    $toma->execute([NOTIFICACAO_PENDENTE, $idNotificacao, NOTIFICACAO_ERRO, $idEscola]);
    $linha = $toma->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        $existe = $conexao->prepare("
            SELECT 1 FROM tb_notificacao n
            JOIN tb_aluno a ON a.id_aluno = n.id_aluno
            JOIN tb_sala s ON s.id_sala = a.id_sala
            WHERE n.id_notificacao = ? AND s.id_escola = ?
        ");
        $existe->execute([$idNotificacao, $idEscola]);
        if ($existe->fetchColumn() === false) {
            enviarErro("Mensagem não encontrada nesta escola.", 404);
        }
        enviarErro("Só dá para tentar de novo uma mensagem que deu erro no envio.", 409);
    }
    $busca = $conexao->prepare("
        SELECT id_responsavel, nome_responsavel, telefone_responsavel, email_responsavel FROM tb_responsavel WHERE id_responsavel = ?
    ");
    $busca->execute([$linha['id_responsavel']]);
    $responsavel = $busca->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao tentar de novo.", 500, $erro);
}

// Mensagens de antes da migração 011: o WhatsApp que falhou ficou gravado
// como link wa.me, e nenhuma tinha assunto (o e-mail precisa de um).
$assunto = trim((string) ($linha['assunto'] ?? ''));
if ($assunto === '') {
    $assunto = mb_substr("Aviso da escola - {$linha['nome_aluno']}", 0, 150);
}
[$resultado, $conteudo] = entregarMensagem(
    (string) $linha['canal'], $responsavel, $assunto, textoDaMensagemGravada((string) $linha['mensagem']),
    'biofrequencia-notificacao-' . $idNotificacao
);
$statusBanco = statusPorResultadoEnvio($resultado);
try {
    $conexao->prepare("UPDATE tb_notificacao SET status_notificacao = ?, mensagem = ?, data_envio_notificacao = ? WHERE id_notificacao = ?")
        ->execute([$statusBanco, $conteudo, $resultado === ENVIO_OK ? agoraUtc()->format('Y-m-d H:i:s') : null, $idNotificacao]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao gravar o resultado do envio.", 500, $erro);
}

$status = statusNotificacaoParaApi($statusBanco);
$mensagem = match ($status) {
    'enviada' => "Mensagem enviada.",
    'erro' => "O envio falhou de novo. Confira o contato do responsável ou tente mais tarde.",
    default => "Sem envio automático agora: a mensagem ficou para você mandar pelo link.",
};
enviarSucesso($mensagem, ['id_notificacao' => $idNotificacao, 'status' => $status]);
