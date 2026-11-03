<?php
require_once __DIR__ . '/escola.php';
require_once __DIR__ . '/sessao.php';

/**
 * Equipe de cada escola: quem entra, quem é gestor e quem sai. Usado pela
 * gestão (escola/atualizarMembro.php) e pela equipe do sistema
 * (admin/atualizarConta.php).
 *
 * Cada conta é de UMA escola (tb_coordenacao.id_escola). Quem se cadastra
 * numa escola que já tem gestão entra como "pendente" e só acessa depois
 * de aprovado: antes, qualquer pessoa com o código de acesso via os
 * alunos, os responsáveis e os telefones de uma escola inteira.
 *
 * A escola nunca fica sem uma conta gestora ativa: desativar ou rebaixar
 * a última é recusado.
 */

const ACOES_EQUIPE = ['aprovar', 'recusar', 'desativar', 'reativar', 'tornar_gestor', 'tornar_coordenador'];

/** Conta de qualquer escola, para as ações da equipe. */
function membroDaEquipe(PDO $conexao, $idCoordenacao): ?array {
    $stmt = $conexao->prepare("
        SELECT id_coordenacao, nome_coordenacao, email_coordenacao, id_escola, papel, situacao
        FROM tb_coordenacao WHERE id_coordenacao = ?
    ");
    $stmt->execute([$idCoordenacao]);
    $membro = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($membro) {
        $membro['id_escola'] = (int) $membro['id_escola'];
        $membro['id_coordenacao'] = (int) $membro['id_coordenacao'];
    }
    return $membro ?: null;
}

/**
 * Situação e papel depois da ação (função pura). Devolve [situacao,
 * papel, erro]: com erro, nada muda.
 *
 * $ficaSemGestor: a escola ficaria sem gestor ativo se esta conta deixar
 * de ser gestora ativa.
 */
function proximoEstadoDoMembro(array $membro, string $acao, bool $ficaSemGestor): array {
    $situacao = $membro['situacao'];
    $papel = $membro['papel'];
    $semGestor = "A escola precisa de pelo menos uma conta gestora ativa: promova outra pessoa antes.";
    switch ($acao) {
        case 'aprovar':
            return $situacao === 'pendente' ? ['ativo', $papel, null] : [$situacao, $papel, "Só contas aguardando aprovação podem ser aprovadas."];
        case 'recusar':
            return $situacao === 'pendente' ? ['recusado', $papel, null] : [$situacao, $papel, "Só contas aguardando aprovação podem ser recusadas."];
        case 'desativar':
            if ($situacao !== 'ativo') return [$situacao, $papel, "A conta já não está ativa."];
            if ($papel === 'gestor' && $ficaSemGestor) return [$situacao, $papel, $semGestor];
            return ['desativado', $papel, null];
        case 'reativar':
            return in_array($situacao, ['desativado', 'recusado'], true) ? ['ativo', $papel, null] : [$situacao, $papel, "A conta já está ativa ou aguardando aprovação."];
        case 'tornar_gestor':
            return $situacao === 'ativo' && $papel !== 'gestor' ? [$situacao, 'gestor', null] : [$situacao, $papel, "Só contas ativas que ainda não são gestoras podem ser promovidas."];
        case 'tornar_coordenador':
            if ($papel !== 'gestor') return [$situacao, $papel, "A conta já não é gestora."];
            if ($situacao === 'ativo' && $ficaSemGestor) return [$situacao, $papel, $semGestor];
            return [$situacao, 'coordenador', null];
    }
    return [$situacao, $papel, "Ação inválida."];
}

/**
 * Aplica a ação e devolve a mensagem de erro (ou null). Conta que deixa
 * de estar ativa perde as sessões abertas na hora.
 */
function aplicarAcaoNaEquipe(PDO $conexao, array $membro, string $acao, int $idQuemFez): ?string {
    $ficaSemGestor = !escolaTemGestor($conexao, $membro['id_escola'], $membro['id_coordenacao']);
    [$situacao, $papel, $erro] = proximoEstadoDoMembro($membro, $acao, $ficaSemGestor);
    if ($erro !== null) {
        return $erro;
    }
    $aprovacao = $acao === 'aprovar' || $acao === 'reativar';
    $stmt = $conexao->prepare("
        UPDATE tb_coordenacao
        SET situacao = ?, papel = ?,
            aprovado_em = CASE WHEN ? THEN NOW() ELSE aprovado_em END,
            aprovado_por = CASE WHEN ? THEN ?::int ELSE aprovado_por END
        WHERE id_coordenacao = ?
    ");
    $stmt->execute([$situacao, $papel, $aprovacao ? 'true' : 'false', $aprovacao ? 'true' : 'false', $idQuemFez, $membro['id_coordenacao']]);
    if ($situacao !== 'ativo') {
        encerrarSessoes($conexao, $membro['id_coordenacao'], null, '');
    }
    return null;
}

/** Lista da equipe de uma escola (a mais recente primeiro entre as pendentes). */
function equipeDaEscola(PDO $conexao, int $idEscola): array {
    $stmt = $conexao->prepare("
        SELECT id_coordenacao, nome_coordenacao, email_coordenacao, papel, situacao, criado_em, aprovado_em
        FROM tb_coordenacao
        WHERE id_escola = ?
        ORDER BY (situacao = 'pendente') DESC, (situacao = 'ativo') DESC, (papel = 'gestor') DESC, nome_coordenacao
    ");
    $stmt->execute([$idEscola]);
    return array_map(function ($membro) {
        $membro['id_coordenacao'] = (int) $membro['id_coordenacao'];
        return $membro;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
