<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Troca a senha da conta. Confere a senha atual e desconecta os outros
 * aparelhos: quem troca a senha por desconfiar de alguém não quer essa
 * pessoa ainda conectada. Este aparelho continua conectado.
 *
 *   { senha_atual, senha_nova }
 */
$idCoordenacao = (int) autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$senhaAtual = $dados["senha_atual"] ?? null;
$senhaNova = $dados["senha_nova"] ?? null;

if (!campoPreenchido($senhaAtual) || !campoPreenchido($senhaNova)) {
    enviarErro("Informe a senha atual e a nova senha.", 422);
}
if (!senhaForte($senhaNova)) {
    enviarErro("A nova senha deve ter pelo menos 6 caracteres.", 422);
}
if ($senhaNova === $senhaAtual) {
    enviarErro("A nova senha precisa ser diferente da atual.", 422);
}

try {
    $stmt = $conexao->prepare("SELECT senha_coordenacao FROM tb_coordenacao WHERE id_coordenacao = ?");
    $stmt->execute([$idCoordenacao]);
    $hash = $stmt->fetchColumn();
    // 403 e não 401: o site trata 401 como sessão vencida e sairia da conta.
    if ($hash === false || !verificarSenhaComMigracao($senhaAtual, $hash)['valida']) {
        enviarErro("A senha atual não confere.", 403);
    }

    $conexao->prepare("UPDATE tb_coordenacao SET senha_coordenacao = ? WHERE id_coordenacao = ?")
        ->execute([password_hash($senhaNova, PASSWORD_DEFAULT), $idCoordenacao]);

    // A senha já mudou: uma falha aqui não pode virar "erro ao trocar a senha".
    $encerradas = 0;
    try {
        $encerradas = encerrarSessoes($conexao, $idCoordenacao, null, sessaoAtual());
    } catch (PDOException $erroSessoes) {
        error_log('[BioFrequenciaApi] sessões não encerradas após troca de senha: ' . $erroSessoes->getMessage());
    }
    enviarSucesso(
        $encerradas > 0 ? "Senha alterada. Os outros aparelhos foram desconectados." : "Senha alterada.",
        ['sessoes_encerradas' => $encerradas]
    );
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao trocar a senha.", 500, $erro);
}
