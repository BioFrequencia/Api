<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/criptografia.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Cadastro de uma digital do aluno, lida por um leitor externo ligado ao
 * tablet. O leitor (e o SDK dele) gera o "template" - um resumo matemático
 * da digital, não a imagem - e o app manda esse template em base64.
 *
 *   { id_aluno, dedo, template (base64), formato }
 *
 * Mesmas regras da facial: só com consentimento e gravado cifrado.
 *
 * Por que a digital é diferente da facial: a comparação de digitais é
 * feita pelo SDK do leitor, no aparelho (cada fabricante tem o seu
 * algoritmo). Por isso a identificação usa templatesDigitais.php + o SDK,
 * e a presença é gravada por presenca/registrar.php com metodo "digital".
 * O sensor do próprio celular NÃO serve: o Android só confirma o dono do
 * aparelho e não deixa o app ler nem guardar a digital de outras pessoas.
 */
$idCoordenacao = autenticar();
$chave = exigirChaveBiometria();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$idAluno = $dados["id_aluno"] ?? null;
$dedo = $dados["dedo"] ?? null;
$template = $dados["template"] ?? null;
$formato = $dados["formato"] ?? null;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!campoPreenchido($dedo) || mb_strlen($dedo) > 30) {
    enviarErro("Informe qual dedo foi lido.", 422);
}
if (!is_string($template) || base64_decode($template, true) === false || strlen($template) < 16 || strlen($template) > 20000) {
    enviarErro("Template da digital inválido (esperado base64).", 422);
}
if (!campoPreenchido($formato) || mb_strlen($formato) > 60) {
    enviarErro("Informe o formato do template (ex.: ISO 19794-2).", 422);
}

$idAluno = (int) $idAluno;
exigirAlunoDaEscola($conexao, $idAluno, escolaDaCoordenacao($conexao, $idCoordenacao));

try {
    $consentimento = $conexao->prepare("SELECT 1 FROM tb_consentimento_biometria WHERE id_aluno = ?");
    $consentimento->bindValue(1, $idAluno);
    $consentimento->execute();
    if (!$consentimento->fetch()) {
        enviarErro("Registre antes o consentimento do responsável para usar a biometria deste aluno.", 409, null, ["falta_consentimento" => true]);
    }

    $grava = $conexao->prepare("
        INSERT INTO tb_biometria_digital (id_aluno, dedo, template, formato, id_coordenacao)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (id_aluno, dedo) DO UPDATE SET
            template = EXCLUDED.template, formato = EXCLUDED.formato,
            id_coordenacao = EXCLUDED.id_coordenacao, data_cadastro = NOW()
    ");
    $grava->bindValue(1, $idAluno);
    $grava->bindValue(2, trim($dedo));
    $grava->bindValue(3, cifrarDado($template, $chave, "digital:aluno:$idAluno"));
    $grava->bindValue(4, trim($formato));
    $grava->bindValue(5, $idCoordenacao);
    $grava->execute();

    enviarSucesso("Digital cadastrada.");
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao cadastrar a digital.", 500, $erro);
}
