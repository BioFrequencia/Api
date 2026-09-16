<?php
/**
 * Validações específicas de responsável - separadas de helpers/validacao.php
 * porque são regras de formato de documento, não validações genéricas de
 * campo reutilizadas em todo o resto da API.
 */

/** Aceita CPF com ou sem pontuação; exige 11 dígitos (não valida dígito verificador - fora de escopo). */
function cpfValido(?string $cpf): bool {
    if (!campoPreenchido($cpf)) return false;
    $digitos = preg_replace('/\D/', '', $cpf);
    return strlen($digitos) === 11;
}

/** Aceita telefone com ou sem pontuação/DDD; exige 10 ou 11 dígitos (fixo ou celular). */
function telefoneValido(?string $telefone): bool {
    if (!campoPreenchido($telefone)) return false;
    $digitos = preg_replace('/\D/', '', $telefone);
    return strlen($digitos) === 10 || strlen($digitos) === 11;
}

function somenteDigitos(string $valor): string {
    return preg_replace('/\D/', '', $valor);
}
