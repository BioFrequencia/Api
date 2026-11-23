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
 * Resposta de quem está no tablet a uma identificação incerta
 * (biometria/identificarFacial.php devolveu "confirmacao"):
 *
 *   { id_identificacao, confirmar: true }   "Sim, é este aluno": grava a
 *                                           presença (e a entrada atrasada,
 *                                           se for o caso) do CANDIDATO
 *                                           guardado na tentativa
 *   { id_identificacao, confirmar: false }  "Não é": nada é gravado
 *
 * Só vale para a tentativa da própria conta, ainda não resolvida e de até
 * SEGUNDOS_PARA_CONFIRMAR_IDENTIFICACAO atrás: o aluno é o que a API
 * guardou, não um id mandado pelo app - não dá para confirmar outra pessoa
 * por aqui (para isso existe o registro manual).
 */
$idCoordenacao = autenticar();
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
if (!inteiroValido($dados['id_identificacao'] ?? null) || !is_bool($dados['confirmar'] ?? null)) {
    enviarErro("Informe a identificação e se confirma (confirmar: true ou false).", 422);
}
$idIdentificacao = (int) $dados['id_identificacao'];
$confirmar = $dados['confirmar'];
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $conexao->beginTransaction();
    $tentativa = $conexao->prepare("
        SELECT i.id_identificacao, i.id_candidato, i.distancia
        FROM tb_identificacao_biometrica i
        JOIN tb_aluno a ON a.id_aluno = i.id_candidato
        JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE i.id_identificacao = ? AND i.id_coordenacao = ? AND i.situacao = 'incerto' AND i.resolvido_em IS NULL
          AND i.criado_em >= NOW() - make_interval(secs => ?::int) AND s.id_escola = ?
        FOR UPDATE OF i
    ");
    $tentativa->execute([$idIdentificacao, $idCoordenacao, SEGUNDOS_PARA_CONFIRMAR_IDENTIFICACAO, $idEscola]);
    $linha = $tentativa->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        $conexao->rollBack();
        enviarErro("Esta identificação não está mais esperando confirmação (já foi respondida ou passou o tempo). Tente de novo.", 409);
    }

    if (!$confirmar) {
        $conexao->prepare("UPDATE tb_identificacao_biometrica SET situacao = 'recusado', resolvido_em = NOW() WHERE id_identificacao = ?")
            ->execute([$idIdentificacao]);
        $conexao->commit();
        enviarSucesso("Nada foi registrado. Tente de novo com o aluno de frente para a câmera, ou registre a presença manualmente.", [
            "registrado" => false,
        ]);
    }

    $idAluno = (int) $linha['id_candidato'];
    $distancia = $linha['distancia'] === null ? null : (float) $linha['distancia'];
    $presenca = registrarPresenca($conexao, $idAluno, (int) $idCoordenacao, 'facial', $distancia);
    $conexao->prepare("
        UPDATE tb_identificacao_biometrica SET situacao = 'confirmado', id_presenca = ?, resolvido_em = NOW() WHERE id_identificacao = ?
    ")->execute([$presenca['id_presenca'], $idIdentificacao]);
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao confirmar a identificação.", 500, $erro);
}

// Fora da transação: a entrada atrasada tem a própria (helpers/presenca.php).
$entrada = $presenca['nova']
    ? conferirEntradaDaPresenca($conexao, $idAluno, (int) $idCoordenacao, $idEscola, $presenca['registrada_em'], 'facial')
    : null;
enviarSucesso(
    $presenca['nova'] ? mensagemDaPresenca($entrada) : "A presença deste aluno já estava registrada hoje.",
    [
        "registrado" => true,
        "reconhecido" => true,
        "aluno" => dadosDoAluno($conexao, $idAluno),
        "presenca" => $presenca,
        "entrada" => $entrada,
        "pode_desfazer" => $presenca['nova'],
    ]
);
