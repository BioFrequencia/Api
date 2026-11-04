<?php
require_once __DIR__ . '/sessao.php';

/**
 * Limite de tentativas de login erradas, contra quem tenta adivinhar
 * senhas (migração 010, tb_tentativa_login).
 *
 * Só as tentativas que falham ficam registradas, por e-mail (o digitado,
 * exista a conta ou não - a resposta não revela quais e-mails têm conta) e
 * por rede (o IP). Nada fica em texto: e-mail e IP viram hash.
 *
 *   - 5 erros com o mesmo e-mail em 15 minutos: esse e-mail espera;
 *   - 20 erros da mesma rede em 15 minutos: a rede espera (uma escola
 *     inteira sai pelo mesmo IP, por isso o limite é maior).
 *
 * A espera dura até a falha que completou o limite sair da janela de 15
 * minutos. Tentativas feitas durante a espera não contam (não esticam o
 * bloqueio). Login certo zera os erros daquele e-mail.
 */

const FALHAS_POR_EMAIL = 5;
const FALHAS_POR_REDE = 20;
const JANELA_DE_FALHAS_SEGUNDOS = 900;

/**
 * Hash Argon2id de um valor aleatório descartado. O login confere a senha
 * contra ele quando o e-mail não existe: assim a resposta demora o mesmo
 * tempo com ou sem conta, e o tempo não revela quem está cadastrado.
 */
const HASH_PARA_EMAIL_INEXISTENTE = '$argon2id$v=19$m=19456,t=2,p=1$SS9vY0ZMQ3NQR0JUcXpVQQ$OD1KN610cG6gwNUu9YB51w/crGjUoYZ9TEyOnyWqvgM';

/**
 * Segundos que ainda faltam para poder tentar de novo, ou null (função
 * pura - testada em tests/helpers_test.php).
 *
 * @param int[] $idadesEmail há quantos segundos foi cada falha com o e-mail
 * @param int[] $idadesRede  há quantos segundos foi cada falha da rede
 */
function esperaParaNovoLogin(array $idadesEmail, array $idadesRede): ?int {
    $espera = null;
    foreach ([[$idadesEmail, FALHAS_POR_EMAIL], [$idadesRede, FALHAS_POR_REDE]] as [$idades, $limite]) {
        $naJanela = array_values(array_filter($idades, fn($idade) => $idade >= 0 && $idade < JANELA_DE_FALHAS_SEGUNDOS));
        if (count($naJanela) < $limite) {
            continue;
        }
        sort($naJanela);
        // Libera quando a falha que completou o limite (a $limite-ésima mais nova) sai da janela.
        $faltam = JANELA_DE_FALHAS_SEGUNDOS - $naJanela[$limite - 1];
        $espera = max($espera ?? 0, $faltam);
    }
    return $espera;
}

/** "Muitas tentativas erradas... espere 12 minutos..." */
function mensagemDeEsperaDoLogin(int $segundos): string {
    $minutos = max(1, (int) ceil($segundos / 60));
    return "Muitas tentativas erradas. Por segurança, espere $minutos " . ($minutos === 1 ? 'minuto' : 'minutos') . ' e tente de novo.';
}

function chaveDoEmailDeLogin(string $email): string {
    return hash('sha256', mb_strtolower(trim($email)));
}

function chaveDaRede(string $ip): string {
    // Com a chave do JWT o hash do IP não é revertido por força bruta (são só 2^32 IPv4).
    return defined('JWT_SECRET_KEY') ? hash_hmac('sha256', $ip, JWT_SECRET_KEY) : hash('sha256', $ip);
}

/**
 * IP de quem chamou. Atrás do balanceador do Render (e de outros), o IP
 * real é o último do X-Forwarded-For: o começo do cabeçalho pode ter sido
 * escrito pelo próprio cliente.
 */
function ipDoCliente(): string {
    $encaminhado = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($encaminhado !== '') {
        $partes = array_map('trim', explode(',', $encaminhado));
        $ultimo = end($partes);
        if (is_string($ultimo) && filter_var($ultimo, FILTER_VALIDATE_IP)) {
            return $ultimo;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function esperaDoLogin(PDO $conexao, string $email, string $ip): ?int {
    $stmt = $conexao->prepare("
        SELECT chave_email = ? AS do_email, chave_rede = ? AS da_rede,
               EXTRACT(EPOCH FROM (NOW() - criado_em))::int AS idade
        FROM tb_tentativa_login
        WHERE (chave_email = ? OR chave_rede = ?) AND criado_em > NOW() - make_interval(secs => ?)
    ");
    $chaveEmail = chaveDoEmailDeLogin($email);
    $chaveRede = chaveDaRede($ip);
    $stmt->execute([$chaveEmail, $chaveRede, $chaveEmail, $chaveRede, JANELA_DE_FALHAS_SEGUNDOS]);
    $doEmail = [];
    $daRede = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        if (valorBooleano($linha['do_email'])) $doEmail[] = (int) $linha['idade'];
        if (valorBooleano($linha['da_rede'])) $daRede[] = (int) $linha['idade'];
    }
    return esperaParaNovoLogin($doEmail, $daRede);
}

function registrarFalhaDeLogin(PDO $conexao, string $email, string $ip): void {
    $conexao->prepare("INSERT INTO tb_tentativa_login (chave_email, chave_rede) VALUES (?, ?)")
        ->execute([chaveDoEmailDeLogin($email), chaveDaRede($ip)]);
    // O que saiu da janela não serve para mais nada: a tabela fica pequena.
    $conexao->exec("DELETE FROM tb_tentativa_login WHERE criado_em < NOW() - INTERVAL '1 day'");
}

function limparFalhasDoEmail(PDO $conexao, string $email): void {
    $conexao->prepare("DELETE FROM tb_tentativa_login WHERE chave_email = ?")->execute([chaveDoEmailDeLogin($email)]);
}
