<?php
/**
 * Validações específicas de responsável - separadas de helpers/validacao.php
 * porque são regras de formato de documento, não validações genéricas de
 * campo reutilizadas em todo o resto da API.
 */

/**
 * Aceita CPF com ou sem pontuação. Confere os dois dígitos
 * verificadores: o CPF é o que identifica o responsável entre alunos
 * (irmãos) e escolas, então um dígito trocado na digitação vinculava a
 * família errada ou criava uma pessoa duplicada.
 */
function cpfValido(?string $cpf): bool {
    if (!campoPreenchido($cpf)) return false;
    $digitos = preg_replace('/\D/', '', $cpf);
    if (strlen($digitos) !== 11 || preg_match('/^(\d)\1{10}$/', $digitos)) {
        return false;
    }
    for ($posicao = 9; $posicao <= 10; $posicao++) {
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += (int) $digitos[$i] * (($posicao + 1) - $i);
        }
        $verificador = ((10 * $soma) % 11) % 10;
        if ((int) $digitos[$posicao] !== $verificador) {
            return false;
        }
    }
    return true;
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

/**
 * Valores aceitos pela restrição chk_genero_responsavel do banco de
 * produção. "Outro" (o que o site oferecia) é recebido como sinônimo de
 * "Indefinido" para não quebrar clientes antigos.
 */
const GENEROS_RESPONSAVEL = ['Feminino', 'Masculino', 'Indefinido'];

function normalizarGeneroResponsavel($genero): ?string {
    if (!is_string($genero)) return null;
    $genero = trim($genero);
    if ($genero === 'Outro') return 'Indefinido';
    return in_array($genero, GENEROS_RESPONSAVEL, true) ? $genero : null;
}

/**
 * Mensagem para uma violação de unicidade (SQLSTATE 23505) ao gravar um
 * responsável, a partir do nome da restrição que aparece na mensagem do
 * banco (tb_responsavel_telefone_responsavel_key, ..._email_..., ...).
 */
function mensagemDuplicidadeResponsavel(string $mensagemBanco): string {
    if (str_contains($mensagemBanco, 'telefone')) {
        return "Já existe um responsável cadastrado com este telefone.";
    }
    if (str_contains($mensagemBanco, 'email')) {
        return "Já existe um responsável cadastrado com este email.";
    }
    if (str_contains($mensagemBanco, 'cpf')) {
        return "Já existe um responsável cadastrado com este CPF.";
    }
    return "Este responsável já está vinculado a este aluno.";
}
