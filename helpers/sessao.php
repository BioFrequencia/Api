<?php
require_once __DIR__ . '/conta.php';

/**
 * Sessões de login (migração 007). Cada login - no site ou no app - vira
 * uma linha de tb_sessao, um "dispositivo conectado" que a coordenação vê
 * em Minha conta e pode desconectar. O id da sessão vai no token ("sid"),
 * e Authentication/Authentication.php recusa o token quando a sessão foi
 * encerrada ou venceu.
 */

/** Aceita o boolean do PostgreSQL como o pdo_pgsql devolve (bool) ou como texto. */
function valorBooleano($valor): bool {
    return $valor === true || $valor === 't' || $valor === 'true' || $valor === 1 || $valor === '1';
}

/**
 * Cria a sessão do login e devolve o id. Devolve null se tb_sessao ainda
 * não existe (banco sem a migração 007): o login continua funcionando
 * como antes, com um token sem sessão.
 */
function criarSessao(PDO $conexao, int $idCoordenacao, string $cliente, bool $lembrar, int $duracaoSegundos, ?string $userAgent): ?string {
    $idSessao = bin2hex(random_bytes(16));
    try {
        // lembrar vai como texto 'true'/'false' (ver helpers/registroEvento.php).
        $stmt = $conexao->prepare("
            INSERT INTO tb_sessao (id_sessao, id_coordenacao, cliente, dispositivo, lembrar, expira_em)
            VALUES (?, ?, ?, ?, ?, NOW() + make_interval(secs => ?))
        ");
        $stmt->execute([
            $idSessao,
            $idCoordenacao,
            $cliente,
            mb_substr(descreverDispositivo($userAgent, $cliente), 0, 120),
            $lembrar ? 'true' : 'false',
            $duracaoSegundos,
        ]);
        return $idSessao;
    } catch (PDOException $erro) {
        error_log('[BioFrequenciaApi] sessão não registrada (migração 007 aplicada?): ' . $erro->getMessage());
        return null;
    }
}

/**
 * A sessão do token ainda vale? O último acesso é atualizado no máximo a
 * cada 5 minutos: a lista de aparelhos não precisa de mais precisão, e
 * cada atualização é uma escrita no banco.
 */
function sessaoAtiva(PDO $conexao, string $idSessao, int $idCoordenacao): bool {
    // A conta também precisa continuar ativa (migração 008): a gestão ou a
    // equipe do sistema pode desativar alguém que já estava logado.
    $stmt = $conexao->prepare("
        SELECT s.ultimo_acesso < NOW() - INTERVAL '5 minutes' AS desatualizada
        FROM tb_sessao s
        JOIN tb_coordenacao c ON c.id_coordenacao = s.id_coordenacao AND c.situacao = 'ativo'
        WHERE s.id_sessao = ? AND s.id_coordenacao = ? AND s.encerrada_em IS NULL AND s.expira_em > NOW()
    ");
    $stmt->execute([$idSessao, $idCoordenacao]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        return false;
    }
    if (valorBooleano($linha['desatualizada'])) {
        $conexao->prepare("UPDATE tb_sessao SET ultimo_acesso = NOW() WHERE id_sessao = ?")->execute([$idSessao]);
    }
    return true;
}

/**
 * Encerra sessões da conta: a de $idSessao, ou - com $idSessao null -
 * todas as abertas menos $exceto (a do aparelho que pediu). Devolve
 * quantas foram encerradas.
 */
function encerrarSessoes(PDO $conexao, int $idCoordenacao, ?string $idSessao, ?string $exceto = null): int {
    if ($idSessao !== null) {
        $stmt = $conexao->prepare("
            UPDATE tb_sessao SET encerrada_em = NOW()
            WHERE id_sessao = ? AND id_coordenacao = ? AND encerrada_em IS NULL AND expira_em > NOW()
        ");
        $stmt->execute([$idSessao, $idCoordenacao]);
    } else {
        $stmt = $conexao->prepare("
            UPDATE tb_sessao SET encerrada_em = NOW()
            WHERE id_coordenacao = ? AND encerrada_em IS NULL AND expira_em > NOW() AND id_sessao <> ?
        ");
        $stmt->execute([$idCoordenacao, $exceto ?? '']);
    }
    return $stmt->rowCount();
}
