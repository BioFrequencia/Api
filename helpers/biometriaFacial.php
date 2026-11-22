<?php
/**
 * Comparação de vetores faciais ("embeddings"). O app da coordenação roda
 * o modelo de reconhecimento no próprio tablet/celular (face-api.js,
 * ResNet-34, vetor de 128 posições) e manda só o vetor - a foto nunca sai
 * do aparelho. Dois vetores de fotos da mesma pessoa ficam próximos; de
 * pessoas diferentes, distantes.
 *
 * O cadastro guarda várias amostras por aluno (fotos com pequenas
 * variações), cifradas (helpers/criptografia.php). A identificação (1:N)
 * compara a foto da hora com as amostras de todos os alunos da escola de
 * quem está logado - nunca de outra escola.
 */

require_once __DIR__ . '/criptografia.php';

/** Identificador do modelo usado pelo app (gravado junto com o cadastro). */
const MODELO_FACIAL_APP = 'faceapi-resnet34-128';

/**
 * Distância euclidiana máxima para "é a mesma pessoa". A face-api.js usa
 * 0,6 como padrão; 0,5 é mais rigoroso - prefere recusar e pedir outra
 * foto a registrar a presença do aluno errado. Calibrar com fotos reais da
 * escola antes de usar em produção.
 */
const DISTANCIA_MAXIMA_FACIAL = 0.5;

/**
 * O aluno mais parecido precisa se destacar do segundo: se dois alunos
 * diferentes ficam quase à mesma distância, a foto não decide e a
 * identificação é recusada.
 */
const MARGEM_MINIMA_FACIAL = 0.06;

/** Máximo de amostras por aluno no cadastro. */
const MAXIMO_AMOSTRAS_FACIAIS = 5;

/**
 * Registro SEM confirmação (migração 012): só bem abaixo do limite de
 * "parece" (DISTANCIA_MAXIMA_FACIAL) e com folga sobre o segundo mais
 * parecido. Entre um limite e o outro o aluno é só um CANDIDATO: nada é
 * gravado e quem está no tablet confirma olhando para o aluno. Antes, uma
 * foto só dentro de 0,5 já gravava a presença - e um irmão, um primo ou
 * uma foto escura gravavam a presença (e a entrada atrasada) no nome
 * errado. Calibrar com fotos reais da escola antes de mexer nestes números.
 */
const DISTANCIA_AUTOMATICA_FACIAL = 0.42;
const MARGEM_AUTOMATICA_FACIAL = 0.10;
/** Com uma foto só (app de antes das fotos em sequência), exige ainda mais. */
const DISTANCIA_AUTOMATICA_UMA_FOTO = 0.38;
/** Fotos seguidas por identificação: todas precisam concordar. */
const MAXIMO_FOTOS_IDENTIFICACAO = 3;
/** Por quanto tempo uma identificação incerta pode ser confirmada no tablet. */
const SEGUNDOS_PARA_CONFIRMAR_IDENTIFICACAO = 120;

function distanciaEuclidiana(array $a, array $b): float {
    if (count($a) !== count($b) || count($a) === 0) {
        return INF;
    }
    $soma = 0.0;
    foreach ($a as $i => $valor) {
        $diferenca = $valor - $b[$i];
        $soma += $diferenca * $diferenca;
    }
    return sqrt($soma);
}

/** Usada pela verificação 1:1 de cadastros antigos (modelo do desktop). */
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
        && array_is_list($valor)
        && count($valor) >= 8
        && count($valor) <= 4096
        && array_reduce($valor, fn($ok, $v) => $ok && (is_int($v) || is_float($v)), true);
}

/** 1 a MAXIMO_AMOSTRAS_FACIAIS vetores válidos, todos com o mesmo tamanho. */
function amostrasValidas($valor): bool {
    if (!is_array($valor) || !array_is_list($valor) || count($valor) === 0 || count($valor) > MAXIMO_AMOSTRAS_FACIAIS) {
        return false;
    }
    $tamanho = is_array($valor[0]) ? count($valor[0]) : 0;
    foreach ($valor as $amostra) {
        if (!embeddingValido($amostra) || count($amostra) !== $tamanho) {
            return false;
        }
    }
    return true;
}

/** Vetor como lista de floats (o JSON pode trazer inteiros, ex.: 0). */
function normalizarVetor(array $vetor): array {
    return array_map('floatval', $vetor);
}

/** Contexto autenticado da cifra: o registro só abre para o mesmo aluno. */
function contextoFacial(int $idAluno): string {
    return "facial:aluno:$idAluno";
}

/**
 * Amostras guardadas de um aluno. Registros cifrados guardam uma lista de
 * vetores; os antigos (antes da migração 006, em texto puro) guardavam um
 * vetor só. null quando o registro não abre (chave trocada, adulterado).
 */
function lerAmostrasFaciais(array $registro, string $chave): ?array {
    if (!empty($registro['cifrado'])) {
        $json = decifrarDado($registro['embedding'], $chave, contextoFacial((int) $registro['id_aluno']));
        if ($json === null) {
            return null;
        }
        $amostras = json_decode($json, true);
        return amostrasValidas($amostras) ? $amostras : null;
    }
    $vetor = json_decode($registro['embedding'], true);
    return embeddingValido($vetor) ? [$vetor] : null;
}

/**
 * Identificação 1:N. $cadastros = [id_aluno => [amostra, amostra, ...]].
 * Para cada aluno vale a menor distância entre a foto e as amostras dele.
 * Só reconhece quando o mais parecido passa do limite E se destaca do
 * segundo (MARGEM_MINIMA_FACIAL).
 *
 * @return array{id_aluno: ?int, distancia: ?float, segunda_distancia: ?float}
 */
function identificarRosto(
    array $amostra,
    array $cadastros,
    float $distanciaMaxima = DISTANCIA_MAXIMA_FACIAL,
    float $margemMinima = MARGEM_MINIMA_FACIAL
): array {
    $distancias = [];
    foreach ($cadastros as $idAluno => $amostrasDoAluno) {
        $menor = INF;
        foreach ($amostrasDoAluno as $cadastrada) {
            $menor = min($menor, distanciaEuclidiana($amostra, $cadastrada));
        }
        if (is_finite($menor)) {
            $distancias[(int) $idAluno] = $menor;
        }
    }
    asort($distancias);

    $ids = array_keys($distancias);
    $melhor = $ids[0] ?? null;
    $distancia = $melhor === null ? null : $distancias[$melhor];
    $segunda = isset($ids[1]) ? $distancias[$ids[1]] : null;

    $reconhecido = $melhor !== null
        && $distancia <= $distanciaMaxima
        && ($segunda === null || $segunda - $distancia >= $margemMinima);

    return [
        "id_aluno" => $reconhecido ? $melhor : null,
        "distancia" => $distancia,
        "segunda_distancia" => $segunda,
    ];
}

/**
 * Menor distância de uma foto até cada aluno, do mais parecido ao menos.
 *
 * @return array<int, float> id_aluno => distância
 */
function distanciasPorAluno(array $foto, array $cadastros): array {
    $distancias = [];
    foreach ($cadastros as $idAluno => $amostrasDoAluno) {
        $menor = INF;
        foreach ($amostrasDoAluno as $cadastrada) {
            $menor = min($menor, distanciaEuclidiana($foto, $cadastrada));
        }
        if (is_finite($menor)) {
            $distancias[(int) $idAluno] = $menor;
        }
    }
    asort($distancias);
    return $distancias;
}

/**
 * Decide a identificação a partir de 1 a MAXIMO_FOTOS_IDENTIFICACAO fotos
 * seguidas, tiradas pelo app no mesmo instante:
 *
 *  - 'reconhecido': todas as fotos apontam o MESMO aluno, a mais distante
 *    delas fica dentro de DISTANCIA_AUTOMATICA_FACIAL (uma foto só:
 *    DISTANCIA_AUTOMATICA_UMA_FOTO) e com folga de MARGEM_AUTOMATICA_FACIAL
 *    sobre o segundo mais parecido - a presença é gravada sozinha;
 *  - 'incerto': há um candidato (as fotos que se parecem com alguém apontam
 *    o mesmo aluno, dentro de DISTANCIA_MAXIMA_FACIAL e MARGEM_MINIMA_FACIAL),
 *    mas sem a certeza acima - nada é gravado; o app pergunta "É Fulano?";
 *  - 'nao_reconhecido': ninguém parecido o bastante, ou fotos que apontam
 *    alunos diferentes - nova tentativa ou registro manual.
 *
 * Função pura.
 *
 * @param list<array> $fotos vetores já normalizados
 * @return array{situacao: string, id_aluno: ?int, distancia: ?float, segunda_distancia: ?float, fotos: int, motivo: ?string}
 */
function decidirIdentificacao(array $fotos, array $cadastros): array {
    $resultado = fn(string $situacao, ?int $id, ?float $distancia, ?float $segunda, ?string $motivo) => [
        'situacao' => $situacao, 'id_aluno' => $id, 'distancia' => $distancia,
        'segunda_distancia' => $segunda, 'fotos' => count($fotos), 'motivo' => $motivo,
    ];
    if ($fotos === [] || $cadastros === []) {
        return $resultado('nao_reconhecido', null, null, null, 'sem_cadastros');
    }

    $porFoto = [];
    foreach ($fotos as $foto) {
        $distancias = distanciasPorAluno($foto, $cadastros);
        $ids = array_keys($distancias);
        $porFoto[] = [
            'id'      => $ids[0] ?? null,
            'melhor'  => isset($ids[0]) ? $distancias[$ids[0]] : INF,
            'segunda' => isset($ids[1]) ? $distancias[$ids[1]] : null,
        ];
    }

    // Cada foto que se parece com alguém precisa apontar o mesmo aluno.
    $parecidas = array_values(array_filter($porFoto, fn($f) => $f['id'] !== null && $f['melhor'] <= DISTANCIA_MAXIMA_FACIAL));
    $idsApontados = array_values(array_unique(array_map(fn($f) => $f['id'], $parecidas)));
    $pior = max(array_map(fn($f) => $f['melhor'], $porFoto));
    $segundas = array_filter(array_map(fn($f) => $f['segunda'], $porFoto), fn($s) => $s !== null);
    $menorSegunda = $segundas === [] ? null : min($segundas);
    if (count($idsApontados) > 1) {
        return $resultado('nao_reconhecido', null, is_finite($pior) ? $pior : null, $menorSegunda, 'fotos_divergentes');
    }
    $candidato = $idsApontados[0] ?? null;
    $validas = array_filter($parecidas, fn($f) => $f['segunda'] === null || $f['segunda'] - $f['melhor'] >= MARGEM_MINIMA_FACIAL);
    if ($candidato === null || $validas === []) {
        $melhorDeTodas = min(array_map(fn($f) => $f['melhor'], $porFoto));
        $motivo = $candidato === null ? 'sem_semelhanca' : 'parecido_com_outro';
        return $resultado('nao_reconhecido', null, is_finite($melhorDeTodas) ? $melhorDeTodas : null, $menorSegunda, $motivo);
    }

    $limite = count($fotos) >= 2 ? DISTANCIA_AUTOMATICA_FACIAL : DISTANCIA_AUTOMATICA_UMA_FOTO;
    $todasDoCandidato = count($parecidas) === count($porFoto);
    $menorFolga = min(array_map(fn($f) => $f['segunda'] === null ? INF : $f['segunda'] - $f['melhor'], $porFoto));
    $distancia = is_finite($pior) ? $pior : null;
    if ($todasDoCandidato && $pior <= $limite && $menorFolga >= MARGEM_AUTOMATICA_FACIAL) {
        return $resultado('reconhecido', $candidato, $distancia, $menorSegunda, null);
    }
    $motivo = !$todasDoCandidato ? 'fotos_insuficientes' : ($menorFolga < MARGEM_AUTOMATICA_FACIAL ? 'parecido_com_outro' : 'pouca_semelhanca');
    return $resultado('incerto', $candidato, $distancia, $menorSegunda, $motivo);
}

/** O que dizer na tela sobre o motivo da dúvida (ou da recusa). Função pura. */
function textoDoMotivoFacial(?string $motivo): string {
    return match ($motivo) {
        'parecido_com_outro'  => 'O rosto ficou parecido com o de outro aluno.',
        'pouca_semelhanca'    => 'A semelhança com o cadastro não foi alta o bastante.',
        'fotos_insuficientes' => 'Nem todas as fotos mostraram o rosto com nitidez.',
        'fotos_divergentes'   => 'As fotos apontaram alunos diferentes.',
        'sem_semelhanca'      => 'Ninguém da escola ficou parecido o bastante.',
        default               => 'Não foi possível ter certeza de quem é.',
    };
}

/**
 * Amostras faciais de todos os alunos de uma escola (ou só de um aluno),
 * decifradas: [id_aluno => [amostras]]. Registros que não abrem ficam de
 * fora e vão para o log - o aluno precisa ser recadastrado.
 */
function amostrasFaciaisDaEscola(PDO $conexao, int $idEscola, string $chave, ?int $exceto = null): array {
    $stmt = $conexao->prepare("
        SELECT bf.id_aluno, bf.embedding, bf.cifrado
        FROM tb_biometria_facial bf
        JOIN tb_aluno a ON a.id_aluno = bf.id_aluno
        JOIN tb_sala  s ON s.id_sala  = a.id_sala
        WHERE s.id_escola = ?
    ");
    $stmt->bindValue(1, $idEscola);
    $stmt->execute();

    $cadastros = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $registro) {
        $idAluno = (int) $registro['id_aluno'];
        if ($idAluno === $exceto) {
            continue;
        }
        $amostras = lerAmostrasFaciais($registro, $chave);
        if ($amostras === null) {
            error_log("[BioFrequenciaApi][biometria] cadastro facial do aluno $idAluno não abre (chave trocada?)");
            continue;
        }
        $cadastros[$idAluno] = array_map('normalizarVetor', $amostras);
    }
    return $cadastros;
}
