<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/criptografia.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Templates de digital dos alunos da escola de quem está logado, para o
 * SDK do leitor fazer a identificação (1:N) no aparelho - ver o
 * comentário em cadastrarBiometriaDigital.php. Só por HTTPS e só para a
 * coordenação da própria escola; o app deve mantê-los apenas na memória
 * enquanto a tela de identificação estiver aberta.
 */
$idCoordenacao = autenticar();
$chave = exigirChaveBiometria();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $stmt = $conexao->prepare("
        SELECT bd.id_aluno, bd.dedo, bd.template, bd.formato
        FROM tb_biometria_digital bd
        JOIN tb_aluno a ON a.id_aluno = bd.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE s.id_escola = ?
    ");
    $stmt->bindValue(1, $idEscola);
    $stmt->execute();

    $templates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $idAluno = (int) $linha['id_aluno'];
        $template = decifrarDado($linha['template'], $chave, "digital:aluno:$idAluno");
        if ($template === null) {
            error_log("[BioFrequenciaApi][biometria] digital do aluno $idAluno não abre (chave trocada?)");
            continue;
        }
        $templates[] = ["id_aluno" => $idAluno, "dedo" => $linha['dedo'], "formato" => $linha['formato'], "template" => $template];
    }

    enviarSucesso("Templates carregados.", $templates);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar as digitais.", 500, $erro);
}
