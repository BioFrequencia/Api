<?php
require_once __DIR__ . '/provedores.php';
require_once __DIR__ . '/escola.php';
require_once __DIR__ . '/corsRegras.php';

/**
 * E-mails da própria conta (migração 009): o link para confirmar o e-mail
 * - no cadastro e na troca do e-mail de login -, o link para criar uma
 * senha nova (migração 010, "Esqueci minha senha") e os avisos do pedido
 * de acesso (para quem aprova e, depois, para quem pediu).
 *
 * O código do link é aleatório (32 bytes) e vale por 24 horas (o de senha
 * nova, por 1 hora); o banco guarda só o hash SHA-256 dele, então nem quem
 * lê o banco confirma o e-mail nem troca a senha de outra pessoa. Pedir um
 * link novo vence os anteriores da mesma finalidade.
 *
 * O endereço do site, para montar os links, vem de SITE_URL (ou da
 * primeira origem fixa de CORS_ORIGENS).
 */

const VALIDADE_LINK_HORAS = 24;
const VALIDADE_LINK_SENHA_HORAS = 1;
const ESPERA_ENTRE_LINKS_SEGUNDOS = 60;
const LINKS_POR_DIA = 5;
/** Contas novas, ainda sem e-mail confirmado, aceitas por hora no sistema todo (freio contra robôs). */
const CADASTROS_POR_HORA = 30;

function urlDoSite(): string {
    $configurada = trim((string) getenv('SITE_URL'));
    if ($configurada !== '') {
        return rtrim($configurada, '/');
    }
    foreach (corsOrigensPermitidas() as $origem) {
        if (!str_contains($origem, '*')) {
            return rtrim($origem, '/');
        }
    }
    return 'http://localhost:3000';
}

function hashDoCodigo(string $codigo): string {
    return hash('sha256', $codigo);
}

function codigoDeConfirmacaoValido($codigo): bool {
    return is_string($codigo) && preg_match('/^[0-9a-f]{64}$/', $codigo) === 1;
}

function linkDeConfirmacao(string $codigo): string {
    return urlDoSite() . '/confirmar-email?codigo=' . $codigo;
}

function linkDeNovaSenha(string $codigo): string {
    return urlDoSite() . '/redefinir-senha?codigo=' . $codigo;
}

/**
 * Regra de espera entre links (função pura - testada em
 * tests/helpers_test.php): um minuto entre um e outro e no máximo cinco
 * por dia, por conta. Devolve a mensagem de recusa ou null.
 */
function esperaParaNovoLink(int $enviadosNoDia, ?float $segundosDesdeOUltimo): ?string {
    if ($enviadosNoDia >= LINKS_POR_DIA) {
        return "Já enviamos " . LINKS_POR_DIA . " links nas últimas 24 horas. Confira a caixa de spam ou tente de novo amanhã.";
    }
    if ($segundosDesdeOUltimo !== null && $segundosDesdeOUltimo < ESPERA_ENTRE_LINKS_SEGUNDOS) {
        return "Acabamos de enviar um link. Espere um minuto antes de pedir outro.";
    }
    return null;
}

function motivoParaEsperarLink(PDO $conexao, int $idCoordenacao): ?string {
    $stmt = $conexao->prepare("
        SELECT COUNT(*) AS no_dia, MIN(EXTRACT(EPOCH FROM (NOW() - criado_em))) AS segundos
        FROM tb_verificacao_email
        WHERE id_coordenacao = ? AND criado_em > NOW() - INTERVAL '1 day'
    ");
    $stmt->execute([$idCoordenacao]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    return esperaParaNovoLink((int) $linha['no_dia'], $linha['segundos'] === null ? null : (float) $linha['segundos']);
}

/**
 * Cria o link (vencendo os anteriores da mesma finalidade) e devolve o
 * código, que só vai no e-mail. $finalidade: 'cadastro', 'troca_email' ou
 * 'redefinir_senha'.
 */
function criarCodigoDeConfirmacao(PDO $conexao, int $idCoordenacao, string $email, string $finalidade, int $validadeHoras = VALIDADE_LINK_HORAS): string {
    $conexao->prepare("
        UPDATE tb_verificacao_email SET expira_em = NOW()
        WHERE id_coordenacao = ? AND finalidade = ? AND usado_em IS NULL AND expira_em > NOW()
    ")->execute([$idCoordenacao, $finalidade]);
    $codigo = bin2hex(random_bytes(32));
    $conexao->prepare("
        INSERT INTO tb_verificacao_email (id_coordenacao, email, finalidade, token_hash, expira_em)
        VALUES (?, ?, ?, ?, NOW() + make_interval(hours => ?::int))
    ")->execute([$idCoordenacao, $email, $finalidade, hashDoCodigo($codigo), $validadeHoras]);
    return $codigo;
}

function primeiroNome(string $nome): string {
    $partes = preg_split('/\s+/', trim($nome));
    return $partes[0] !== '' ? $partes[0] : trim($nome);
}

/** Texto do e-mail em HTML simples: parágrafos, um botão e o link por extenso. */
function htmlDoEmail(array $paragrafos, ?string $botao = null, ?string $link = null, ?string $rodape = null): string {
    $e = fn(string $valor) => htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#211f2b;max-width:520px">';
    foreach ($paragrafos as $paragrafo) {
        $html .= '<p>' . $e($paragrafo) . '</p>';
    }
    if ($botao !== null && $link !== null) {
        $html .= '<p><a href="' . $e($link) . '" style="display:inline-block;background:#452ff6;color:#ffffff;'
            . 'padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">' . $e($botao) . '</a></p>';
    }
    if ($rodape !== null) {
        $html .= '<p style="color:#6b6f77;font-size:13px">' . $e($rodape) . '</p>';
    }
    return $html . '<p>Equipe BioFrequência</p></div>';
}

/**
 * Assunto, texto e HTML do e-mail com o link de confirmação (função
 * pura). $finalidade: 'cadastro' ou 'troca_email'.
 */
function mensagemDeConfirmacao(string $nome, string $escola, string $link, string $finalidade): array {
    $ola = 'Olá, ' . primeiroNome($nome) . '!';
    if ($finalidade === 'troca_email') {
        $assunto = 'Confirme o seu novo e-mail no BioFrequência';
        $pedido = 'Para passar a entrar no BioFrequência com este e-mail, abra o link abaixo.';
        $botao = 'Confirmar o novo e-mail';
        $ignorar = 'Se não foi você que pediu a troca, ignore esta mensagem: o e-mail da conta continua o mesmo.';
    } else {
        $assunto = 'Confirme o seu e-mail no BioFrequência';
        $pedido = "Para confirmar o e-mail da sua conta no BioFrequência ($escola), abra o link abaixo.";
        $botao = 'Confirmar o e-mail';
        $ignorar = 'Se não foi você que se cadastrou, ignore esta mensagem.';
    }
    $validade = 'O link vale por ' . VALIDADE_LINK_HORAS . ' horas.';
    $texto = "$ola\n\n$pedido\n$link\n\n$validade $ignorar\n\nEquipe BioFrequência";
    $html = htmlDoEmail([$ola, $pedido], $botao, $link, "$validade $ignorar Se o botão não abrir, copie este endereço: $link");
    return [$assunto, $texto, $html];
}

/** Gera o link e manda o e-mail de confirmação. $conta: id_coordenacao, nome_coordenacao, nome_escola. Devolve ENVIO_*. */
function enviarLinkDeConfirmacao(PDO $conexao, array $conta, string $email, string $finalidade): string {
    if (!emailConfigurado()) {
        return ENVIO_SEM_PROVEDOR;
    }
    $codigo = criarCodigoDeConfirmacao($conexao, (int) $conta['id_coordenacao'], $email, $finalidade);
    [$assunto, $texto, $html] = mensagemDeConfirmacao($conta['nome_coordenacao'], $conta['nome_escola'], linkDeConfirmacao($codigo), $finalidade);
    return enviarEmail($email, $assunto, $texto, $html);
}

/** Assunto, texto e HTML do e-mail com o link para criar uma senha nova (função pura). */
function mensagemDeNovaSenha(string $nome, string $link): array {
    $ola = 'Olá, ' . primeiroNome($nome) . '!';
    $assunto = 'Crie uma senha nova no BioFrequência';
    $pedido = 'Recebemos um pedido para criar uma senha nova para a sua conta no BioFrequência. Para continuar, abra o link abaixo.';
    $validade = 'O link vale por ' . VALIDADE_LINK_SENHA_HORAS . ' hora e só funciona uma vez.';
    $ignorar = 'Se não foi você que pediu, ignore esta mensagem: a sua senha continua a mesma.';
    $texto = "$ola\n\n$pedido\n$link\n\n$validade $ignorar\n\nEquipe BioFrequência";
    $html = htmlDoEmail([$ola, $pedido], 'Criar uma senha nova', $link, "$validade $ignorar Se o botão não abrir, copie este endereço: $link");
    return [$assunto, $texto, $html];
}

/** Gera o link de senha nova e manda o e-mail. $conta: id_coordenacao, nome_coordenacao, email_coordenacao. Devolve ENVIO_*. */
function enviarLinkDeNovaSenha(PDO $conexao, array $conta): string {
    if (!emailConfigurado()) {
        return ENVIO_SEM_PROVEDOR;
    }
    $codigo = criarCodigoDeConfirmacao($conexao, (int) $conta['id_coordenacao'], $conta['email_coordenacao'], 'redefinir_senha', VALIDADE_LINK_SENHA_HORAS);
    [$assunto, $texto, $html] = mensagemDeNovaSenha($conta['nome_coordenacao'], linkDeNovaSenha($codigo));
    return enviarEmail($conta['email_coordenacao'], $assunto, $texto, $html);
}

/**
 * Quem libera uma conta pendente: a gestão da escola, quando já existe
 * uma conta gestora ativa nela; senão, a equipe do sistema (ADMIN_EMAILS).
 */
function quemAprova(PDO $conexao, int $idEscola, int $idCoordenacao): string {
    return escolaTemGestor($conexao, $idEscola, $idCoordenacao) ? 'gestao' : 'equipe';
}

/**
 * O que a tela de cadastro mostra depois de criar a conta (função pura).
 * $aprovacao: 'equipe' ou 'gestao'; $envio: ENVIO_*.
 */
function mensagemDoCadastro(string $email, string $nomeEscola, string $aprovacao, string $envio): string {
    $quem = $aprovacao === 'gestao' ? "a gestão da $nomeEscola" : 'a equipe do BioFrequência';
    if ($envio === ENVIO_OK) {
        return "Conta criada! Enviamos um link de confirmação para $email. Depois que você confirmar, $quem aprova o seu acesso.";
    }
    if ($envio === ENVIO_SEM_PROVEDOR) {
        return "Conta criada, mas o envio de e-mails ainda não foi configurado no sistema. Avise a equipe do BioFrequência: ela confirma o seu e-mail e aprova o acesso.";
    }
    return "Conta criada, mas o e-mail de confirmação não saiu agora. Peça outro em \"Reenviar o link\" daqui a alguns minutos. Depois que você confirmar, $quem aprova o seu acesso.";
}

/** E-mail para quem aprova: alguém confirmou o e-mail e pediu acesso (função pura). */
function mensagemDePedidoDeAcesso(array $conta, string $link): array {
    $municipio = trim((string) ($conta['municipio'] ?? '')) !== '' ? " ({$conta['municipio']})" : '';
    $cargo = CARGOS_NA_ESCOLA[$conta['cargo'] ?? ''] ?? null;
    $comoQuem = $cargo !== null ? ", como $cargo," : '';
    $assunto = "Nova conta aguardando aprovação: {$conta['nome_coordenacao']}";
    $pedido = "{$conta['nome_coordenacao']} ({$conta['email_coordenacao']}) confirmou o e-mail e pediu acesso ao BioFrequência$comoQuem na {$conta['nome_escola']}$municipio.";
    $texto = "$pedido\n\nPara aprovar ou recusar: $link\n\nEquipe BioFrequência";
    return [$assunto, $texto, htmlDoEmail([$pedido], 'Ver o pedido', $link)];
}

/**
 * Uma conta pendente acabou de confirmar o e-mail: avisa quem aprova (a
 * gestão ativa da escola ou os e-mails de ADMIN_EMAILS). É um extra - sem
 * provedor de e-mail, o pedido aparece no contador do menu de quem aprova.
 * $conta: id_coordenacao, nome_coordenacao, email_coordenacao, cargo, id_escola, nome_escola, municipio.
 */
function avisarQuemAprova(PDO $conexao, array $conta): void {
    if (!emailConfigurado()) {
        return;
    }
    if (quemAprova($conexao, (int) $conta['id_escola'], (int) $conta['id_coordenacao']) === 'gestao') {
        $stmt = $conexao->prepare("SELECT email_coordenacao FROM tb_coordenacao WHERE id_escola = ? AND papel = 'gestor' AND situacao = 'ativo'");
        $stmt->execute([(int) $conta['id_escola']]);
        $destinos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $caminho = '/escola?aba=equipe';
    } else {
        $destinos = emailsAdministradores();
        $caminho = '/admin?aba=contas';
    }
    [$assunto, $texto, $html] = mensagemDePedidoDeAcesso($conta, urlDoSite() . $caminho);
    foreach (array_unique(array_map('mb_strtolower', $destinos)) as $destino) {
        enviarEmail($destino, $assunto, $texto, $html);
    }
}

/** E-mail para quem pediu o acesso, depois da decisão (função pura). null para ações que não avisam. */
function mensagemDoResultadoDoPedido(string $nome, string $nomeEscola, string $acao, string $urlSite): ?array {
    $ola = 'Olá, ' . primeiroNome($nome) . '!';
    if ($acao === 'aprovar') {
        $assunto = 'Sua conta no BioFrequência foi aprovada';
        $corpo = "O seu acesso ao BioFrequência na $nomeEscola foi aprovado. Já pode entrar com o seu e-mail e senha.";
        $link = "$urlSite/login";
        return [$assunto, "$ola\n\n$corpo\n$link\n\nEquipe BioFrequência", htmlDoEmail([$ola, $corpo], 'Entrar no BioFrequência', $link)];
    }
    if ($acao === 'recusar') {
        $assunto = 'Seu pedido de acesso ao BioFrequência não foi aprovado';
        $corpo = "O pedido de acesso ao BioFrequência na $nomeEscola não foi aprovado. Se achar que é um engano, fale com a coordenação da escola.";
        return [$assunto, "$ola\n\n$corpo\n\nEquipe BioFrequência", htmlDoEmail([$ola, $corpo])];
    }
    return null;
}

/** Avisa a pessoa da decisão sobre o pedido de acesso (só aprovar e recusar avisam). */
function avisarResultadoDoPedido(array $membro, string $acao): void {
    $mensagem = mensagemDoResultadoDoPedido($membro['nome_coordenacao'], $membro['nome_escola'], $acao, urlDoSite());
    if ($mensagem === null || !emailConfigurado()) {
        return;
    }
    [$assunto, $texto, $html] = $mensagem;
    enviarEmail($membro['email_coordenacao'], $assunto, $texto, $html);
}
