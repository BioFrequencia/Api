<?php
/**
 * Conta do aluno no app (migração 006). O aluno cria a conta no próprio
 * app com o nome e a data de nascimento do cadastro feito pela escola, e
 * cada login registra o celular dele. Substitui o código de vínculo que a
 * coordenação gerava no site.
 */

/**
 * Nome comparável: minúsculas, sem acento e com um espaço só entre as
 * palavras ("  JOÃO  Pedro " e "joao pedro" ficam iguais). Tabela fixa em
 * vez de iconv//TRANSLIT, cujo resultado muda com o locale e a libc do
 * servidor.
 */
function normalizarNome(string $nome): string {
    $minusculo = mb_strtolower(trim($nome), 'UTF-8');
    $semAcento = strtr($minusculo, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
    return preg_replace('/\s+/u', ' ', $semAcento);
}

/**
 * O nome digitado no app confere com o do cadastro da escola? Vale o nome
 * completo igual ou o primeiro e o último nome iguais: o aluno não precisa
 * lembrar como a secretaria escreveu os nomes do meio. Quem separa
 * homônimos é a data de nascimento, conferida na consulta.
 */
function nomesConferem(string $digitado, string $cadastrado): bool {
    $digitado = normalizarNome($digitado);
    $cadastrado = normalizarNome($cadastrado);
    if ($digitado === '' || $cadastrado === '') {
        return false;
    }
    if ($digitado === $cadastrado) {
        return true;
    }
    $partesDigitado = explode(' ', $digitado);
    $partesCadastro = explode(' ', $cadastrado);
    return count($partesDigitado) >= 2 && count($partesCadastro) >= 2
        && $partesDigitado[0] === $partesCadastro[0]
        && end($partesDigitado) === end($partesCadastro);
}

/**
 * Conecta o celular à conta e monta a resposta do login e do cadastro.
 *
 * Um celular por aluno: entrar num aparelho desconecta os outros, para um
 * login feito no celular de um colega não continuar enviando check-ins em
 * nome do aluno. device_secret só sai nesta resposta - o app o guarda no
 * armazenamento seguro do sistema e assina com ele cada check-in.
 *
 * Chamar dentro de uma transação.
 */
function conectarCelularDoAluno(PDO $conexao, int $idConta, int $idAluno, string $deviceUuid): array {
    $desconectaOutros = $conexao->prepare("
        UPDATE tb_dispositivo_aluno SET ativo = FALSE, revogado_em = NOW()
        WHERE id_aluno = ? AND device_uuid <> ? AND ativo = TRUE
    ");
    $desconectaOutros->bindValue(1, $idAluno);
    $desconectaOutros->bindValue(2, $deviceUuid);
    $desconectaOutros->execute();

    $dispositivo = registrarDispositivo($conexao, $idAluno, $deviceUuid);

    $stmt = $conexao->prepare("
        UPDATE tb_conta_aluno c SET ultimo_login_em = NOW()
        FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala
        WHERE c.id_conta = ? AND a.id_aluno = c.id_aluno
        RETURNING c.email_conta, a.nome_aluno, s.nome_sala
    ");
    $stmt->bindValue(1, $idConta);
    $stmt->execute();
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        "id_dispositivo" => $dispositivo['id_dispositivo'],
        "id_aluno"       => $idAluno,
        "nome_aluno"     => $conta['nome_aluno'],
        "nome_sala"      => $conta['nome_sala'],
        "email"          => $conta['email_conta'],
        "device_secret"  => $dispositivo['device_secret'],
    ];
}

/**
 * E-mail e senha são de uma conta de ALUNO? Usado pelo login do site
 * (user/selectCoord.php) para dizer "use o app" em vez de "senha
 * incorreta". Qualquer erro de banco conta como "não" - inclusive a tabela
 * ainda não existir: é só a escolha da mensagem, não pode derrubar o login.
 */
function contaDeAlunoConfere(PDO $conexao, string $email, string $senha): bool {
    try {
        $stmt = $conexao->prepare("SELECT senha_conta FROM tb_conta_aluno WHERE lower(email_conta) = lower(?)");
        $stmt->bindValue(1, trim($email));
        $stmt->execute();
        $hash = $stmt->fetchColumn();
        return is_string($hash) && password_verify($senha, $hash);
    } catch (PDOException $erro) {
        error_log('[BioFrequenciaApi][contaAluno] ' . $erro->getMessage());
        return false;
    }
}
