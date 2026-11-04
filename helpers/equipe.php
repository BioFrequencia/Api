<?php
require_once __DIR__ . '/escola.php';
require_once __DIR__ . '/sessao.php';
require_once __DIR__ . '/emailConta.php';

/**
 * Equipe de cada escola: quem entra, quem é gestor e quem sai. Usado pela
 * gestão (escola/atualizarMembro.php) e pela equipe do sistema
 * (admin/atualizarConta.php).
 *
 * Cada conta é de UMA escola (tb_coordenacao.id_escola). Toda conta nova
 * entra como "pendente" e só acessa depois de aprovada - e a aprovação só
 * libera depois que a pessoa confirmou o e-mail (migração 009).
 *
 * Cargo e permissão são coisas diferentes (migração 010):
 *   - cargo: a função na escola (direção, vice-direção, coordenação,
 *     secretaria, outro), informada no cadastro - só descritivo;
 *   - papel: a permissão no sistema. "gestor" (Gestão) configura a escola,
 *     aprova a equipe e apaga registros; "coordenador" (Coordenação) faz o
 *     dia a dia.
 * Quem aprova escolhe a permissão; sem escolha, vale a sugerida pelo cargo
 * (direção e vice-direção: Gestão). Uma escola pode ter várias contas de
 * Gestão. Quem é aprovado numa escola ainda sem gestão ativa vira a gestão
 * dela, qualquer que seja o cargo.
 *
 * A escola nunca fica sem uma conta gestora ativa: desativar ou rebaixar
 * a última é recusado.
 */

const ACOES_EQUIPE = ['aprovar', 'recusar', 'desativar', 'reativar', 'tornar_gestor', 'tornar_coordenador'];

/** Permissões no sistema: Gestão e Coordenação (cargos e sugestão: helpers/escola.php). */
const PAPEIS = ['gestor', 'coordenador'];

/** Conta de qualquer escola, para as ações da equipe. */
function membroDaEquipe(PDO $conexao, $idCoordenacao): ?array {
    $stmt = $conexao->prepare("
        SELECT c.id_coordenacao, c.nome_coordenacao, c.email_coordenacao, c.id_escola, c.papel, c.situacao, c.cargo,
               c.email_verificado_em IS NOT NULL AS email_verificado, e.nome_escola
        FROM tb_coordenacao c JOIN tb_escola e ON e.id_escola = c.id_escola
        WHERE c.id_coordenacao = ?
    ");
    $stmt->execute([$idCoordenacao]);
    $membro = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($membro) {
        $membro['id_escola'] = (int) $membro['id_escola'];
        $membro['id_coordenacao'] = (int) $membro['id_coordenacao'];
        $membro['email_verificado'] = valorBooleano($membro['email_verificado']);
    }
    return $membro ?: null;
}

/**
 * Situação e papel depois da ação (função pura). Devolve [situacao,
 * papel, erro]: com erro, nada muda.
 *
 * $ficaSemGestor: a escola não tem OUTRA conta gestora ativa além desta
 * (ficaria sem gestão se esta deixasse de ser gestora ativa).
 *
 * Aprovar e reativar exigem o e-mail confirmado ($membro['email_verificado']).
 * Na aprovação, $papelEscolhido ('gestor' ou 'coordenador') é a permissão
 * que quem aprova escolheu; sem escolha, vale a sugerida pelo cargo. Numa
 * escola sem gestão ativa, a pessoa aprovada é sempre gestor.
 */
function proximoEstadoDoMembro(array $membro, string $acao, bool $ficaSemGestor, ?string $papelEscolhido = null): array {
    $situacao = $membro['situacao'];
    $papel = $membro['papel'];
    $semGestor = "A escola precisa de pelo menos uma conta gestora ativa: promova outra pessoa antes.";
    $semEmail = "Esta conta ainda não confirmou o e-mail: a aprovação fica liberada quando a pessoa abrir o link que recebeu.";
    $emailConfirmado = !empty($membro['email_verificado']);
    switch ($acao) {
        case 'aprovar':
            if ($situacao !== 'pendente') return [$situacao, $papel, "Só contas aguardando aprovação podem ser aprovadas."];
            if (!$emailConfirmado) return [$situacao, $papel, $semEmail];
            if ($ficaSemGestor) return ['ativo', 'gestor', null];
            return ['ativo', $papelEscolhido ?? papelSugeridoPeloCargo($membro['cargo'] ?? null), null];
        case 'recusar':
            return $situacao === 'pendente' ? ['recusado', $papel, null] : [$situacao, $papel, "Só contas aguardando aprovação podem ser recusadas."];
        case 'desativar':
            if ($situacao !== 'ativo') return [$situacao, $papel, "A conta já não está ativa."];
            if ($papel === 'gestor' && $ficaSemGestor) return [$situacao, $papel, $semGestor];
            return ['desativado', $papel, null];
        case 'reativar':
            if (!in_array($situacao, ['desativado', 'recusado'], true)) return [$situacao, $papel, "A conta já está ativa ou aguardando aprovação."];
            if (!$emailConfirmado) return [$situacao, $papel, $semEmail];
            return ['ativo', $papel, null];
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
 * de estar ativa perde as sessões abertas na hora. Quem pediu o acesso
 * recebe um e-mail quando é aprovado ou recusado. $papelEscolhido: a
 * permissão escolhida na aprovação (ver proximoEstadoDoMembro).
 */
function aplicarAcaoNaEquipe(PDO $conexao, array $membro, string $acao, int $idQuemFez, ?string $papelEscolhido = null): ?string {
    $ficaSemGestor = !escolaTemGestor($conexao, $membro['id_escola'], $membro['id_coordenacao']);
    [$situacao, $papel, $erro] = proximoEstadoDoMembro($membro, $acao, $ficaSemGestor, $papelEscolhido);
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
    avisarResultadoDoPedido($membro, $acao);
    return null;
}

/** Lista da equipe de uma escola (a mais recente primeiro entre as pendentes). */
function equipeDaEscola(PDO $conexao, int $idEscola): array {
    $stmt = $conexao->prepare("
        SELECT id_coordenacao, nome_coordenacao, email_coordenacao, papel, cargo, situacao, criado_em, aprovado_em,
               email_verificado_em IS NOT NULL AS email_verificado
        FROM tb_coordenacao
        WHERE id_escola = ?
        ORDER BY (situacao = 'pendente') DESC, (email_verificado_em IS NOT NULL) DESC, (situacao = 'ativo') DESC,
                 (papel = 'gestor') DESC, nome_coordenacao
    ");
    $stmt->execute([$idEscola]);
    return array_map(function ($membro) {
        $membro['id_coordenacao'] = (int) $membro['id_coordenacao'];
        $membro['email_verificado'] = valorBooleano($membro['email_verificado']);
        return $membro;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
