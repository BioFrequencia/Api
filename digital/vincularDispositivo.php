<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/dispositivo.php';

/**
 * Chamado pelo app React Native do próprio aluno - de propósito SEM
 * autenticar() aqui, porque o celular do aluno não tem (nem deve ter) um
 * token de coordenador. A segurança deste endpoint depende inteiramente
 * do código de uso único/curta validade gerado em gerarCodigoVinculo.php.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$codigo = $dados["codigo"] ?? null;
$deviceUuid = $dados["device_uuid"] ?? null;

if (!campoPreenchido($codigo)) {
    enviarErro("Informe o código de vínculo.", 422);
}
if (!campoPreenchido($deviceUuid)) {
    enviarErro("Identificador do dispositivo ausente.", 422);
}

$codigo = strtoupper(trim($codigo));

try {
    $stmt = $conexao->prepare("
        SELECT id_codigo, id_aluno FROM tb_codigo_vinculo
        WHERE codigo = ? AND usado = FALSE AND expira_em >= NOW()
    ");
    $stmt->bindValue(1, $codigo);
    $stmt->execute();
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        enviarErro("Código inválido, já usado ou expirado. Peça um novo código à coordenação.", 401);
    }

    $idAluno = $registro['id_aluno'];

    $conexao->beginTransaction();

    $marcaUsado = $conexao->prepare("UPDATE tb_codigo_vinculo SET usado = TRUE WHERE id_codigo = ?");
    $marcaUsado->bindValue(1, $registro['id_codigo']);
    $marcaUsado->execute();

    // Um dispositivo pode ser reaproveitado (mesmo device_uuid vinculado
    // de novo, ex.: reinstalou o app) - desativa o vínculo anterior desse
    // UUID e cria um novo, em vez de acumular linhas mortas.
    $desativaAnterior = $conexao->prepare("UPDATE tb_dispositivo_aluno SET ativo = FALSE, revogado_em = NOW() WHERE device_uuid = ? AND ativo = TRUE");
    $desativaAnterior->bindValue(1, $deviceUuid);
    $desativaAnterior->execute();

    $segredo = gerarSegredoDispositivo();
    $insert = $conexao->prepare("
        INSERT INTO tb_dispositivo_aluno (id_aluno, device_uuid, device_secret)
        VALUES (?, ?, ?)
        RETURNING id_dispositivo
    ");
    $insert->bindValue(1, $idAluno);
    $insert->bindValue(2, $deviceUuid);
    $insert->bindValue(3, $segredo);
    $insert->execute();
    $novoDispositivo = $insert->fetch(PDO::FETCH_ASSOC);

    $conexao->commit();

    $stmtAluno = $conexao->prepare("SELECT nome_aluno FROM tb_aluno WHERE id_aluno = ?");
    $stmtAluno->bindValue(1, $idAluno);
    $stmtAluno->execute();
    $nomeAluno = $stmtAluno->fetchColumn();

    // device_secret só é devolvido AQUI, uma única vez - o app precisa
    // guardá-lo (ex.: SecureStore/Keychain) porque a API não tem como
    // reenviá-lo depois.
    enviarSucesso("Dispositivo vinculado com sucesso.", [
        "id_dispositivo" => $novoDispositivo["id_dispositivo"],
        "id_aluno"        => $idAluno,
        "nome_aluno"      => $nomeAluno,
        "device_secret"   => $segredo,
    ]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao vincular o dispositivo.", 500, $erro);
}
