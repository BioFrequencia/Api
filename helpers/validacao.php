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

const SENHA_MINIMA = 8;
const SENHA_MAXIMA = 128;

/**
 * Os requisitos que a senha ainda não cumpre, no texto que a tela mostra
 * (vazio = senha aceita). São os mesmos do site e do software desktop:
 * 8 a 128 caracteres, com maiúscula, minúscula, número e caractere especial
 * (qualquer coisa que não seja letra, número ou espaço). Espaço é aceito.
 *
 * @return string[]
 */
function requisitosSenhaPendentes(?string $senha): array {
    $senha = (string) $senha;
    $tamanho = mb_strlen($senha);
    $tem = fn(string $padrao) => preg_match($padrao, $senha) === 1;
    $pendentes = [];
    if ($tamanho < SENHA_MINIMA) $pendentes[] = 'pelo menos ' . SENHA_MINIMA . ' caracteres';
    if (!$tem('/\p{Lu}/u')) $pendentes[] = 'uma letra maiúscula';
    if (!$tem('/\p{Ll}/u')) $pendentes[] = 'uma letra minúscula';
    if (!$tem('/\p{N}/u')) $pendentes[] = 'um número';
    if (!$tem('/[^\p{L}\p{N}\s]/u')) $pendentes[] = 'um caractere especial (ex.: ! @ # $ %)';
    if ($tamanho > SENHA_MAXIMA) $pendentes[] = 'no máximo ' . SENHA_MAXIMA . ' caracteres';
    return $pendentes;
}

function senhaForte(?string $senha): bool {
    return campoPreenchido($senha) && requisitosSenhaPendentes($senha) === [];
}

/** "A senha precisa ter uma letra maiúscula e um número." */
function mensagemRequisitosSenha(array $pendentes): string {
    $ultimo = array_pop($pendentes);
    $lista = $pendentes === [] ? $ultimo : implode(', ', $pendentes) . ' e ' . $ultimo;
    return "A senha precisa ter $lista.";
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
