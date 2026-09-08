<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * NOVO ENDPOINT (não existia antes).
 *
 * Necessário porque a tela de cadastro de aluno enviava a sala como texto
 * livre (txtClassroom), sem nenhuma forma de obter o id_sala real esperado
 * por addaluno.php. Colunas id_sala/nome_sala confirmadas pelo uso em
 * filtroAluno.php.
 */

autenticar();

try {
    $select = $conexao->query("SELECT id_sala, nome_sala FROM tb_sala ORDER BY nome_sala ASC");
    $salas = $select->fetchAll(PDO::FETCH_ASSOC);

    enviarSucesso("Salas carregadas com sucesso.", $salas);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as salas.", 500, $erro);
}
