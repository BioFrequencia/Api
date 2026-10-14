<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Colunas confirmadas no schema do Supabase: id_sala, nome_sala, periodo_sala.
 */

$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    // Restrito à escola de quem está logado: antes, qualquer conta via
    // as turmas de todas as escolas da mesma instalação.
    $select = $conexao->prepare("SELECT id_sala, nome_sala, periodo_sala FROM tb_sala WHERE id_escola = ? ORDER BY nome_sala ASC");
    $select->bindValue(1, $idEscola);
    $select->execute();
    $salas = $select->fetchAll(PDO::FETCH_ASSOC);

    enviarSucesso("Salas carregadas com sucesso.", $salas);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as salas.", 500, $erro);
}
