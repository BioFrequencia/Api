<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/equipe.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A equipe do sistema decide sobre qualquer conta:
 *
 *   { id_coordenacao, acao: aprovar | recusar | desativar | reativar |
 *                           tornar_gestor | tornar_coordenador | mover_escola |
 *                           confirmar_email,
 *     id_escola? (para mover_escola),
 *     papel?: gestor | coordenador (na aprovação: a permissão escolhida) }
 *
 * confirmar_email: a equipe confirma o e-mail no lugar da pessoa, quando o
 * link não tem como chegar (o envio de e-mails ainda não foi configurado,
 * por exemplo). Só a equipe do sistema faz isso, depois de conferir com a
 * pessoa que o endereço é dela.
 *
 * mover_escola: a conta passa para outra escola (ex.: escolheu a escola
 * errada no cadastro) - como gestora se a escola nova não tiver gestão,
 * senão como coordenadora ativa. As mesmas regras da gestão valem para o
 * resto (helpers/equipe.php).
 */
$idCoordenacao = autenticar();
exigirAdministrador($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$acao = $dados['acao'] ?? null;
if (!inteiroValido($dados['id_coordenacao'] ?? null)) {
    enviarErro("Informe a conta.", 422);
}
$papelEscolhido = $dados['papel'] ?? null;
if ($papelEscolhido !== null && !in_array($papelEscolhido, PAPEIS, true)) {
    enviarErro("Permissão inválida: escolha Gestão ou Coordenação.", 422);
}
if (!in_array($acao, [...ACOES_EQUIPE, 'mover_escola', 'confirmar_email'], true)) {
    enviarErro("Ação inválida.", 422);
}

try {
    $membro = membroDaEquipe($conexao, (int) $dados['id_coordenacao']);
    if ($membro === null) {
        enviarErro("Conta não encontrada.", 404);
    }

    if ($acao === 'confirmar_email') {
        if ($membro['email_verificado']) {
            enviarErro("O e-mail desta conta já está confirmado.", 409);
        }
        $conexao->prepare("UPDATE tb_coordenacao SET email_verificado_em = NOW() WHERE id_coordenacao = ?")
            ->execute([$membro['id_coordenacao']]);
    } elseif ($acao === 'mover_escola') {
        if (!inteiroValido($dados['id_escola'] ?? null)) {
            enviarErro("Informe a escola de destino.", 422);
        }
        $destino = (int) $dados['id_escola'];
        $existe = $conexao->prepare("SELECT 1 FROM tb_escola WHERE id_escola = ?");
        $existe->execute([$destino]);
        if (!$existe->fetchColumn()) {
            enviarErro("Escola de destino não encontrada.", 404);
        }
        if ($destino === $membro['id_escola']) {
            enviarErro("A conta já é dessa escola.", 409);
        }
        if ($membro['papel'] === 'gestor' && $membro['situacao'] === 'ativo' && !escolaTemGestor($conexao, $membro['id_escola'], $membro['id_coordenacao'])) {
            enviarErro("A escola atual ficaria sem gestão ativa: promova outra conta de lá antes.", 409);
        }
        $papel = escolaTemGestor($conexao, $destino) ? 'coordenador' : 'gestor';
        $move = $conexao->prepare("
            UPDATE tb_coordenacao SET id_escola = ?, papel = ?, situacao = 'ativo', aprovado_em = NOW(), aprovado_por = ?
            WHERE id_coordenacao = ?
        ");
        $move->execute([$destino, $papel, (int) $idCoordenacao, $membro['id_coordenacao']]);
    } else {
        $erro = aplicarAcaoNaEquipe($conexao, $membro, $acao, (int) $idCoordenacao, $papelEscolhido);
        if ($erro !== null) {
            enviarErro($erro, 409);
        }
    }
    enviarSucesso("Conta atualizada.", membroDaEquipe($conexao, $membro['id_coordenacao']));
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao atualizar a conta.", 500, $erro);
}
