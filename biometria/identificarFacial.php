<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/biometriaFacial.php';
require_once __DIR__ . '/../helpers/presenca.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Identificação facial (1:N) com registro automático da presença.
 *
 *   { embedding: [128 números], modelo }
 *
 * Compara o vetor da foto tirada agora com os cadastros dos alunos da
 * escola de quem está logado - nunca de outra escola. Reconhecido: grava
 * a presença do dia (se já havia, devolve a existente, sem duplicar).
 * Toda tentativa fica em tb_identificacao_biometrica, sem dado biométrico.
 *
 * Resposta 200 nos dois casos; "reconhecido" diz qual foi:
 *   reconhecido: true  -> aluno + presenca { nova, registrada_em, metodo }
 *   reconhecido: false -> o app pede outra foto ou a identificação manual
 */
$idCoordenacao = autenticar();
$chave = exigirChaveBiometria();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$amostra = $dados["embedding"] ?? null;
$modelo = $dados["modelo"] ?? null;

if (!embeddingValido($amostra)) {
    enviarErro("Amostra facial inválida ou ausente.", 422);
}
if (!campoPreenchido($modelo)) {
    enviarErro("Informe o identificador do modelo usado para gerar a amostra.", 422);
}

$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $cadastros = amostrasFaciaisDaEscola($conexao, $idEscola, $chave);
    if (empty($cadastros)) {
        enviarErro("Nenhum aluno desta escola tem biometria facial cadastrada ainda.", 409, null, ["sem_cadastros" => true]);
    }

    $resultado = identificarRosto(normalizarVetor($amostra), $cadastros);
    $idAluno = $resultado['id_aluno'];

    $log = $conexao->prepare("
        INSERT INTO tb_identificacao_biometrica (id_coordenacao, metodo, reconhecido, id_aluno, distancia)
        VALUES (?, 'facial', ?, ?, ?)
    ");
    $log->bindValue(1, $idCoordenacao);
    $log->bindValue(2, $idAluno !== null ? 'true' : 'false');
    $log->bindValue(3, $idAluno);
    $log->bindValue(4, $resultado['distancia'] === null ? null : round(min($resultado['distancia'], 9.99999), 5));
    $log->execute();

    if ($idAluno === null) {
        enviarSucesso("Aluno não reconhecido.", ["reconhecido" => false]);
    }

    $presenca = registrarPresenca($conexao, $idAluno, $idCoordenacao, 'facial', $resultado['distancia']);

    enviarSucesso(
        $presenca['nova'] ? "Presença registrada." : "A presença deste aluno já estava registrada hoje.",
        [
            "reconhecido" => true,
            "aluno" => dadosDoAluno($conexao, $idAluno),
            "presenca" => $presenca,
            "distancia" => round($resultado['distancia'], 4),
        ]
    );
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao identificar o aluno.", 500, $erro);
}
