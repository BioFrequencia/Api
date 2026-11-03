<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/equipe.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Equipe da escola (contas de coordenação). A gestão vê todo mundo,
 * inclusive quem aguarda aprovação, com o email; o restante da equipe vê
 * só quem está ativo. "pendentes" conta só quem já confirmou o e-mail -
 * os que a gestão já pode aprovar (é o número no menu).
 */
$idCoordenacao = autenticar();
$conta = contaDaCoordenacao($conexao, $idCoordenacao);

try {
    $equipe = equipeDaEscola($conexao, $conta['id_escola']);
    if ($conta['papel'] !== 'gestor') {
        $equipe = array_values(array_map(
            fn($m) => ['id_coordenacao' => $m['id_coordenacao'], 'nome_coordenacao' => $m['nome_coordenacao'], 'papel' => $m['papel'], 'situacao' => $m['situacao']],
            array_filter($equipe, fn($m) => $m['situacao'] === 'ativo')
        ));
    }
    foreach ($equipe as &$membro) {
        $membro['voce'] = $membro['id_coordenacao'] === (int) $idCoordenacao;
    }
    unset($membro);
    enviarSucesso("Equipe carregada.", [
        "equipe"      => $equipe,
        "pendentes"   => count(array_filter($equipe, fn($m) => $m['situacao'] === 'pendente' && !empty($m['email_verificado']))),
        "pode_editar" => $conta['papel'] === 'gestor',
    ]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar a equipe.", 500, $erro);
}
