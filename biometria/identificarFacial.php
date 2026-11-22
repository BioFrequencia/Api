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
 * Identificação facial (1:N), com registro da presença SÓ quando há
 * certeza.
 *
 *   { embeddings: [[128 números], ...] (1 a 3 fotos seguidas), modelo }
 *   ou, do app antigo, { embedding: [128 números], modelo }
 *
 * Compara as fotos com os cadastros dos alunos da escola de quem está
 * logado - nunca de outra escola - e decide (decidirIdentificacao em
 * helpers/biometriaFacial.php):
 *
 *   reconhecido: true          as fotos concordam e a semelhança é alta:
 *                              grava a presença do dia (se já havia, devolve
 *                              a existente) e, depois da tolerância, a
 *                              entrada atrasada. Vem com aluno + presenca + entrada.
 *   confirmacao: {...}         há um candidato, sem certeza: NADA é gravado.
 *                              O app pergunta "É Fulano?" e chama
 *                              biometria/confirmarIdentificacao.php.
 *                              (reconhecido: false - o app antigo mostra
 *                              "não reconhecido", o que é seguro.)
 *   reconhecido: false         ninguém parecido, ou fotos que apontam alunos
 *                              diferentes: nova tentativa ou registro manual.
 *
 * Toda tentativa fica em tb_identificacao_biometrica, sem dado biométrico.
 */
$idCoordenacao = autenticar();
$chave = exigirChaveBiometria();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$fotos = $dados["embeddings"] ?? (isset($dados["embedding"]) ? [$dados["embedding"]] : null);
$modelo = $dados["modelo"] ?? null;

if (!is_array($fotos) || !array_is_list($fotos) || count($fotos) < 1 || count($fotos) > MAXIMO_FOTOS_IDENTIFICACAO) {
    enviarErro("Amostra facial inválida ou ausente.", 422);
}
foreach ($fotos as $foto) {
    if (!embeddingValido($foto) || count($foto) !== count($fotos[0])) {
        enviarErro("Amostra facial inválida ou ausente.", 422);
    }
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

    $decisao = decidirIdentificacao(array_map('normalizarVetor', $fotos), $cadastros);
    $reconhecido = $decisao['situacao'] === 'reconhecido';
    $arredondar = fn(?float $d) => $d === null ? null : round(min($d, 9.99999), 5);

    $log = $conexao->prepare("
        INSERT INTO tb_identificacao_biometrica
            (id_coordenacao, metodo, reconhecido, id_aluno, distancia, situacao, id_candidato, segunda_distancia, amostras)
        VALUES (?, 'facial', ?, ?, ?, ?, ?, ?, ?)
        RETURNING id_identificacao
    ");
    $log->execute([
        $idCoordenacao, $reconhecido ? 'true' : 'false', $reconhecido ? $decisao['id_aluno'] : null,
        $arredondar($decisao['distancia']), $decisao['situacao'], $decisao['id_aluno'],
        $arredondar($decisao['segunda_distancia']), $decisao['fotos'],
    ]);
    $idIdentificacao = (int) $log->fetchColumn();

    if ($decisao['situacao'] === 'incerto') {
        $candidato = dadosDoAluno($conexao, (int) $decisao['id_aluno']);
        enviarSucesso("Confirme quem é o aluno antes de registrar a presença.", [
            "reconhecido" => false,
            "confirmacao" => [
                "id_identificacao"    => $idIdentificacao,
                "aluno"               => $candidato,
                "motivo"              => textoDoMotivoFacial($decisao['motivo']),
                "expira_em_segundos"  => SEGUNDOS_PARA_CONFIRMAR_IDENTIFICACAO,
            ],
        ]);
    }
    if (!$reconhecido) {
        enviarSucesso("Aluno não reconhecido.", ["reconhecido" => false, "motivo" => textoDoMotivoFacial($decisao['motivo'])]);
    }

    $idAluno = (int) $decisao['id_aluno'];
    $presenca = registrarPresenca($conexao, $idAluno, $idCoordenacao, 'facial', $decisao['distancia']);
    $conexao->prepare("UPDATE tb_identificacao_biometrica SET id_presenca = ?, resolvido_em = NOW() WHERE id_identificacao = ?")
        ->execute([$presenca['id_presenca'], $idIdentificacao]);
    // Presença nova depois da tolerância vira entrada atrasada (helpers/presenca.php).
    $entrada = $presenca['nova']
        ? conferirEntradaDaPresenca($conexao, $idAluno, (int) $idCoordenacao, $idEscola, $presenca['registrada_em'], 'facial')
        : null;

    enviarSucesso(
        $presenca['nova'] ? mensagemDaPresenca($entrada) : "A presença deste aluno já estava registrada hoje.",
        [
            "reconhecido" => true,
            "aluno" => dadosDoAluno($conexao, $idAluno),
            "presenca" => $presenca,
            "entrada" => $entrada,
            "distancia" => round((float) $decisao['distancia'], 4),
            // Quem está no tablet pode desfazer nos primeiros minutos, se o
            // nome na tela não for o do aluno (registro/remover.php).
            "pode_desfazer" => $presenca['nova'],
        ]
    );
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao identificar o aluno.", 500, $erro);
}
