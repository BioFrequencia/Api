<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/biometriaFacial.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Cadastro da biometria facial de um aluno pelo app da coordenação.
 *
 *   { id_aluno, amostras: [[128 números], ...], modelo, confirmar_duplicado? }
 *
 * - Só com o consentimento do responsável registrado (consentimento.php).
 * - Recebe de 1 a 5 amostras (fotos com pequenas variações), nunca a foto.
 * - Antes de gravar, confere se o rosto já está cadastrado para OUTRO
 *   aluno da escola: é o erro mais grave possível (a presença de um aluno
 *   cair para outro), então a API recusa (409) e diz com quem parece;
 *   só grava assim com confirmar_duplicado (ex.: gêmeos).
 * - Grava cifrado (AES-256-GCM) e substitui o cadastro anterior do aluno.
 */
$idCoordenacao = autenticar();
$chave = exigirChaveBiometria();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

$idAluno = $dados["id_aluno"] ?? null;
// "embedding" (um vetor só) é o formato antigo do desktop.
$amostras = $dados["amostras"] ?? (isset($dados["embedding"]) ? [$dados["embedding"]] : null);
$modelo = $dados["modelo"] ?? null;
$confirmarDuplicado = ($dados["confirmar_duplicado"] ?? false) === true;

if (!inteiroValido($idAluno)) {
    enviarErro("Informe um aluno válido.", 422);
}
if (!amostrasValidas($amostras)) {
    enviarErro("Envie de 1 a " . MAXIMO_AMOSTRAS_FACIAIS . " amostras faciais válidas, todas do mesmo modelo.", 422);
}
if (!campoPreenchido($modelo) || mb_strlen($modelo) > 100) {
    enviarErro("Informe o identificador do modelo usado para gerar as amostras.", 422);
}

$idAluno = (int) $idAluno;
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
exigirAlunoDaEscola($conexao, $idAluno, $idEscola);
$amostras = array_map('normalizarVetor', $amostras);

try {
    $consentimento = $conexao->prepare("SELECT 1 FROM tb_consentimento_biometria WHERE id_aluno = ?");
    $consentimento->bindValue(1, $idAluno);
    $consentimento->execute();
    if (!$consentimento->fetch()) {
        enviarErro("Registre antes o consentimento do responsável para usar a biometria deste aluno.", 409, null, ["falta_consentimento" => true]);
    }

    if (!$confirmarDuplicado) {
        $outros = amostrasFaciaisDaEscola($conexao, $idEscola, $chave, $idAluno);
        foreach ($amostras as $amostra) {
            $parecido = identificarRosto($amostra, $outros, DISTANCIA_MAXIMA_FACIAL, 0.0);
            if ($parecido['id_aluno'] !== null) {
                $outro = $conexao->prepare("SELECT nome_aluno FROM tb_aluno WHERE id_aluno = ?");
                $outro->bindValue(1, $parecido['id_aluno']);
                $outro->execute();
                enviarErro(
                    "Este rosto parece ser de outro aluno já cadastrado: " . $outro->fetchColumn() . ". Confira se o aluno selecionado é o certo.",
                    409,
                    null,
                    ["duplicado" => true, "id_aluno_parecido" => $parecido['id_aluno']]
                );
            }
        }
    }

    $cifrado = cifrarDado(json_encode($amostras), $chave, contextoFacial($idAluno));

    $upsert = $conexao->prepare("
        INSERT INTO tb_biometria_facial (id_aluno, embedding, modelo, id_coordenacao, amostras, cifrado)
        VALUES (?, ?, ?, ?, ?, TRUE)
        ON CONFLICT (id_aluno) DO UPDATE SET
            embedding = EXCLUDED.embedding,
            modelo = EXCLUDED.modelo,
            id_coordenacao = EXCLUDED.id_coordenacao,
            amostras = EXCLUDED.amostras,
            cifrado = TRUE,
            atualizado_em = NOW()
    ");
    $upsert->bindValue(1, $idAluno);
    $upsert->bindValue(2, $cifrado);
    $upsert->bindValue(3, trim($modelo));
    $upsert->bindValue(4, $idCoordenacao);
    $upsert->bindValue(5, count($amostras));
    $upsert->execute();

    enviarSucesso("Biometria facial cadastrada.", ["amostras" => count($amostras)]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao cadastrar a biometria facial.", 500, $erro);
}
