<?php
/**
 * Comparação de embeddings faciais. O "embedding" é o vetor numérico que
 * um modelo de reconhecimento facial (ex.: FaceNet/ArcFace via ONNX
 * Runtime, rodando no desktop) produz a partir de um rosto detectado na
 * imagem - dois embeddings de fotos da mesma pessoa ficam próximos no
 * espaço vetorial; de pessoas diferentes, distantes. A API nunca recebe
 * a foto em si, só o vetor já calculado - reduz volume de dados e evita
 * a API precisar rodar o modelo de ML.
 */

function similaridadeCosseno(array $a, array $b): float {
    if (count($a) !== count($b) || count($a) === 0) {
        return 0.0;
    }
    $produtoEscalar = 0.0;
    $normaA = 0.0;
    $normaB = 0.0;
    foreach ($a as $i => $valor) {
        $produtoEscalar += $valor * $b[$i];
        $normaA += $valor * $valor;
        $normaB += $b[$i] * $b[$i];
    }
    if ($normaA <= 0.0 || $normaB <= 0.0) {
        return 0.0;
    }
    return $produtoEscalar / (sqrt($normaA) * sqrt($normaB));
}

function embeddingValido($valor): bool {
    return is_array($valor)
        && count($valor) >= 8
        && count($valor) <= 4096
        && array_reduce($valor, fn($ok, $v) => $ok && is_numeric($v), true);
}

/**
 * Limiar de decisão "é a mesma pessoa". 0.55 é um valor de referência
 * comum para embeddings normalizados de modelos tipo FaceNet/ArcFace,
 * MAS precisa ser calibrado com o modelo real escolhido e dados de
 * validação reais - não foi possível calibrar isso aqui (sem webcam,
 * sem modelo, sem fotos de teste). Deixado como constante isolada de
 * propósito, para ser o primeiro ajuste ao colocar em produção.
 */
define('LIMIAR_SIMILARIDADE_FACIAL', 0.55);
