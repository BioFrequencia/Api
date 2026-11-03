<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/calendario.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * A gestão marca dias próprios da escola no calendário:
 *
 *   { data_inicio, data_fim?, tipo: sem_aula | aula_extra, descricao }
 *
 *   sem_aula    recesso, férias, feriado municipal, conselho de classe:
 *               ninguém registra entrada atrasada nem saída antecipada e
 *               os dias não contam nas porcentagens;
 *   aula_extra  sábado letivo, reposição num feriado: vira dia de aula.
 *
 * Os feriados nacionais e o de São Paulo já entram sozinhos
 * (helpers/calendario.php). Um período tem no máximo 120 dias.
 */
$idCoordenacao = autenticar();
$conta = exigirGestor($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$inicio = $dados['data_inicio'] ?? null;
$fim = $dados['data_fim'] ?? $inicio;
$tipo = $dados['tipo'] ?? null;
$descricao = is_string($dados['descricao'] ?? null) ? trim($dados['descricao']) : '';

if (!dataIsoValida($inicio) || !dataIsoValida($fim)) {
    enviarErro("Informe as datas no formato AAAA-MM-DD.", 422);
}
if ($fim < $inicio) {
    enviarErro("A data final não pode ser antes da inicial.", 422);
}
if ((new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fim))->days > 119) {
    enviarErro("Cadastre períodos de até 120 dias (as férias de julho e de fim de ano separadas).", 422);
}
if (!in_array($tipo, ['sem_aula', 'aula_extra'], true)) {
    enviarErro("Tipo inválido: use sem_aula ou aula_extra.", 422);
}
if ($descricao === '' || mb_strlen($descricao) > 100) {
    enviarErro("Descreva o período (até 100 caracteres), ex.: \"Recesso escolar\".", 422);
}

try {
    $stmt = $conexao->prepare("
        INSERT INTO tb_calendario_escola (id_escola, data_inicio, data_fim, tipo, descricao, id_coordenacao)
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING id_calendario
    ");
    $stmt->execute([$conta['id_escola'], $inicio, $fim, $tipo, $descricao, (int) $idCoordenacao]);
    enviarSucesso($tipo === 'sem_aula' ? "Dias sem aula cadastrados." : "Dia letivo extra cadastrado.", [
        "id_calendario" => (int) $stmt->fetchColumn(),
        "data_inicio" => $inicio, "data_fim" => $fim, "tipo" => $tipo, "descricao" => $descricao,
    ], 201);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao salvar o calendário.", 500, $erro);
}
