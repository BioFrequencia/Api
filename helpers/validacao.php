<?php
/**
 * Funções de validação reutilizadas pelos endpoints da API.
 * Mantidas como funções puras (sem I/O) para permitir testes isolados.
 */

function campoPreenchido($valor): bool {
    return isset($valor) && is_string($valor) && trim($valor) !== '';
}

function emailValido(?string $email): bool {
    if (!campoPreenchido($email)) return false;
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function senhaForte(?string $senha, int $minimo = 6): bool {
    return campoPreenchido($senha) && strlen($senha) >= $minimo;
}

function inteiroValido($valor): bool {
    return is_numeric($valor) && (int)$valor == $valor && (int)$valor > 0;
}

/**
 * Valida uma data no formato Y-m-d, garantindo que:
 * - é uma data real (ex.: 2024-02-30 é rejeitada);
 * - não está no futuro;
 * - representa uma idade plausível (0 a 120 anos).
 */
function dataNascimentoValida(?string $data): bool {
    if (!campoPreenchido($data)) return false;

    $dt = DateTime::createFromFormat('Y-m-d', $data);
    if (!$dt || $dt->format('Y-m-d') !== $data) return false;

    // createFromFormat('Y-m-d', ...) preenche a HORA ATUAL (não meia-noite)
    // quando o formato não especifica horário - sem isso, cadastrar um
    // recém-nascido com data de nascimento igual a hoje era rejeitado como
    // "data futura" sempre que o teste rodasse depois de 00:00:00 (ou seja,
    // sempre). Zera a hora para comparar só as datas.
    $dt->setTime(0, 0, 0);

    $hoje = new DateTime('today');
    if ($dt > $hoje) return false;

    $idade = $hoje->diff($dt)->y;
    if ($idade > 120) return false;

    return true;
}
