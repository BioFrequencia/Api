<?php
/**
 * Contato de cada escola com os responsáveis (tb_escola.email_contato e
 * telefone_contato, migração 012). Cada escola tem o seu - nada de um
 * e-mail ou telefone único para todas. Vai no fim das mensagens aos
 * responsáveis e, no e-mail, como "responder para" (helpers/avisos.php):
 * o remetente continua o endereço do sistema (EMAIL_REMETENTE), o único
 * que o provedor de e-mail aceita enviar, e a resposta do responsável
 * chega à escola.
 *
 * Toda conta da escola vê; só a gestão altera.
 */

const EMAIL_CONTATO_MAXIMO = 150;

/**
 * Lê o contato enviado pela tela. Campo ausente não muda; vazio apaga.
 * Telefone: só os dígitos, com DDD (10 ou 11 dígitos). Função pura.
 *
 * @return array{0: array{email: ?string, telefone: ?string}, 1: ?string} [contato novo, erro]
 */
function lerContatoDaEscola(array $dados, array $atual): array {
    $novo = ['email' => $atual['email'] ?? null, 'telefone' => $atual['telefone'] ?? null];
    if (array_key_exists('email_contato', $dados)) {
        $email = $dados['email_contato'];
        if ($email !== null && !is_string($email)) {
            return [$atual, 'E-mail de contato inválido.'];
        }
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') {
            $novo['email'] = null;
        } elseif (mb_strlen($email) > EMAIL_CONTATO_MAXIMO || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [$atual, 'Informe um e-mail de contato válido (ex.: secretaria@escola.sp.gov.br).'];
        } else {
            $novo['email'] = $email;
        }
    }
    if (array_key_exists('telefone_contato', $dados)) {
        $telefone = $dados['telefone_contato'];
        if ($telefone !== null && !is_string($telefone) && !is_int($telefone)) {
            return [$atual, 'Telefone de contato inválido.'];
        }
        $digitos = preg_replace('/\D/', '', (string) $telefone) ?? '';
        if ($digitos === '') {
            $novo['telefone'] = null;
        } elseif (!preg_match('/^[1-9]{2}\d{8,9}$/', $digitos)) {
            return [$atual, 'Informe o telefone de contato com DDD, ex.: (11) 3333-4444 ou (11) 99999-1111.'];
        } else {
            $novo['telefone'] = $digitos;
        }
    }
    return [$novo, null];
}

/** @return array{email: ?string, telefone: ?string, atualizado_em: ?string, atualizado_por: ?string} */
function contatoDaEscola(PDO $conexao, int $idEscola): array {
    $stmt = $conexao->prepare("
        SELECT e.email_contato, e.telefone_contato, e.contato_atualizado_em, c.nome_coordenacao AS contato_atualizado_por
        FROM tb_escola e
        LEFT JOIN tb_coordenacao c ON c.id_coordenacao = e.contato_atualizado_por
        WHERE e.id_escola = ?
    ");
    $stmt->execute([$idEscola]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'email'          => $linha['email_contato'] ?? null,
        'telefone'       => $linha['telefone_contato'] ?? null,
        'atualizado_em'  => $linha['contato_atualizado_em'] ?? null,
        'atualizado_por' => $linha['contato_atualizado_por'] ?? null,
    ];
}

function salvarContatoDaEscola(PDO $conexao, int $idEscola, array $contato, int $idCoordenacao): void {
    $conexao->prepare("
        UPDATE tb_escola
        SET email_contato = ?, telefone_contato = ?, contato_atualizado_em = NOW(), contato_atualizado_por = ?
        WHERE id_escola = ?
    ")->execute([$contato['email'], $contato['telefone'], $idCoordenacao, $idEscola]);
}
