<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/validacaoResponsavel.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Edita os dados de contato de um responsável da escola - antes não havia
 * como corrigir um telefone digitado errado (era preciso remover e
 * cadastrar de novo).
 *
 *   { id_responsavel, nome_responsavel?, telefone_responsavel?, email_responsavel?,
 *     genero_responsavel?, canal_preferido?, parentesco?, id_aluno? }
 *
 * parentesco muda o vínculo com o aluno id_aluno. O CPF não muda: é o que
 * identifica a pessoa entre irmãos e escolas.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idResponsavel = $dados['id_responsavel'] ?? null;
if (!inteiroValido($idResponsavel)) {
    enviarErro("Informe um responsável válido.", 422);
}
$idResponsavel = (int) $idResponsavel;

try {
    if (!responsavelVisivelNaEscola($conexao, $idResponsavel, $idEscola)) {
        enviarErro("Responsável não encontrado.", 404);
    }
    $stmt = $conexao->prepare("SELECT nome_responsavel, telefone_responsavel, email_responsavel, genero_responsavel, canal_preferido FROM tb_responsavel WHERE id_responsavel = ?");
    $stmt->execute([$idResponsavel]);
    $atual = $stmt->fetch(PDO::FETCH_ASSOC);

    $nome = array_key_exists('nome_responsavel', $dados) ? $dados['nome_responsavel'] : $atual['nome_responsavel'];
    $telefone = array_key_exists('telefone_responsavel', $dados) ? $dados['telefone_responsavel'] : $atual['telefone_responsavel'];
    $email = array_key_exists('email_responsavel', $dados) ? $dados['email_responsavel'] : $atual['email_responsavel'];
    $genero = array_key_exists('genero_responsavel', $dados) ? normalizarGeneroResponsavel($dados['genero_responsavel']) : $atual['genero_responsavel'];
    $canal = array_key_exists('canal_preferido', $dados) ? $dados['canal_preferido'] : $atual['canal_preferido'];

    if (!campoPreenchido($nome) || mb_strlen(trim($nome)) > 150) {
        enviarErro("Informe o nome do responsável (até 150 caracteres).", 422);
    }
    if (!telefoneValido($telefone)) {
        enviarErro("Informe um telefone válido (10 ou 11 dígitos, com DDD).", 422);
    }
    if (campoPreenchido($email) && (!emailValido($email) || mb_strlen(trim($email)) > 150)) {
        enviarErro("Informe um email válido (ou deixe em branco).", 422);
    }
    if ($genero === null) {
        enviarErro("Informe um gênero válido.", 422);
    }
    if (!in_array($canal, CANAIS_RESPONSAVEL, true)) {
        enviarErro("Canal inválido: use whatsapp, email ou ambos.", 422);
    }
    $email = campoPreenchido($email) ? mb_strtolower(trim($email)) : null;
    if ($canal !== 'whatsapp' && $email === null) {
        enviarErro("Para receber os avisos por email, informe o email do responsável.", 422);
    }

    $parentesco = null;
    $mudarParentesco = array_key_exists('parentesco', $dados) && inteiroValido($dados['id_aluno'] ?? null);
    if ($mudarParentesco) {
        [$parentesco, $erroParentesco] = lerParentesco($dados['parentesco']);
        if ($erroParentesco !== null) {
            enviarErro($erroParentesco, 422);
        }
        exigirAlunoDaEscola($conexao, (int) $dados['id_aluno'], $idEscola);
    }

    $conexao->beginTransaction();
    $grava = $conexao->prepare("
        UPDATE tb_responsavel
        SET nome_responsavel = ?, telefone_responsavel = ?, email_responsavel = ?, genero_responsavel = ?, canal_preferido = ?
        WHERE id_responsavel = ?
    ");
    $grava->execute([trim($nome), somenteDigitos($telefone), $email, $genero, $canal, $idResponsavel]);
    if ($mudarParentesco) {
        $vinculo = $conexao->prepare("UPDATE tb_aluno_responsavel SET parentesco = ? WHERE id_aluno = ? AND id_responsavel = ?");
        $vinculo->execute([$parentesco, (int) $dados['id_aluno'], $idResponsavel]);
    }
    $conexao->commit();

    enviarSucesso("Responsável atualizado.", ["id_responsavel" => $idResponsavel]);
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    if (($erro->errorInfo[0] ?? $erro->getCode()) === '23505') {
        enviarErro(mensagemDuplicidadeResponsavel($erro->getMessage()), 409, $erro);
    }
    enviarErro("Erro no servidor ao atualizar o responsável.", 500, $erro);
}
