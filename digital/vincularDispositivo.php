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
if (!campoPreenchido($deviceUuid) || mb_strlen($deviceUuid) > 100) {
    enviarErro("Identificador do dispositivo ausente ou inválido.", 422);
}

$codigo = strtoupper(trim($codigo));

try {
    $conexao->beginTransaction();

    // "Gasta" o código e lê o aluno numa instrução só. Antes era um
    // SELECT seguido de um UPDATE: dois aparelhos com o mesmo código ao
    // mesmo tempo passavam os dois no SELECT e os dois eram vinculados.
    $consome = $conexao->prepare("
        UPDATE tb_codigo_vinculo SET usado = TRUE
        WHERE codigo = ? AND usado = FALSE AND expira_em >= NOW()
        RETURNING id_codigo, id_aluno
    ");
    $consome->bindValue(1, $codigo);
    $consome->execute();
    $registro = $consome->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        $conexao->rollBack();
        enviarErro("Código inválido, já usado ou expirado. Peça um novo código à coordenação.", 401);
    }

    $idAluno = (int) $registro['id_aluno'];

    // Um dispositivo pode ser reaproveitado (mesmo device_uuid vinculado
    // de novo, ex.: reinstalou o app) - desativa o vínculo anterior desse
    // UUID e cria um novo, em vez de acumular linhas mortas.
    $desativaAnterior = $conexao->prepare("UPDATE tb_dispositivo_aluno SET ativo = FALSE, revogado_em = NOW() WHERE device_uuid = ? AND ativo = TRUE");
    $desativaAnterior->bindValue(1, $deviceUuid);
    $desativaAnterior->execute();

    // device_uuid é UNIQUE na tabela (inclusive entre linhas inativas):
    // um aparelho já vinculado antes reaproveita a própria linha.
    $segredo = gerarSegredoDispositivo();
    $grava = $conexao->prepare("
        INSERT INTO tb_dispositivo_aluno (id_aluno, device_uuid, device_secret)
        VALUES (?, ?, ?)
        ON CONFLICT (device_uuid) DO UPDATE
        SET id_aluno = EXCLUDED.id_aluno, device_secret = EXCLUDED.device_secret,
            ativo = TRUE, criado_em = NOW(), revogado_em = NULL
        RETURNING id_dispositivo
    ");
    $grava->bindValue(1, $idAluno);
    $grava->bindValue(2, $deviceUuid);
    $grava->bindValue(3, $segredo);
    $grava->execute();
    $idDispositivo = (int) $grava->fetchColumn();

    $stmtAluno = $conexao->prepare("SELECT nome_aluno FROM tb_aluno WHERE id_aluno = ?");
    $stmtAluno->bindValue(1, $idAluno);
    $stmtAluno->execute();
    $nomeAluno = $stmtAluno->fetchColumn();

    $conexao->commit();

    // device_secret só é devolvido AQUI, uma única vez - o app precisa
    // guardá-lo (SecureStore/Keychain) porque a API não tem como
    // reenviá-lo depois.
    enviarSucesso("Dispositivo vinculado com sucesso.", [
        "id_dispositivo" => $idDispositivo,
        "id_aluno"       => $idAluno,
        "nome_aluno"     => $nomeAluno,
        "device_secret"  => $segredo,
    ]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao vincular o dispositivo.", 500, $erro);
}
