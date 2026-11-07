<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/planilha.php';
require_once __DIR__ . '/../helpers/importacaoOcorrenciasEscola.php';
require_once __DIR__ . '/../helpers/avisos.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Confirma a importação de entradas atrasadas e saídas antecipadas:
 * recebe de novo a planilha (multipart/form-data: "arquivo" e o "hash"
 * que a prévia devolveu) e registra as linhas novas numa transação só.
 *
 * - O hash garante que o arquivo é o mesmo que a coordenação revisou.
 * - A escola fica travada durante a gravação e a conferência é refeita
 *   com o banco de agora: o que alguém lançou depois da prévia fica de
 *   fora, e importar o mesmo arquivo duas vezes não duplica nada.
 * - Cada registro passa pela mesma função do lançamento manual (uma
 *   entrada atrasada e uma saída antecipada por aluno por dia).
 * - Avisos aos responsáveis: nenhum por registro importado (chegaria
 *   horas ou dias depois, vários de uma vez); o aviso de limite continua,
 *   no máximo um por período, como no lançamento manual.
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

try {
    $arquivo = arquivoDaImportacao($_FILES['arquivo'] ?? null);
} catch (PlanilhaInvalida $erro) {
    enviarErro($erro->getMessage(), 422);
}
$hashDaPrevia = $_POST['hash'] ?? null;
if (!is_string($hashDaPrevia) || !hash_equals($arquivo['hash'], $hashDaPrevia)) {
    enviarErro("O arquivo não é o mesmo da prévia. Gere a prévia de novo antes de confirmar.", 409);
}
try {
    $lido = lerOcorrenciasDaImportacao($arquivo['caminho'], $arquivo['extensao']);
} catch (PlanilhaInvalida $erro) {
    enviarErro($erro->getMessage(), 422);
}

try {
    $conexao->beginTransaction();
    // Mesma trava da importação de turmas e alunos: uma importação por escola de cada vez.
    $conexao->prepare("SELECT pg_advisory_xact_lock(4344143, ?)")->execute([$idEscola]);
    $escola = dadosDaEscolaParaOcorrencias($conexao, $idEscola, $lido['linhas']);
    $previa = montarPreviaDeOcorrencias($lido['linhas'], $escola, agoraNaEscolaParaPrevia());
    if ($previa['resumo']['novas'] === 0) {
        $conexao->rollBack();
        enviarErro("Nada novo para registrar: tudo o que é válido nesta planilha já está no sistema.", 422, null, ['resumo' => $previa['resumo']]);
    }
    $resultado = gravarOcorrenciasImportadas($conexao, (int) $idCoordenacao, $previa);
    $conexao->commit();
} catch (PDOException $erro) {
    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }
    enviarErro("Erro no servidor ao importar a planilha. Nada foi registrado.", 500, $erro);
}

// Aviso de limite (fora da transação; falha aqui não desfaz a importação).
$avisosDeLimite = 0;
try {
    $configuracao = configuracaoDaEscola($conexao, $idEscola);
    foreach ($resultado['alunos'] as $idAluno) {
        foreach (avisarSeAtingiuLimite($conexao, $idEscola, $idAluno, $configuracao) as $resumoDoTipo) {
            if (($resumoDoTipo['enviados'] ?? 0) + ($resumoDoTipo['na_fila'] ?? 0) > 0) {
                $avisosDeLimite++;
                break;
            }
        }
    }
} catch (PDOException $erro) {
    error_log('[BioFrequenciaApi] Falha no aviso de limite depois da importação: ' . $erro->getMessage());
}

enviarSucesso(
    mensagemDaImportacaoDeOcorrencias($resultado['atrasos_criados'], $resultado['saidas_criadas']),
    [
        'atrasos_criados' => $resultado['atrasos_criados'],
        'saidas_criadas' => $resultado['saidas_criadas'],
        'avisos_de_limite' => $avisosDeLimite,
        'resumo' => $previa['resumo'],
    ],
    201
);
