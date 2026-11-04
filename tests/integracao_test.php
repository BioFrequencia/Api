<?php
/**
 * Testes de integração da API: requisições HTTP de verdade contra a API
 * rodando (php -S no CI, ou o container Docker com Apache) e um
 * PostgreSQL de teste com o schema de produção (migrações 001-009).
 * Cada resultado gravado é conferido direto no banco, não só pela
 * resposta.
 *
 * Substitui tests/fluxo_ocorrencias_test.sh, que só rodava no ambiente
 * de quem o escreveu (porta, banco e usuários fixos) e quebrava o CI.
 * Os cenários dele continuam aqui (seção "App").
 *
 * APAGA TODOS OS DADOS do banco apontado. Por isso só roda com
 * BANCO_DE_TESTE=1 e banco local: localhost ou um nome de serviço do
 * Docker sem domínio (ex.: "db" em docker-compose.teste.yml). Endereços
 * do Supabase sempre têm domínio e são recusados.
 *
 * Variáveis (as mesmas usadas para subir a API no CI):
 *   API_URL                 http://127.0.0.1:8080
 *   DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD
 *   JWT_SECRET_KEY          para montar um token expirado
 *   SITE_URL                endereço do site nos links dos e-mails
 *   CORS_ORIGENS            a primeira origem é usada nos testes de CORS
 *   WHATSAPP_LOG, EMAIL_LOG arquivos onde tests/mock_provedores.php registra os envios
 *   EMAIL_API_TOKEN         token que a API manda ao provedor de e-mail (falso)
 *   APACHE=1                liga os testes que dependem do .htaccess
 *
 * Rodar: php tests/integracao_test.php
 */

// Feriados e dias letivos pelas mesmas regras da API (helpers/calendario.php,
// testada à parte em tests/helpers_test.php): no CI o teste roda na raiz do
// repositório; no Docker, ao lado do código da imagem.
require_once is_file(__DIR__ . '/../helpers/calendario.php') ? __DIR__ . '/../helpers/calendario.php' : '/var/www/html/helpers/calendario.php';

$api = rtrim(getenv('API_URL') ?: 'http://127.0.0.1:8080', '/');
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$apache = getenv('APACHE') === '1';
$jwtSecret = (string) getenv('JWT_SECRET_KEY');
$siteUrl = rtrim((string) getenv('SITE_URL'), '/');
$origens = array_map('trim', explode(',', (string) getenv('CORS_ORIGENS')));
$origem = $origens[0] ?? '';
$logWhatsapp = getenv('WHATSAPP_LOG') ?: sys_get_temp_dir() . '/whatsapp.log';
$logEmail = getenv('EMAIL_LOG') ?: sys_get_temp_dir() . '/email.log';

$bancoLocal = in_array($dbHost, ['127.0.0.1', 'localhost'], true) || preg_match('/^[a-z0-9-]+$/i', $dbHost) === 1;
if (getenv('BANCO_DE_TESTE') !== '1' || !$bancoLocal) {
    fwrite(STDERR, "Recusado: este teste apaga o banco. Use BANCO_DE_TESTE=1 com um banco local (localhost ou serviço do Docker).\n");
    exit(2);
}
foreach (['JWT_SECRET_KEY' => $jwtSecret, 'SITE_URL' => $siteUrl, 'CORS_ORIGENS' => $origem] as $nome => $valor) {
    if ($valor === '') {
        fwrite(STDERR, "Defina $nome (o mesmo valor configurado na API).\n");
        exit(2);
    }
}

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'biofrequencia_ci'),
    getenv('DB_USER') ?: 'postgres',
    getenv('DB_PASSWORD') ?: 'postgres',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("SET TIME ZONE 'UTC'");
$fuso = new DateTimeZone('America/Sao_Paulo');

// ------------------------------------------------------------ utilidades
$total = 0;
$falhas = [];

function secao(string $titulo): void {
    echo "\n=== $titulo ===\n";
}

function verificar(string $descricao, bool $ok, $detalhe = null): void {
    global $total, $falhas;
    $total++;
    if ($ok) {
        echo "  ✓ $descricao\n";
        return;
    }
    $falhas[] = $descricao;
    $texto = $detalhe === null ? '' : (is_string($detalhe) ? $detalhe : json_encode($detalhe, JSON_UNESCAPED_UNICODE));
    $texto = mb_substr($texto, 0, 400);
    echo "  ✗ $descricao" . ($texto !== '' ? " -> $texto" : '') . "\n";
    if (getenv('GITHUB_ACTIONS')) {
        // Anotação no resumo do workflow (visível sem abrir o log).
        echo '::error title=Integração::' . str_replace(["\r", "\n", '%'], [' ', ' ', '%25'], "$descricao $texto") . "\n";
    }
}

function req(string $metodo, string $caminho, $corpo = null, ?string $token = null, array $cabecalhos = []): array {
    global $api;
    $ch = curl_init($api . '/' . ltrim($caminho, '/'));
    if ($corpo !== null) {
        $cabecalhos[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $cabecalhos[] = 'Authorization: Bearer ' . $token;
    }
    $recebidos = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$recebidos) {
            $partes = explode(':', $linha, 2);
            if (count($partes) === 2) {
                $recebidos[strtolower(trim($partes[0]))] = trim($partes[1]);
            }
            return strlen($linha);
        },
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($corpo) ? $corpo : json_encode($corpo));
    }
    $texto = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'h' => $recebidos, 'texto' => $texto, 'json' => json_decode($texto, true)];
}

function post(string $caminho, array $corpo = [], ?string $token = null, array $cabecalhos = []): array {
    return req('POST', $caminho, $corpo, $token, $cabecalhos);
}

/** Dispara várias requisições ao mesmo tempo (para testar corrida). */
function paralelo(array $pedidos): array {
    global $api;
    $multi = curl_multi_init();
    $handles = [];
    foreach ($pedidos as $i => [$caminho, $corpo, $token]) {
        $ch = curl_init($api . '/' . $caminho);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => array_filter(['Content-Type: application/json', $token ? "Authorization: Bearer $token" : null]),
            CURLOPT_POSTFIELDS     => json_encode($corpo),
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }
    do {
        curl_multi_exec($multi, $ativos);
        curl_multi_select($multi, 1.0);
    } while ($ativos > 0);
    $respostas = [];
    foreach ($handles as $i => $ch) {
        $texto = (string) curl_multi_getcontent($ch);
        $respostas[$i] = ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($texto, true), 'texto' => $texto];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $respostas;
}

function sql(string $consulta, array $parametros = []): array {
    global $pdo;
    $stmt = $pdo->prepare($consulta);
    $stmt->execute($parametros);
    return $stmt->columnCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function valor(string $consulta, array $parametros = []) {
    $linhas = sql($consulta, $parametros);
    return $linhas ? array_values($linhas[0])[0] : null;
}

/** Expressão SQL da data local (fuso da escola) de uma coluna em UTC. */
function dia(string $coluna): string {
    return "(($coluna) AT TIME ZONE 'UTC' AT TIME ZONE 'America/Sao_Paulo')::date";
}

function jwtHs256(array $payload, string $segredo): string {
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $cabecalho = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $corpo = $b64(json_encode($payload));
    return "$cabecalho.$corpo." . $b64(hash_hmac('sha256', "$cabecalho.$corpo", $segredo, true));
}

/** CPF válido (com dígitos verificadores) diferente para cada $n. */
function gerarCpf(int $n): string {
    $cpf = sprintf('%09d', 123456000 + $n * 37);
    for ($posicao = 9; $posicao <= 10; $posicao++) {
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += (int) $cpf[$i] * (($posicao + 1) - $i);
        }
        $cpf .= (string) (((10 * $soma) % 11) % 10);
    }
    return $cpf;
}

/** Últimos $n dias úteis (Y-m-d, fuso da escola), do mais recente para o mais antigo. */
function ultimosDiasUteis(int $n): array {
    global $fuso;
    $dias = [];
    $dia = new DateTimeImmutable('today', $fuso);
    while (count($dias) < $n) {
        if ((int) $dia->format('N') <= 5) {
            $dias[] = $dia->format('Y-m-d');
        }
        $dia = $dia->modify('-1 day');
    }
    return $dias;
}

function localParaUtcTeste(string $local): string {
    global $fuso;
    return (new DateTimeImmutable($local, $fuso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function assinado(string $uuid, string $segredo, string $tipo, ?string $timestamp = null): array {
    $timestamp = $timestamp ?? gmdate('Y-m-d\TH:i:s') . '.000Z';
    return [
        'device_uuid' => $uuid,
        'tipo'        => $tipo,
        'timestamp'   => $timestamp,
        'assinatura'  => hash_hmac('sha256', "$uuid|$tipo|$timestamp", $segredo),
    ];
}

function enviosWhatsapp(string $numero): array {
    global $logWhatsapp;
    if (!is_file($logWhatsapp)) return [];
    $linhas = array_filter(array_map(fn($l) => json_decode($l, true), file($logWhatsapp, FILE_IGNORE_NEW_LINES)));
    return array_values(array_filter($linhas, fn($l) => ($l['to'] ?? '') === $numero));
}

/** E-mails que o provedor falso recebeu para este endereço, na ordem. */
function emailsPara(string $email): array {
    global $logEmail;
    if (!is_file($logEmail)) return [];
    $linhas = array_filter(array_map(fn($l) => json_decode($l, true), file($logEmail, FILE_IGNORE_NEW_LINES)));
    return array_values(array_filter($linhas, fn($l) => in_array($email, $l['to'] ?? [], true)));
}

/** Código do último link de confirmação mandado para o endereço ('' se nenhum). */
function codigoDoLink(string $email): string {
    foreach (array_reverse(emailsPara($email)) as $mensagem) {
        if (preg_match('/confirmar-email\?codigo=([0-9a-f]{64})/', (string) ($mensagem['texto'] ?? ''), $m)) {
            return $m[1];
        }
    }
    return '';
}

/** Código do último link de senha nova mandado para o endereço ('' se nenhum). */
function codigoDaSenhaNova(string $email): string {
    foreach (array_reverse(emailsPara($email)) as $mensagem) {
        if (preg_match('/redefinir-senha\?codigo=([0-9a-f]{64})/', (string) ($mensagem['texto'] ?? ''), $m)) {
            return $m[1];
        }
    }
    return '';
}

/** E-mails para o endereço cujo assunto contém o trecho. */
function emailsComAssunto(string $email, string $trecho): array {
    return array_values(array_filter(emailsPara($email), fn($m) => str_contains((string) ($m['assunto'] ?? ''), $trecho)));
}

$hoje = (new DateTimeImmutable('today', $fuso))->format('Y-m-d');
$ontem = (new DateTimeImmutable('today', $fuso))->modify('-1 day')->format('Y-m-d');
$inicioMesLocal = (new DateTimeImmutable('first day of this month', $fuso))->format('Y-m-d');

// Dias de referência no fuso da escola, pelo calendário padrão: os últimos
// dias letivos ANTES de hoje (registros com horário de aula que já passou,
// para o teste não depender da hora em que roda), o último sábado e o
// último feriado num dia útil.
$diasLetivos = [];
for ($dia = (new DateTimeImmutable('today', $fuso))->modify('-1 day'); count($diasLetivos) < 6; $dia = $dia->modify('-1 day')) {
    if (situacaoDoDia($dia->format('Y-m-d'))['letivo']) {
        $diasLetivos[] = $dia->format('Y-m-d');
    }
}
[$d1, $d2, $d3, $d4, $d5] = $diasLetivos;
$sabado = (new DateTimeImmutable('last saturday', $fuso))->format('Y-m-d');
$feriado = null;
for ($dia = (new DateTimeImmutable('today', $fuso))->modify('-1 day'); $feriado === null; $dia = $dia->modify('-1 day')) {
    if ((int) $dia->format('N') <= 5 && !situacaoDoDia($dia->format('Y-m-d'))['letivo']) {
        $feriado = $dia->format('Y-m-d');
    }
}

// ------------------------------------------------------------ preparo
secao('Preparo (banco limpo)');
$pdo->exec("
    TRUNCATE tb_presenca, tb_identificacao_biometrica, tb_biometria_digital, tb_consentimento_biometria,
             tb_ocorrencia_pendente, tb_checkin_biometrico, tb_dispositivo_aluno, tb_codigo_vinculo,
             tb_notificacao, tb_alerta_nivel, tb_alerta_encerrado, tb_acompanhamento, tb_verificacao_facial,
             tb_biometria_facial, tb_aluno_responsavel, tb_responsavel, tb_falta, tb_atraso, tb_aluno,
             tb_sala, tb_sessao, tb_preferencia_coordenacao, tb_chamado_suporte, tb_coordenacao,
             tb_saida_antecipada, tb_aviso_limite, tb_calendario_escola, tb_configuracao_escola, tb_catalogo_escolas,
             tb_verificacao_email
             RESTART IDENTITY CASCADE
");
$pdo->exec("DELETE FROM tb_escola WHERE id_escola <> 1");
$pdo->exec("UPDATE tb_escola SET codigo_cie = NULL WHERE id_escola = 1");
@unlink($logWhatsapp);
@unlink($logEmail);
$escola2 = (int) valor("INSERT INTO tb_escola (nome_escola) VALUES ('Escola Vizinha') RETURNING id_escola");
$sala1 = (int) valor("INSERT INTO tb_sala (nome_sala, periodo_sala, id_escola) VALUES ('1A', 'Matutino', 1) RETURNING id_sala");
$sala1b = (int) valor("INSERT INTO tb_sala (nome_sala, periodo_sala, id_escola) VALUES ('1B', 'Vespertino', 1) RETURNING id_sala");
$sala2 = (int) valor("INSERT INTO tb_sala (nome_sala, periodo_sala, id_escola) VALUES ('2A', 'Matutino', ?) RETURNING id_sala", [$escola2]);
echo "  escola 2 = $escola2; turmas 1A=$sala1, 1B=$sala1b, 2A=$sala2\n";

// ------------------------------------------------------------ saúde e CORS
secao('Saúde e CORS');
$r = req('GET', 'health.php');
verificar('health.php: 200 com banco e schema ok', $r['status'] === 200 && ($r['json']['dados']['schema'] ?? '') === 'ok', $r['texto']);

$preflight = fn(string $caminho, string $deOrigem) => req('OPTIONS', $caminho, null, null, [
    "Origin: $deOrigem", 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: authorization, content-type',
]);
$r = $preflight('user/dashboard.php', $origem);
verificar('preflight de origem permitida: 204', $r['status'] === 204, $r['status']);
verificar('preflight devolve Allow-Origin igual à origem (regressão do .htaccess)', ($r['h']['access-control-allow-origin'] ?? null) === $origem, $r['h']);
verificar('preflight libera o cabeçalho Authorization', stripos($r['h']['access-control-allow-headers'] ?? '', 'authorization') !== false, $r['h']);
$r = $preflight('user/dashboard.php', 'https://site-git-ajuste-login-equipe-teste.vercel.app');
verificar('preflight de deploy de preview (padrão com *) é aceito', ($r['h']['access-control-allow-origin'] ?? null) === 'https://site-git-ajuste-login-equipe-teste.vercel.app', $r['h']);
$r = $preflight('user/dashboard.php', 'https://malicioso.example');
verificar('preflight de origem não permitida: sem Allow-Origin', !isset($r['h']['access-control-allow-origin']), $r['h']);
$r = post('user/selectCoord.php', ['email' => 'x@x.com', 'senha' => 'y'], null, ["Origin: $origem"]);
verificar('resposta de erro (401) também leva Allow-Origin', ($r['h']['access-control-allow-origin'] ?? null) === $origem, $r['h']);
if ($apache) {
    $r = post('caminho/que/nao/existe.php', [], null, ["Origin: $origem"]);
    verificar('URL inexistente: 404 em JSON com Allow-Origin (erro.php)', $r['status'] === 404 && ($r['json']['sucesso'] ?? null) === false
        && ($r['h']['access-control-allow-origin'] ?? null) === $origem, [$r['status'], $r['h'], $r['texto']]);
    $r = $preflight('caminho/que/nao/existe.php', $origem);
    verificar('preflight em URL inexistente: 204 com Allow-Origin', $r['status'] === 204 && ($r['h']['access-control-allow-origin'] ?? null) === $origem, [$r['status'], $r['h']]);
    $r = req('GET', 'config/config.php');
    verificar('config/config.php não é servido', in_array($r['status'], [403, 404], true) && !str_contains($r['texto'], 'DB_PASSWORD'), $r['status']);
    $r = req('GET', 'database/migracao-completa.sql');
    verificar('arquivos .sql não são servidos', in_array($r['status'], [403, 404], true), $r['status']);
}

// ------------------------------------------------------------ cadastro e login
secao('Cadastro aberto, confirmação do e-mail e aprovação');
// Sem código de acesso (migração 009): a conta nasce pendente, confirma o
// e-mail pelo link (lido aqui do provedor falso) e espera quem aprova.
$cadastrar = fn(string $email, array $extra = []) => post('user/addCoord.php',
    array_merge(['nome' => 'Coordenação Teste', 'email' => $email, 'senha' => 'Senha@123', 'cargo' => 'coordenacao'], $extra));
$confirmar = fn(string $email) => post('conta/confirmarEmail.php', ['codigo' => codigoDoLink($email)]);
$entrarComo = fn(string $email, string $senha = 'Senha@123') => post('user/selectCoord.php', ['email' => $email, 'senha' => $senha]);
$r = $cadastrar('Coord1@Escola.test');
verificar('cadastro sem código de acesso: 200, pendente e com o e-mail por confirmar', $r['status'] === 200
    && ($r['json']['dados']['situacao'] ?? '') === 'pendente' && ($r['json']['dados']['email_verificado'] ?? null) === false
    && ($r['json']['dados']['email_enviado'] ?? null) === true && ($r['json']['dados']['aprovacao'] ?? '') === 'equipe', $r['texto']);
verificar('email gravado em minúsculas', valor("SELECT email_coordenacao FROM tb_coordenacao WHERE lower(email_coordenacao) = 'coord1@escola.test'") === 'coord1@escola.test');
$emails = emailsPara('coord1@escola.test');
verificar('o link de confirmação sai pelo provedor de e-mail (falso), com o endereço do site', count($emails) === 1
    && str_contains((string) ($emails[0]['texto'] ?? ''), "$siteUrl/confirmar-email?codigo=") && codigoDoLink('coord1@escola.test') !== ''
    && ($emails[0]['auth'] ?? '') === 'Bearer ' . getenv('EMAIL_API_TOKEN') && str_contains((string) ($emails[0]['html'] ?? ''), 'Confirmar o e-mail'), $emails);
verificar('o banco guarda só o hash do código do link', valor("SELECT token_hash FROM tb_verificacao_email ORDER BY id_verificacao DESC LIMIT 1")
    === hash('sha256', codigoDoLink('coord1@escola.test')));
$r = $cadastrar('COORD1@escola.test', ['codigo_acesso' => 'qualquer']);
verificar('mesmo email (com outra caixa) aguardando a confirmação: 409', $r['status'] === 409, $r['texto']);
$r = $cadastrar('coord2@escola.test');
verificar('segunda conta: 200', $r['status'] === 200, $r['texto']);
$r = $cadastrar('fraca@escola.test', ['senha' => 'senha123']);
verificar('cadastro com senha fraca: 422 com a lista do que falta', $r['status'] === 422
    && ($r['json']['dados']['requisitos_senha'] ?? null) === ['uma letra maiúscula', 'um caractere especial (ex.: ! @ # $ %)']
    && str_contains($r['json']['mensagem'] ?? '', 'uma letra maiúscula e um caractere especial'), $r['texto']);
$r = $cadastrar('semcargo@escola.test', ['cargo' => null]);
verificar('cadastro sem cargo: 422', $r['status'] === 422 && str_contains($r['json']['mensagem'] ?? '', 'cargo'), $r['texto']);
$r = $cadastrar('semcargo@escola.test', ['cargo' => 'diretor']);
verificar('cargo fora da lista: 422', $r['status'] === 422, $r['texto']);
verificar('nenhuma das contas recusadas ficou no banco', (int) valor("SELECT count(*) FROM tb_coordenacao WHERE email_coordenacao IN ('fraca@escola.test', 'semcargo@escola.test')") === 0);
$idCoord1 = (int) valor("SELECT id_coordenacao FROM tb_coordenacao WHERE email_coordenacao = 'coord1@escola.test'");
$escolaDaConta = (int) valor("SELECT id_escola FROM tb_coordenacao WHERE id_coordenacao = ?", [$idCoord1]);
verificar('conta nova ganha escola própria (antes caía na escola 1 e via os alunos das outras)', $escolaDaConta !== 1
    && valor("SELECT nome_escola FROM tb_escola WHERE id_escola = ?", [$escolaDaConta]) === 'Escola de Coordenação Teste');
$idCoord2 = (int) valor("SELECT id_coordenacao FROM tb_coordenacao WHERE email_coordenacao = 'coord2@escola.test'");

$r = $entrarComo('coord1@escola.test', 'errada');
verificar('e-mail por confirmar e senha errada: 401 (não revela nada)', $r['status'] === 401, $r['texto']);
$r = $entrarComo('coord1@escola.test');
verificar('e-mail por confirmar não entra: 403 "email_nao_confirmado"', $r['status'] === 403
    && ($r['json']['dados']['situacao'] ?? '') === 'email_nao_confirmado', $r['texto']);
$r = post('conta/reenviarConfirmacao.php', ['email' => 'coord1@escola.test', 'senha' => 'errada']);
verificar('reenviar o link com a senha errada: 401', $r['status'] === 401, $r['texto']);
$r = post('conta/reenviarConfirmacao.php', ['email' => 'coord1@escola.test', 'senha' => 'Senha@123']);
verificar('reenviar logo depois do primeiro link: 429 (espera um minuto)', $r['status'] === 429, $r['texto']);
$codigoAntigo = codigoDoLink('coord1@escola.test');
sql("UPDATE tb_verificacao_email SET criado_em = criado_em - INTERVAL '2 minutes'");
$r = post('conta/reenviarConfirmacao.php', ['email' => 'coord1@escola.test', 'senha' => 'Senha@123']);
verificar('reenviar depois de um minuto: 200 e um link novo', $r['status'] === 200 && count(emailsPara('coord1@escola.test')) === 2
    && codigoDoLink('coord1@escola.test') !== $codigoAntigo, $r['texto']);
$r = post('conta/confirmarEmail.php', ['codigo' => $codigoAntigo]);
verificar('o link antigo vence quando sai um novo: 410', $r['status'] === 410, $r['texto']);
$r = post('conta/confirmarEmail.php', ['codigo' => 'abc']);
verificar('código malformado: 422', $r['status'] === 422, $r['texto']);
$r = post('conta/confirmarEmail.php', ['codigo' => str_repeat('0', 64)]);
verificar('código que não existe: 404', $r['status'] === 404, $r['texto']);
$r = $confirmar('coord1@escola.test');
verificar('o link confirma o e-mail; a conta segue pendente, à espera da equipe do sistema', $r['status'] === 200
    && ($r['json']['dados']['situacao'] ?? '') === 'pendente' && ($r['json']['dados']['aprovacao'] ?? '') === 'equipe'
    && (int) valor("SELECT count(*) FROM tb_coordenacao WHERE id_coordenacao = ? AND email_verificado_em IS NOT NULL", [$idCoord1]) === 1, $r['texto']);
$r = $confirmar('coord1@escola.test');
verificar('abrir o mesmo link de novo (recarregar a página): 200, "já estava confirmado"', $r['status'] === 200
    && str_contains($r['json']['mensagem'] ?? '', 'já estava'), $r['texto']);
$r = $entrarComo('coord1@escola.test');
verificar('confirmado, mas ainda sem aprovação: 403 dizendo que a equipe do sistema aprova', $r['status'] === 403
    && ($r['json']['dados']['situacao'] ?? '') === 'pendente' && str_contains($r['json']['mensagem'] ?? '', 'equipe do BioFrequência'), $r['texto']);
$confirmar('coord2@escola.test');

// A equipe do sistema (ADMIN_EMAILS) se cadastra como qualquer pessoa e,
// ao confirmar o e-mail, já entra: é ela quem aprova os outros.
$r = $cadastrar('admin@escola.test', ['nome_escola' => 'Equipe BioFrequência']);
verificar('conta da equipe do sistema: cadastro como qualquer outra', $r['status'] === 200 && ($r['json']['dados']['situacao'] ?? '') === 'pendente', $r['texto']);
$r = $entrarComo('admin@escola.test');
verificar('...que também não entra antes de confirmar o e-mail (saber o endereço não basta)', $r['status'] === 403
    && ($r['json']['dados']['situacao'] ?? '') === 'email_nao_confirmado', $r['texto']);
$r = $confirmar('admin@escola.test');
verificar('ao confirmar o e-mail, a equipe do sistema fica ativa', $r['status'] === 200 && ($r['json']['dados']['situacao'] ?? '') === 'ativo', $r['texto']);
$r = $entrarComo('admin@escola.test');
$tAdmin = $r['json']['dados']['token'] ?? '';
verificar('login informa que a conta é da equipe do sistema', ($r['json']['dados']['administrador'] ?? null) === true, $r['texto']);
$pedidos = emailsComAssunto('admin@escola.test', 'aguardando aprovação');
verificar('quem confirma o e-mail numa escola sem gestão avisa a equipe, com o link da tela de contas', count($pedidos) === 2
    && str_contains((string) ($pedidos[0]['texto'] ?? ''), "$siteUrl/admin?aba=contas") && str_contains((string) ($pedidos[0]['texto'] ?? ''), 'coord1@escola.test'), $pedidos);
$r = post('admin/resumo.php', [], $tAdmin);
verificar('o contador da equipe soma só os pendentes que já confirmaram o e-mail', ($r['json']['dados']['contas_pendentes'] ?? -1) === 2
    && ($r['json']['dados']['contas_sem_email'] ?? -1) === 0, $r['texto']);
foreach (['coord1' => $idCoord1, 'coord2' => $idCoord2] as $quem => $id) {
    $r = post('admin/atualizarConta.php', ['id_coordenacao' => $id, 'acao' => 'aprovar'], $tAdmin);
    verificar("a equipe aprova $quem, que vira a gestão da escola nova", $r['status'] === 200 && ($r['json']['dados']['situacao'] ?? '') === 'ativo'
        && ($r['json']['dados']['papel'] ?? '') === 'gestor', $r['texto']);
}
$aprovacao = emailsComAssunto('coord1@escola.test', 'aprovada');
verificar('quem é aprovado recebe um e-mail com o link do login', count($aprovacao) === 1
    && str_contains((string) ($aprovacao[0]['texto'] ?? ''), "$siteUrl/login"), $aprovacao);
// Daqui em diante a coordenação 1 usa a escola 1 (a que já existe em
// produção, com as turmas criadas no preparo) e a 2, a escola vizinha.
sql("UPDATE tb_coordenacao SET id_escola = 1 WHERE id_coordenacao = ?", [$idCoord1]);
sql("UPDATE tb_coordenacao SET id_escola = ? WHERE id_coordenacao = ?", [$escola2, $idCoord2]);

$r = post('user/selectCoord.php', ['email' => 'coord1@escola.test', 'senha' => 'errada']);
verificar('login com senha errada: 401', $r['status'] === 401, $r['texto']);
$r = post('user/selectCoord.php', ['email' => ' Coord1@Escola.TEST ', 'senha' => 'Senha@123']);
verificar('login sem diferenciar maiúsculas (teclado do celular): 200', $r['status'] === 200 && !empty($r['json']['dados']['token']), $r['texto']);
$t1 = $r['json']['dados']['token'] ?? '';
$t2 = post('user/selectCoord.php', ['email' => 'coord2@escola.test', 'senha' => 'Senha@123'])['json']['dados']['token'] ?? '';
verificar('login da escola 2: 200', $t2 !== '');

// ------------------------------------------------------------ autenticação
secao('Autenticação');
$r = post('user/listarSalas.php');
verificar('sem token: 401', $r['status'] === 401, $r['texto']);
$r = post('user/listarSalas.php', [], 'nao.e.token');
verificar('token inválido: 401', $r['status'] === 401, $r['texto']);
$r = post('user/listarSalas.php', [], jwtHs256(['iss' => 'biofrequencia-api', 'iat' => time() - 7300, 'exp' => time() - 100, 'sub' => $idCoord1], $jwtSecret));
verificar('token expirado: 401 "Sessão expirada"', $r['status'] === 401 && str_contains($r['json']['mensagem'] ?? '', 'expirada'), $r['texto']);
$r = post('user/listarSalas.php', [], jwtHs256(['iss' => 'biofrequencia-api', 'iat' => time(), 'exp' => time() + 600, 'sub' => $idCoord1], 'outra-chave'));
verificar('token assinado com outra chave: 401', $r['status'] === 401, $r['texto']);

// ------------------------------------------------------------ turmas e alunos
secao('Turmas, alunos e isolamento entre escolas');
$r = post('user/listarSalas.php', [], $t1);
verificar('escola 1 vê só as próprias turmas', array_column($r['json']['dados'] ?? [], 'id_sala') == [$sala1, $sala1b], $r['texto']);
$r = post('user/listarSalas.php', [], $t2);
verificar('escola 2 vê só a própria turma', array_column($r['json']['dados'] ?? [], 'id_sala') == [$sala2], $r['texto']);

$novoAluno = function (string $token, int $sala, string $nome): array {
    return post('user/addaluno.php', ['nome_aluno' => $nome, 'id_sala' => $sala, 'dta_nasc_aluno' => '2010-05-10', 'genero_aluno' => 'Feminino'], $token);
};
$r = $novoAluno($t1, $sala2, 'Aluno Intruso');
verificar('cadastrar aluno numa turma de outra escola: 422', $r['status'] === 422, $r['texto']);
$alunos = [];
foreach (['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Fabio', 'Gabi'] as $nome) {
    $r = $novoAluno($t1, $sala1, "$nome Teste");
    $alunos[$nome] = (int) ($r['json']['dados']['id_aluno'] ?? 0);
}
verificar('7 alunos cadastrados na escola 1', count(array_filter($alunos)) === 7, $alunos);
['Ana' => $ana, 'Bruno' => $bruno, 'Carla' => $carla, 'Diego' => $diego, 'Elisa' => $elisa, 'Fabio' => $fabio, 'Gabi' => $gabi] = $alunos;
$zeca = (int) ($novoAluno($t2, $sala2, 'Zeca Vizinho')['json']['dados']['id_aluno'] ?? 0);
$r = post('user/filtroAluno.php', ['pagina' => 1, 'por_pagina' => 50], $t2);
verificar('escola 2 lista só o próprio aluno', array_column($r['json']['dados']['alunos'] ?? [], 'nome_aluno') === ['Zeca Vizinho'], $r['texto']);
$r = post('user/filtroAluno.php', ['pagina' => 1, 'por_pagina' => 50], $t1);
verificar('escola 1 lista os próprios 7 alunos', ($r['json']['dados']['total_registros'] ?? 0) === 7, $r['texto']);

// ------------------------------------------------------------ entradas e saídas
secao('Entradas atrasadas e saídas antecipadas');
// A turma 1A é do período da manhã: padrão das 07:00 às 12:20, com 10
// minutos de tolerância. Os horários são de dias letivos que já passaram,
// para o teste não depender da hora em que roda.
$atraso = fn(int $aluno, ?string $quando, string $token, array $extra = []) => post('user/marcarAtraso.php', ['id_aluno' => $aluno] + ($quando === null ? [] : ['dta_atraso' => $quando]) + $extra, $token);
$saida = fn(int $aluno, ?string $quando, string $token, array $extra = []) => post('user/marcarSaida.php', ['id_aluno' => $aluno] + ($quando === null ? [] : ['dta_saida' => $quando]) + $extra, $token);

$r = $atraso($ana, "$d1 07:30", $t1, ['motivo_atraso' => 'Ônibus']);
verificar('entrada atrasada às 07:30 de um dia de aula: 200', $r['status'] === 200 && ($r['json']['dados']['id_atraso'] ?? 0) > 0, $r['texto']);
verificar('origem gravada: lançada pela coordenação', valor("SELECT origem FROM tb_atraso WHERE id_atraso = ?", [$r['json']['dados']['id_atraso'] ?? 0]) === 'manual');
$r = $atraso($ana, "$d1 08:15", $t1);
verificar('segunda entrada atrasada no mesmo dia: 409', $r['status'] === 409, $r['texto']);
$r = $atraso($bruno, "$d1 17:00", $t1);
verificar('entrada atrasada às 17h de aluno da manhã: 422 com o horário da turma (caso de produção)', $r['status'] === 422
    && str_contains($r['json']['mensagem'] ?? '', '07:00 às 12:20'), $r['texto']);
$r = $atraso($bruno, "$sabado 08:00", $t1);
verificar('entrada atrasada num sábado: 422', $r['status'] === 422 && str_contains($r['json']['mensagem'] ?? '', 'sábado'), $r['texto']);
$r = $atraso($bruno, "$feriado 08:00", $t1);
verificar('entrada atrasada num feriado: 422 com o nome do feriado', $r['status'] === 422 && str_contains($r['json']['mensagem'] ?? '', 'feriado'), $r['texto']);
$r = $atraso($bruno, "$d1 07:08", $t1);
verificar('chegada dentro da tolerância (07:08): 422, não é atraso', $r['status'] === 422 && str_contains($r['json']['mensagem'] ?? '', 'tolerância'), $r['texto']);
$r = $atraso($bruno, $d1, $t1);
verificar('só a data, sem o horário: 422 pedindo o horário', $r['status'] === 422 && str_contains($r['json']['mensagem'] ?? '', 'horário'), $r['texto']);
$r = $atraso($bruno, '2099-01-05 08:00', $t1);
verificar('data no futuro: 422', $r['status'] === 422, $r['texto']);
$r = $atraso($bruno, 'ontem', $t1);
verificar('data em formato inválido: 422 (antes virava 500)', $r['status'] === 422, $r['texto']);
$r = $atraso($bruno, "$d1 07:30", $t1, ['motivo_atraso' => str_repeat('x', 151)]);
verificar('motivo com mais de 150 caracteres: 422', $r['status'] === 422, $r['texto']);
$r = $atraso($ana, "$d2 07:30", $t2);
verificar('outra escola registrando no aluno: 404', $r['status'] === 404, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana], $t1);
verificar('faltas não são mais registradas: 410 explicando a Sala do Futuro', $r['status'] === 410
    && str_contains($r['json']['mensagem'] ?? '', 'Sala do Futuro'), $r['texto']);

$corrida = paralelo(array_fill(0, 4, ['user/marcarAtraso.php', ['id_aluno' => $bruno, 'dta_atraso' => "$d1 07:40"], $t1]));
$statusCorrida = array_column($corrida, 'status');
sort($statusCorrida);
verificar('4 registros simultâneos: 1 grava e 3 recebem 409', $statusCorrida === [200, 409, 409, 409], $statusCorrida);
verificar('registros simultâneos: uma entrada atrasada só no banco', (int) valor("SELECT count(*) FROM tb_atraso WHERE id_aluno = ?", [$bruno]) === 1);

$r = $saida($ana, "$d1 10:00", $t1, ['motivo_saida' => 'Consulta', 'retirado_por' => 'Maria (mãe)']);
verificar('saída antecipada às 10h: 200', $r['status'] === 200 && ($r['json']['dados']['id_saida'] ?? 0) > 0, $r['texto']);
verificar('quem buscou o aluno fica gravado', valor("SELECT retirado_por FROM tb_saida_antecipada WHERE id_saida = ?", [$r['json']['dados']['id_saida'] ?? 0]) === 'Maria (mãe)');
$r = $saida($ana, "$d1 11:00", $t1);
verificar('segunda saída antecipada no mesmo dia: 409', $r['status'] === 409, $r['texto']);
$r = $saida($bruno, "$d1 12:30", $t1);
verificar('saída depois do fim da aula: 422 (não é antecipada)', $r['status'] === 422, $r['texto']);
$r = $saida($bruno, "$d1 07:05", $t1);
verificar('saída logo depois do início: 200 (a tolerância é só da entrada)', $r['status'] === 200, $r['texto']);
$r = $saida($bruno, "$sabado 09:00", $t1);
verificar('saída antecipada num sábado: 422', $r['status'] === 422, $r['texto']);
$r = $saida($ana, "$d2 10:00", $t1, ['retirado_por' => str_repeat('x', 151)]);
verificar('nome de quem buscou com mais de 150 caracteres: 422', $r['status'] === 422, $r['texto']);

$r = post('registro/listar.php', ['data' => $d1], $t1);
verificar('registros do dia: entradas atrasadas e saídas da escola', ($r['json']['dados']['atrasos'] ?? 0) === 2
    && ($r['json']['dados']['saidas'] ?? 0) === 2 && ($r['json']['dados']['dia']['letivo'] ?? null) === true, $r['texto']);
$r = post('registro/listar.php', ['data' => $sabado], $t1);
verificar('registros de um sábado: o dia vem marcado como sem aula', ($r['json']['dados']['dia']['letivo'] ?? null) === false, $r['texto']);
$r = post('registro/listar.php', ['data' => $d1], $t2);
verificar('outra escola não vê os registros', ($r['json']['dados']['registros'] ?? null) === [], $r['texto']);
$r = post('registro/listar.php', ['data' => $d1, 'id_sala' => $sala2], $t1);
verificar('registros de turma de outra escola: 404', $r['status'] === 404, $r['texto']);

// ------------------------------------------------------------ histórico e contagem
secao('Histórico, lista de alunos e contagem por dia');
$r = post('user/historicoAluno.php', ['id_aluno' => $ana, 'data_inicio' => $d2, 'data_fim' => $hoje], $t1);
verificar('histórico: entradas atrasadas e saídas antecipadas, sem faltas', count($r['json']['dados']['atrasos'] ?? []) === 1
    && count($r['json']['dados']['saidas'] ?? []) === 1 && !isset($r['json']['dados']['faltas']), $r['texto']);
verificar('histórico traz o indicador do aluno (janela, dias de aula, limite)', ($r['json']['dados']['indicador']['atrasos'] ?? null) === 1
    && ($r['json']['dados']['indicador']['limite'] ?? null) === 20 && ($r['json']['dados']['indicador']['janela_dias'] ?? null) === 30, $r['texto']);
verificar('histórico diz quem registrou', ($r['json']['dados']['atrasos'][0]['registrado_por'] ?? '') === 'Coordenação Teste', $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $ana, 'data_inicio' => '2026-13-01'], $t1);
verificar('período inválido: 422', $r['status'] === 422, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $ana, 'data_inicio' => $hoje, 'data_fim' => $d1], $t1);
verificar('início depois do fim: 422', $r['status'] === 422, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $ana], $t2);
verificar('histórico de aluno de outra escola: 404', $r['status'] === 404, $r['texto']);
// Linha duplicada inserida direto no banco (como os 2 atrasos com 26 s de
// diferença que existiam em produção) conta como um dia só; registro antigo
// num sábado não conta em nada.
sql("INSERT INTO tb_atraso (id_aluno, id_coordenacao, dta_atraso) SELECT id_aluno, id_coordenacao, dta_atraso + INTERVAL '1 minute' FROM tb_atraso WHERE id_aluno = ? ORDER BY id_atraso LIMIT 1", [$ana]);
sql("INSERT INTO tb_atraso (id_aluno, id_coordenacao, dta_atraso) VALUES (?, ?, ?)", [$ana, $idCoord1, localParaUtcTeste("$sabado 17:51")]);
$r = post('user/filtroAluno.php', ['busca' => 'Ana Teste', 'pagina' => 1, 'por_pagina' => 10], $t1);
$linhaAna = $r['json']['dados']['alunos'][0] ?? [];
verificar('lista de alunos: entradas atrasadas contam dias de aula distintos (1), não linhas nem sábados', ($linhaAna['atrasos'] ?? null) === 1
    && ($linhaAna['saidas'] ?? null) === 1, $r['texto']);
verificar('lista de alunos: taxa sobre os dias de aula e situação', is_numeric($linhaAna['taxa_atrasos'] ?? null)
    && ($linhaAna['dias_letivos'] ?? 0) > 0 && in_array($linhaAna['status'] ?? '', ['Regular', 'Perto do limite', 'Acima do limite', 'Crítico'], true), $r['texto']);
$r = post('user/filtroAluno.php', ['status' => 'Crítico', 'pagina' => 1, 'por_pagina' => 50], $t1);
verificar('filtro por situação', ($r['json']['sucesso'] ?? false) === true && count(array_filter($r['json']['dados']['alunos'] ?? [], fn($a) => $a['status'] !== 'Crítico')) === 0, $r['texto']);

// ------------------------------------------------------------ responsáveis
secao('Responsáveis');
$cpfA = gerarCpf(1);
$cpfB = gerarCpf(2);
$cpfC = gerarCpf(3);
$addResp = fn(string $token, int $aluno, string $cpf, string $telefone, string $genero = 'Outro', array $extra = []) => post('responsavel/addResponsavel.php', $extra + [
    'id_aluno' => $aluno, 'nome_responsavel' => 'Maria Responsável', 'cpf_responsavel' => $cpf,
    'telefone_responsavel' => $telefone, 'email_responsavel' => null, 'genero_responsavel' => $genero,
], $token);
$r = $addResp($t1, $carla, $cpfA, '(11) 99999-0001', 'Outro', ['parentesco' => 'Mãe']);
$respA = (int) ($r['json']['dados']['id_responsavel'] ?? 0);
verificar('cadastrar responsável com gênero "Outro": 200 (antes: 500 pela restrição do banco)', $r['status'] === 200 && $respA > 0, $r['texto']);
verificar('gênero gravado como "Indefinido" e canal padrão WhatsApp', valor("SELECT genero_responsavel || '|' || canal_preferido FROM tb_responsavel WHERE id_responsavel = ?", [$respA]) === 'Indefinido|whatsapp');
verificar('parentesco gravado no vínculo', valor("SELECT parentesco FROM tb_aluno_responsavel WHERE id_aluno = ? AND id_responsavel = ?", [$carla, $respA]) === 'Mãe');
$r = $addResp($t1, $carla, '123.456.789-00', '(11) 99999-0009');
verificar('CPF com dígito verificador errado: 422', $r['status'] === 422, $r['texto']);
$r = $addResp($t1, $carla, gerarCpf(9), '(11) 99999-0009', 'Feminino', ['canal_preferido' => 'email']);
verificar('aviso por email sem email cadastrado: 422', $r['status'] === 422 && str_contains($r['json']['mensagem'] ?? '', 'email'), $r['texto']);
$r = $addResp($t1, $carla, gerarCpf(9), '(11) 99999-0009', 'Feminino', ['canal_preferido' => 'sms']);
verificar('canal desconhecido: 422', $r['status'] === 422, $r['texto']);
$r = $addResp($t1, $diego, $cpfA, '(11) 99999-0002');
verificar('mesmo CPF para outro aluno da escola: 409 com o id para vincular', $r['status'] === 409 && ($r['json']['dados']['id_responsavel'] ?? null) === $respA, $r['texto']);
$r = post('responsavel/buscarResponsavelPorCpf.php', ['cpf_responsavel' => $cpfA], $t1);
verificar('buscar por CPF na própria escola: encontra, com os alunos que já acompanha', ($r['json']['dados']['id_responsavel'] ?? null) === $respA
    && array_column($r['json']['dados']['alunos'] ?? [], 'nome_aluno') === ['Carla Teste'], $r['texto']);
$r = post('responsavel/vincularResponsavel.php', ['id_aluno' => $diego, 'id_responsavel' => $respA, 'parentesco' => 'Mãe'], $t1);
verificar('vincular o mesmo responsável a outro aluno (irmãos): 200', $r['status'] === 200
    && valor("SELECT parentesco FROM tb_aluno_responsavel WHERE id_aluno = ? AND id_responsavel = ?", [$diego, $respA]) === 'Mãe', $r['texto']);
$r = $addResp($t1, $carla, $cpfB, '(11) 99999-0001');
verificar('telefone já usado por outro responsável: 409 que fala em telefone', $r['status'] === 409 && str_contains($r['json']['mensagem'] ?? '', 'telefone'), $r['texto']);
$r = post('responsavel/buscarResponsavelPorCpf.php', ['cpf_responsavel' => $cpfA], $t2);
verificar('outra escola buscando o CPF: 404 (não vê nome/telefone)', $r['status'] === 404, $r['texto']);
$r = post('responsavel/listarResponsaveis.php', ['id_aluno' => $carla], $t2);
verificar('outra escola listando responsáveis do aluno: 404', $r['status'] === 404, $r['texto']);
$r = post('responsavel/vincularResponsavel.php', ['id_aluno' => $zeca, 'id_responsavel' => $respA], $t2);
verificar('outra escola vinculando responsável alheio ao próprio aluno: 404', $r['status'] === 404, $r['texto']);
$r = post('responsavel/removerVinculo.php', ['id_aluno' => $carla, 'id_responsavel' => $respA], $t2);
verificar('outra escola removendo vínculo: 404', $r['status'] === 404, $r['texto']);
$r = $addResp($t2, $zeca, $cpfA, '(11) 99999-0003');
verificar('CPF que só existe em outra escola: 409 sem dados pessoais', $r['status'] === 409 && ($r['json']['dados'] ?? null) === null, $r['texto']);
$r = post('responsavel/listarResponsaveis.php', ['id_aluno' => $carla], $t1);
verificar('listar responsáveis do aluno: 1, com canal e parentesco', count($r['json']['dados'] ?? []) === 1
    && ($r['json']['dados'][0]['canal_preferido'] ?? '') === 'whatsapp' && ($r['json']['dados'][0]['parentesco'] ?? '') === 'Mãe', $r['texto']);
$respC = (int) ($addResp($t1, $fabio, $cpfC, '(11) 99999-0005')['json']['dados']['id_responsavel'] ?? 0);
$r = post('responsavel/removerVinculo.php', ['id_aluno' => $fabio, 'id_responsavel' => $respC], $t1);
verificar('remover vínculo: 200', $r['status'] === 200, $r['texto']);
$r = $addResp($t2, $zeca, $cpfC, '(11) 99999-0006', 'Feminino');
verificar('CPF sem vínculo nenhum é reaproveitado no novo cadastro', $r['status'] === 200 && ($r['json']['dados']['id_responsavel'] ?? null) === $respC, $r['texto']);

$r = post('responsavel/listarDaEscola.php', [], $t1);
$porResponsavel = array_column($r['json']['dados']['responsaveis'] ?? [], null, 'id_responsavel');
verificar('responsáveis da escola numa chamada só, com os alunos de cada um', count($porResponsavel[$respA]['alunos'] ?? []) === 2
    && !isset($porResponsavel[$respC]), $r['texto']);
verificar('alunos sem responsável aparecem à parte', in_array($ana, array_column($r['json']['dados']['sem_responsavel'] ?? [], 'id_aluno'), true)
    && !in_array($carla, array_column($r['json']['dados']['sem_responsavel'] ?? [], 'id_aluno'), true), $r['texto']);
$r = post('responsavel/listarDaEscola.php', ['busca' => 'carla'], $t1);
verificar('busca pelo nome do aluno acha o responsável', array_column($r['json']['dados']['responsaveis'] ?? [], 'id_responsavel') === [$respA], $r['texto']);
$r = post('responsavel/listarDaEscola.php', [], $t2);
verificar('outra escola só vê os próprios responsáveis', array_column($r['json']['dados']['responsaveis'] ?? [], 'id_responsavel') === [$respC], $r['texto']);
$r = post('responsavel/atualizarResponsavel.php', ['id_responsavel' => $respA, 'telefone_responsavel' => '(11) 99999-0001', 'email_responsavel' => 'Maria@Exemplo.test',
    'canal_preferido' => 'ambos', 'id_aluno' => $diego, 'parentesco' => 'Avó'], $t1);
verificar('editar responsável: email em minúsculas, canal e parentesco de um vínculo', $r['status'] === 200
    && valor("SELECT email_responsavel || '|' || canal_preferido FROM tb_responsavel WHERE id_responsavel = ?", [$respA]) === 'maria@exemplo.test|ambos'
    && valor("SELECT parentesco FROM tb_aluno_responsavel WHERE id_aluno = ? AND id_responsavel = ?", [$diego, $respA]) === 'Avó'
    && valor("SELECT parentesco FROM tb_aluno_responsavel WHERE id_aluno = ? AND id_responsavel = ?", [$carla, $respA]) === 'Mãe', $r['texto']);
$r = post('responsavel/atualizarResponsavel.php', ['id_responsavel' => $respA, 'nome_responsavel' => 'Invasora'], $t2);
verificar('outra escola editando responsável: 404', $r['status'] === 404, $r['texto']);
$r = post('responsavel/atualizarResponsavel.php', ['id_responsavel' => $respA, 'telefone_responsavel' => '123'], $t1);
verificar('editar com telefone inválido: 422', $r['status'] === 422, $r['texto']);
post('responsavel/atualizarResponsavel.php', ['id_responsavel' => $respA, 'canal_preferido' => 'whatsapp'], $t1);

// ------------------------------------------------------------ avisos a cada registro
secao('Avisos aos responsáveis a cada registro');
$r = post('escola/salvarConfiguracao.php', ['aviso_cada_registro' => true], $t1);
verificar('a gestão liga o aviso a cada registro', $r['status'] === 200 && ($r['json']['dados']['aviso_cada_registro'] ?? null) === true, $r['texto']);
// Carla tem a responsável A (WhatsApp) e a E (só e-mail): os dois avisos
// saem pelos provedores falsos.
$respE = (int) ($addResp($t1, $carla, gerarCpf(5), '(11) 97777-0055', 'Feminino', ['email_responsavel' => 'resp.e@exemplo.test', 'canal_preferido' => 'email'])['json']['dados']['id_responsavel'] ?? 0);
$r = $saida($carla, "$d1 10:30", $t1, ['motivo_saida' => 'Dentista', 'retirado_por' => 'Tia Rosa']);
verificar('saída com os avisos ligados: a resposta diz o que saiu', $r['status'] === 200
    && ($r['json']['dados']['avisos']['registro']['enviados'] ?? null) === 2 && ($r['json']['dados']['avisos']['registro']['na_fila'] ?? null) === 0, $r['texto']);
$enviosCarla = array_values(array_filter(enviosWhatsapp('5511999990001'), fn($e) => str_contains((string) ($e['texto'] ?? ''), 'Carla Teste')));
verificar('o provedor recebeu a mensagem com data, hora e quem buscou', count($enviosCarla) === 1
    && str_contains($enviosCarla[0]['texto'], '10:30') && str_contains($enviosCarla[0]['texto'], 'Tia Rosa'), $enviosCarla);
$emailsResp = array_values(array_filter(emailsPara('resp.e@exemplo.test'), fn($m) => str_contains((string) ($m['texto'] ?? ''), 'Carla Teste')));
verificar('o provedor de e-mail recebeu o aviso, com quem buscou', count($emailsResp) === 1
    && str_contains((string) $emailsResp[0]['texto'], 'Tia Rosa'), $emailsResp);
verificar('WhatsApp e e-mail registrados como "Enviado"',
    valor("SELECT status_notificacao FROM tb_notificacao WHERE id_aluno = ? AND canal = 'whatsapp' AND tipo_aviso = 'registro_saida'", [$carla]) === 'Enviado'
    && valor("SELECT status_notificacao FROM tb_notificacao WHERE id_aluno = ? AND canal = 'email' AND tipo_aviso = 'registro_saida'", [$carla]) === 'Enviado');
// Sem provedor (escola que não configurou nenhum), a mensagem fica na fila,
// "Pendente", e a coordenação manda e marca à mão. Com os dois provedores
// falsos ligados aqui, ela entra direto no banco.
$idNotificacao = (int) valor("
    INSERT INTO tb_notificacao (id_aluno, id_responsavel, mensagem, status_notificacao, canal, tipo_aviso)
    VALUES (?, ?, 'Aviso na fila manual', 'Pendente', 'email', 'registro_saida') RETURNING id_notificacao", [$carla, $respE]);
$r = post('notificacao/listarNotificacoes.php', ['status' => 'pendente'], $t1);
$naFila = array_values(array_filter($r['json']['dados']['notificacoes'] ?? [], fn($n) => $n['id_notificacao'] === $idNotificacao));
verificar('listar pendentes: a mensagem da fila aparece como "pendente"', count($naFila) === 1
    && ($naFila[0]['status_notificacao'] ?? '') === 'pendente' && ($naFila[0]['tipo_aviso'] ?? '') === 'registro_saida', $r['texto']);
$r = post('notificacao/listarNotificacoes.php', [], $t2);
verificar('outra escola não vê as notificações', ($r['json']['dados']['total_registros'] ?? -1) === 0, $r['texto']);
$r = post('notificacao/marcarEnviada.php', ['id_notificacao' => $idNotificacao], $t2);
verificar('outra escola marcando como enviada: 404', $r['status'] === 404, $r['texto']);
$r = post('notificacao/marcarEnviada.php', ['id_notificacao' => $idNotificacao], $t1);
verificar('marcar como enviada: 200 e "Enviado" no banco', $r['status'] === 200
    && valor("SELECT status_notificacao FROM tb_notificacao WHERE id_notificacao = ?", [$idNotificacao]) === 'Enviado', $r['texto']);
$r = post('notificacao/listarNotificacoes.php', ['status' => 'enviada'], $t1);
verificar('filtro "enviada" traz a notificação', in_array($idNotificacao, array_column($r['json']['dados']['notificacoes'] ?? [], 'id_notificacao'), true), $r['texto']);
$r = post('notificacao/marcarEnviada.php', ['id_notificacao' => $idNotificacao], $t1);
verificar('marcar de novo: 404 (já enviada)', $r['status'] === 404, $r['texto']);
$r = post('notificacao/listarNotificacoes.php', ['status' => 'lida'], $t1);
verificar('status desconhecido no filtro: 422', $r['status'] === 422, $r['texto']);
post('escola/salvarConfiguracao.php', ['aviso_cada_registro' => false], $t1);
$antes = (int) valor("SELECT count(*) FROM tb_notificacao WHERE id_aluno = ?", [$carla]);
$atraso($carla, "$d2 07:45", $t1);
verificar('com o aviso a cada registro desligado (padrão), nenhuma mensagem nova', (int) valor("SELECT count(*) FROM tb_notificacao WHERE id_aluno = ?", [$carla]) === $antes);

// ------------------------------------------------------------ alertas e aviso por limite
secao('Alertas e aviso automático por limite');
// Diego (responsável A, WhatsApp 11 99999-0001) e Gabi (responsável cujo
// número simula falha do provedor): entradas atrasadas nos últimos 5 dias
// letivos, inseridas direto no banco (como os registros que já existiam).
// Os dois foram cadastrados hoje: os dias de aula deles contam a partir
// do primeiro registro, e 5 em 5 dias passa do dobro do limite (alta).
$respG = (int) ($addResp($t1, $gabi, gerarCpf(4), '(11) 98888-0000')['json']['dados']['id_responsavel'] ?? 0);
foreach ([$diego, $gabi] as $aluno) {
    foreach ([$d1, $d2, $d3, $d4, $d5] as $dia) {
        sql("INSERT INTO tb_atraso (id_aluno, id_coordenacao, dta_atraso) VALUES (?, ?, ?)", [$aluno, $idCoord1, localParaUtcTeste("$dia 07:30")]);
    }
    sql("INSERT INTO tb_alerta_nivel (id_aluno, nivel, motivo_nivel, atualizado_em) VALUES (?, 3, 'teste', NOW() - INTERVAL '8 days')", [$aluno]);
}
// Fábio não tem alerta nenhum, mas tem um nível antigo esquecido.
sql("INSERT INTO tb_alerta_nivel (id_aluno, nivel, motivo_nivel, atualizado_em) VALUES (?, 3, 'antigo', NOW() - INTERVAL '30 days')", [$fabio]);

$respostas = paralelo(array_fill(0, 3, ['user/alertas.php', [], $t1]));
verificar('3 aberturas simultâneas da tela de alertas: todas 200', array_column($respostas, 'status') === [200, 200, 200], array_column($respostas, 'texto'));
$avisosDiego = 0;
foreach ($respostas as $resposta) {
    foreach ($resposta['json']['dados']['avisos'] ?? [] as $aviso) {
        if ($aviso['id_aluno'] === $diego) $avisosDiego++;
    }
}
verificar('o aviso do Diego aparece em UMA resposta só (trava antes do envio)', $avisosDiego === 1, $avisosDiego);
$alertaDiego = array_values(array_filter($respostas[0]['json']['dados']['alertas'] ?? [], fn($a) => $a['id_aluno'] === $diego))[0] ?? [];
verificar('alerta de entradas atrasadas com ocorrências, dias de aula e taxa', ($alertaDiego['tipo'] ?? '') === 'atrasos'
    && ($alertaDiego['prioridade'] ?? '') === 'alta' && ($alertaDiego['ocorrencias'] ?? 0) === 5 && ($alertaDiego['taxa'] ?? 0) >= 40
    && str_contains($alertaDiego['motivo'] ?? '', '5 entradas atrasadas'), $alertaDiego);
verificar('nível 4 gravado para o Diego (alto há mais de 7 dias)', (int) valor("SELECT nivel FROM tb_alerta_nivel WHERE id_aluno = ?", [$diego]) === 4);
$enviosDiego = array_values(array_filter(enviosWhatsapp('5511999990001'), fn($e) => str_contains((string) ($e['texto'] ?? ''), 'Diego Teste')));
verificar('o provedor recebeu exatamente 1 aviso para a família do Diego', count($enviosDiego) === 1, $enviosDiego);
verificar('o aviso fala em entradas atrasadas e dias de aula (não em faltas)', str_contains($enviosDiego[0]['texto'] ?? '', 'entradas atrasadas em')
    && str_contains($enviosDiego[0]['texto'] ?? '', 'dias de aula') && !str_contains($enviosDiego[0]['texto'] ?? '', 'falta'), $enviosDiego);
verificar('envio autenticado com o token do provedor', ($enviosDiego[0]['auth'] ?? '') === 'Bearer ' . getenv('WHATSAPP_TOKEN'), $enviosDiego);
verificar('aviso registrado como "Enviado", do tipo limite',
    valor("SELECT status_notificacao FROM tb_notificacao WHERE id_aluno = ? AND tipo_aviso = 'limite_atrasos'", [$diego]) === 'Enviado');
verificar('falha do provedor fica registrada como "Erro" (Gabi)',
    valor("SELECT status_notificacao FROM tb_notificacao WHERE id_aluno = ? AND tipo_aviso = 'limite_atrasos'", [$gabi]) === 'Erro');
verificar('nível antigo do Fábio (sem alerta) foi zerado', (int) valor("SELECT nivel FROM tb_alerta_nivel WHERE id_aluno = ?", [$fabio]) === 0);
post('user/alertas.php', [], $t1);
verificar('abrir os alertas de novo não reenvia (um aviso por intervalo)', (int) valor("SELECT count(*) FROM tb_notificacao WHERE id_aluno = ? AND tipo_aviso = 'limite_atrasos'", [$diego]) === 1);
$r = post('user/alertas.php', ['id_sala' => $sala1], $t1);
$alertasDiego = array_filter($r['json']['dados']['alertas'] ?? [], fn($a) => $a['id_aluno'] === $diego);
verificar('filtro por turma (id_sala) mantém os alertas do Diego, com nível 4', count($alertasDiego) > 0 && reset($alertasDiego)['nivel'] === 4, $r['texto']);
$r = post('user/alertas.php', ['id_sala' => $sala1b], $t1);
verificar('filtro por outra turma não traz o Diego', count(array_filter($r['json']['dados']['alertas'] ?? [], fn($a) => $a['id_aluno'] === $diego)) === 0, $r['texto']);
$r = post('user/alertas.php', ['tipo' => 'saidas'], $t1);
verificar('filtro por tipo: saídas antecipadas', count(array_filter($r['json']['dados']['alertas'] ?? [], fn($a) => $a['tipo'] !== 'saidas')) === 0, $r['texto']);
$r = post('user/alertas.php', [], $t2);
verificar('outra escola não vê os alertas', ($r['json']['dados']['total'] ?? -1) === 0, $r['texto']);
$r = post('user/encerrarAlerta.php', ['id_aluno' => $diego], $t2);
verificar('outra escola encerrando alerta: 404', $r['status'] === 404, $r['texto']);
$r = post('user/encerrarAlerta.php', ['id_aluno' => $diego], $t1);
verificar('encerrar alerta: 200', $r['status'] === 200, $r['texto']);
$r = post('user/alertas.php', [], $t1);
verificar('alerta encerrado some da lista', count(array_filter($r['json']['dados']['alertas'] ?? [], fn($a) => $a['id_aluno'] === $diego)) === 0, $r['texto']);

// ------------------------------------------------------------ dashboard
secao('Dashboard');
$r = post('user/dashboard.php', [], $t2);
verificar('dashboard da escola 2 lista só a turma dela', array_column($r['json']['dados']['turmas'] ?? [], 'nome_sala') === ['2A'], $r['texto']);
verificar('escola 2 sem nenhum registro: evolução vazia (nada de meses inventados) e 0 atrasos',
    ($r['json']['dados']['evolucao_mensal'] ?? null) === [] && ($r['json']['dados']['atrasos_mes'] ?? null) === 0, $r['texto']);
$r = post('user/dashboard.php', [], $t1);
$painel = $r['json']['dados'] ?? [];
verificar('dashboard da escola 1 lista 1A e 1B', array_column($painel['turmas'] ?? [], 'nome_sala') === ['1A', '1B'], $r['texto']);
verificar('evolução: no máximo 6 meses, terminando no mês atual', count($painel['evolucao_mensal'] ?? []) >= 1 && count($painel['evolucao_mensal']) <= 6
    && end($painel['evolucao_mensal'])['mes'] === $inicioMesLocal, $r['texto']);
$pontualidadeEsperada = ($painel['aluno_dias_mes'] ?? 0) > 0
    ? round(100 - round(100 * min($painel['atrasos_mes'], $painel['aluno_dias_mes']) / $painel['aluno_dias_mes'], 1), 1) : null;
verificar('pontualidade = dias de aula sem entrada atrasada / dias de aula dos alunos (null sem dia de aula)',
    array_key_exists('pontualidade', $painel) && $painel['pontualidade'] == $pontualidadeEsperada, [$painel['pontualidade'] ?? '?', $pontualidadeEsperada]);
verificar('registro antigo num sábado não entra nas contas, mas é informado', ($painel['fora_do_calendario'] ?? 0) >= ($sabado >= (new DateTimeImmutable('first day of -5 months', $fuso))->format('Y-m-01') ? 1 : 0), $r['texto']);
verificar('alunos acima do limite e famílias avisadas no mês', ($painel['alunos_acima_limite'] ?? 0) >= 1 && ($painel['responsaveis_avisados_mes'] ?? 0) >= 2, $r['texto']);
verificar('o limite da escola vem junto (para o gráfico e o texto)', ($painel['limite_alerta'] ?? null) === 20, $r['texto']);

// ------------------------------------------------------------ catálogo e equipe
secao('Catálogo de escolas, cadastro pela escola e aprovação da gestão');
$novaConta = fn(string $email, array $escola) => post('user/addCoord.php',
    array_merge(['nome' => 'Coordenação ' . strtok($email, '@'), 'email' => $email, 'senha' => 'Senha@123', 'cargo' => 'coordenacao'], $escola));
$idDe = fn(string $email) => (int) valor("SELECT id_coordenacao FROM tb_coordenacao WHERE email_coordenacao = ?", [$email]);
$r = post('user/selectCoord.php', ['email' => 'coord1@escola.test', 'senha' => 'Senha@123']);
verificar('login de conta comum: papel na escola e sem acesso de equipe', ($r['json']['dados']['papel'] ?? '') === 'gestor'
    && ($r['json']['dados']['administrador'] ?? null) === false, $r['texto']);
$r = post('admin/importarCatalogo.php', [], $t1);
verificar('conta comum na área da equipe: 404 (a área nem aparece)', $r['status'] === 404, $r['texto']);
$r = post('admin/importarCatalogo.php', [], $tAdmin);
verificar('a equipe importa o catálogo da SEDUC: 5.795 escolas', $r['status'] === 200 && ($r['json']['dados']['total'] ?? 0) === 5795, $r['texto']);
verificar('a escola 1 (cadastrada à mão) foi reconhecida pelo nome e ganhou o código CIE, sem duplicar',
    (int) valor("SELECT codigo_cie FROM tb_escola WHERE id_escola = 1") === 11808 && (int) valor("SELECT count(*) FROM tb_escola WHERE codigo_cie = 11808") === 1);
verificar('turmas e alunos da escola 1 continuam nela', (int) valor("SELECT count(*) FROM tb_sala WHERE id_escola = 1") === 2);
$r = post('admin/importarCatalogo.php', [], $tAdmin);
verificar('importar de novo atualiza, sem duplicar', ($r['json']['dados']['inseridas'] ?? -1) === 0
    && (int) valor("SELECT count(*) FROM tb_escola WHERE codigo_cie IS NOT NULL") === 5795, $r['texto']);

$r = post('escola/buscar.php', ['busca' => 'maria nilde']);
verificar('busca pública no catálogo (sem login): acha a escola 1, que já tem gestão', ($r['json']['dados']['escolas'][0]['id_escola'] ?? 0) === 1
    && ($r['json']['dados']['escolas'][0]['tem_gestao'] ?? null) === true, $r['texto']);
$r = post('escola/buscar.php', ['busca' => 'São Bernardo  mascellani']);
verificar('busca sem acento, juntando município e nome', in_array(1, array_column($r['json']['dados']['escolas'] ?? [], 'id_escola'), true), $r['texto']);
$r = post('escola/buscar.php', ['busca' => '11808']);
verificar('busca pelo código CIE', array_column($r['json']['dados']['escolas'] ?? [], 'codigo_cie') === [11808], $r['texto']);
$r = post('escola/buscar.php', ['busca' => 'ab']);
verificar('busca com menos de 3 letras: 422', $r['status'] === 422, $r['texto']);
$r = post('escola/buscar.php', ['busca' => 'Escola Vizinha']);
verificar('escola criada à mão por outra conta não aparece na busca', ($r['json']['dados']['escolas'] ?? null) === [], $r['texto']);

$escolaHelen = post('escola/buscar.php', ['busca' => '30806'])['json']['dados']['escolas'][0] ?? [];
$idHelen = (int) ($escolaHelen['id_escola'] ?? 0);
verificar('escola do catálogo ainda sem ninguém, com o nome de exibição', $idHelen > 0 && ($escolaHelen['tem_gestao'] ?? null) === false
    && ($escolaHelen['nome_escola'] ?? '') === 'E.E. Helen Keller', $escolaHelen);
$r = $novaConta('coord3@escola.test', ['id_escola' => $idHelen]);
verificar('primeira conta da escola: pendente, e quem aprova é a equipe do sistema', $r['status'] === 200
    && ($r['json']['dados']['situacao'] ?? '') === 'pendente' && ($r['json']['dados']['aprovacao'] ?? '') === 'equipe', $r['texto']);
$idCoord3 = $idDe('coord3@escola.test');
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord3, 'acao' => 'aprovar'], $tAdmin);
verificar('nem a equipe aprova quem ainda não confirmou o e-mail: 409', $r['status'] === 409 && str_contains($r['json']['mensagem'] ?? '', 'e-mail'), $r['texto']);
$confirmar('coord3@escola.test');
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord3, 'acao' => 'aprovar'], $tAdmin);
verificar('e-mail confirmado: a equipe aprova e a primeira conta da escola vira a gestão', $r['status'] === 200
    && ($r['json']['dados']['papel'] ?? '') === 'gestor' && ($r['json']['dados']['situacao'] ?? '') === 'ativo', $r['texto']);
$r = $novaConta('coord4@escola.test', ['id_escola' => $idHelen]);
verificar('segunda conta da mesma escola: pendente, aguardando a gestão', ($r['json']['dados']['situacao'] ?? '') === 'pendente'
    && ($r['json']['dados']['papel'] ?? '') === 'coordenador' && ($r['json']['dados']['aprovacao'] ?? '') === 'gestao', $r['texto']);
$idCoord4 = $idDe('coord4@escola.test');
$t3 = $entrarComo('coord3@escola.test')['json']['dados']['token'] ?? '';
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idCoord4, 'acao' => 'aprovar'], $t3);
verificar('a gestão também não aprova antes da confirmação do e-mail: 409', $r['status'] === 409, $r['texto']);
$r = post('escola/equipe.php', [], $t3);
verificar('quem não confirmou o e-mail aparece na equipe, mas não conta no menu', ($r['json']['dados']['pendentes'] ?? -1) === 0
    && in_array('coord4@escola.test', array_column($r['json']['dados']['equipe'] ?? [], 'email_coordenacao'), true), $r['texto']);
$r = $confirmar('coord4@escola.test');
verificar('confirmado o e-mail numa escola com gestão: quem aprova é a gestão', ($r['json']['dados']['aprovacao'] ?? '') === 'gestao', $r['texto']);
$pedido = emailsComAssunto('coord3@escola.test', 'aguardando aprovação');
verificar('...e a gestão recebe o pedido por e-mail, com o link da equipe da escola', count($pedido) === 1
    && str_contains((string) ($pedido[0]['texto'] ?? ''), "$siteUrl/escola?aba=equipe") && str_contains((string) ($pedido[0]['texto'] ?? ''), 'coord4@escola.test'), $pedido);
$r = $novaConta('coord5@escola.test', ['id_escola' => 999999]);
verificar('escola que não existe no catálogo: 404', $r['status'] === 404, $r['texto']);
$r = $novaConta('coord5@escola.test', ['id_escola' => $escola2]);
verificar('escola criada à mão não pode ser escolhida no cadastro: 404', $r['status'] === 404, $r['texto']);
$r = post('user/selectCoord.php', ['email' => 'coord4@escola.test', 'senha' => 'errada']);
verificar('conta pendente com senha errada: 401 (não revela a situação)', $r['status'] === 401, $r['texto']);
$r = post('user/selectCoord.php', ['email' => 'coord4@escola.test', 'senha' => 'Senha@123']);
verificar('conta pendente não entra: 403 explicando que a gestão aprova', $r['status'] === 403 && str_contains($r['json']['mensagem'] ?? '', 'aprovação da gestão'), $r['texto']);
$r = post('user/selectCoord.php', ['email' => 'coord3@escola.test', 'senha' => 'Senha@123', 'cliente' => 'app']);
$t3 = $r['json']['dados']['token'] ?? '';
$payload = json_decode((string) base64_decode(strtr(explode('.', $t3 . '..')[1], '-_', '+/')), true) ?? [];
verificar('login da gestora: escola do catálogo, papel e, no app, sessão de 12 horas', ($r['json']['dados']['nome_escola'] ?? '') === 'E.E. Helen Keller'
    && ($r['json']['dados']['papel'] ?? '') === 'gestor' && (($payload['exp'] ?? 0) - ($payload['iat'] ?? 0)) === 43200, $r['texto']);
$idCoord3 = (int) valor("SELECT id_coordenacao FROM tb_coordenacao WHERE email_coordenacao = 'coord3@escola.test'");
$idCoord4 = (int) valor("SELECT id_coordenacao FROM tb_coordenacao WHERE email_coordenacao = 'coord4@escola.test'");
$r = post('escola/equipe.php', [], $t3);
verificar('a gestão vê quem aguarda aprovação', ($r['json']['dados']['pendentes'] ?? 0) === 1, $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idCoord4, 'acao' => 'aprovar'], $t1);
verificar('gestão de outra escola não aprova: 404', $r['status'] === 404, $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idCoord4, 'acao' => 'aprovar'], $t3);
verificar('a gestão aprova a conta pendente', $r['status'] === 200 && ($r['json']['dados']['situacao'] ?? '') === 'ativo', $r['texto']);
$r = post('user/selectCoord.php', ['email' => 'coord4@escola.test', 'senha' => 'Senha@123']);
$t4 = $r['json']['dados']['token'] ?? '';
verificar('depois de aprovada, a conta entra como coordenadora', $r['status'] === 200 && ($r['json']['dados']['papel'] ?? '') === 'coordenador', $r['texto']);
$r = post('escola/salvarConfiguracao.php', ['tolerancia_minutos' => 5], $t4);
verificar('coordenador(a) não altera a configuração da escola: 403', $r['status'] === 403, $r['texto']);
$r = post('escola/equipe.php', [], $t4);
verificar('coordenador(a) vê só a equipe ativa, sem os emails', count($r['json']['dados']['equipe'] ?? []) === 2
    && !isset($r['json']['dados']['equipe'][0]['email_coordenacao']), $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idCoord3, 'acao' => 'tornar_coordenador'], $t3);
verificar('a única gestora não pode deixar de ser gestora: 409', $r['status'] === 409, $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idCoord4, 'acao' => 'desativar'], $t3);
verificar('a gestão desativa uma conta', $r['status'] === 200, $r['texto']);
$r = post('user/listarSalas.php', [], $t4);
verificar('a conta desativada perde a sessão na hora: 401', $r['status'] === 401, $r['texto']);
$r = post('user/selectCoord.php', ['email' => 'coord4@escola.test', 'senha' => 'Senha@123']);
verificar('conta desativada não entra: 403', $r['status'] === 403, $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idCoord4, 'acao' => 'reativar'], $t3);
verificar('a gestão reativa a conta', $r['status'] === 200, $r['texto']);
$t4 = post('user/selectCoord.php', ['email' => 'coord4@escola.test', 'senha' => 'Senha@123'])['json']['dados']['token'] ?? '';
// Cargo e permissão (migração 010): a diretora que entra numa escola que já
// tem gestão era aprovada como Coordenação. Agora quem aprova escolhe, com
// a permissão sugerida pelo cargo.
$r = $novaConta('diretora@escola.test', ['id_escola' => $idHelen, 'cargo' => 'direcao']);
verificar('cadastro da diretora numa escola com gestão: Gestão prevista pelo cargo', ($r['json']['dados']['papel'] ?? '') === 'gestor'
    && ($r['json']['dados']['cargo'] ?? '') === 'direcao' && ($r['json']['dados']['aprovacao'] ?? '') === 'gestao', $r['texto']);
$confirmar('diretora@escola.test');
$idDiretora = $idDe('diretora@escola.test');
$pedidoDiretora = emailsComAssunto('coord3@escola.test', 'aguardando aprovação');
verificar('o pedido que chega para a gestão diz o cargo', str_contains((string) (end($pedidoDiretora)['texto'] ?? ''), 'como Direção'), $pedidoDiretora);
$r = post('escola/equipe.php', [], $t3);
$diretoraNaLista = array_values(array_filter($r['json']['dados']['equipe'] ?? [], fn($m) => ($m['email_coordenacao'] ?? '') === 'diretora@escola.test'))[0] ?? [];
verificar('a equipe mostra o cargo de quem pediu acesso', ($diretoraNaLista['cargo'] ?? '') === 'direcao', $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idDiretora, 'acao' => 'aprovar', 'papel' => 'chefe'], $t3);
verificar('permissão inválida na aprovação: 422', $r['status'] === 422, $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idDiretora, 'acao' => 'aprovar'], $t3);
verificar('diretora aprovada sem escolha explícita: Gestão, mesmo com a escola já tendo gestão', $r['status'] === 200
    && ($r['json']['dados']['papel'] ?? '') === 'gestor' && ($r['json']['dados']['situacao'] ?? '') === 'ativo', $r['texto']);
$novaConta('secretaria@escola.test', ['id_escola' => $idHelen, 'cargo' => 'secretaria']);
$confirmar('secretaria@escola.test');
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idDe('secretaria@escola.test'), 'acao' => 'aprovar', 'papel' => 'gestor'], $t3);
verificar('quem aprova escolhe Gestão para a secretaria', ($r['json']['dados']['papel'] ?? '') === 'gestor', $r['texto']);
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idDe('secretaria@escola.test'), 'acao' => 'tornar_coordenador'], $t3);
verificar('com mais de uma gestão, uma pode voltar a ser Coordenação', ($r['json']['dados']['papel'] ?? '') === 'coordenador', $r['texto']);
// A escola volta a ter só uma gestão (coord3), como os testes seguintes esperam.
$r = post('escola/atualizarMembro.php', ['id_coordenacao' => $idDiretora, 'acao' => 'tornar_coordenador'], $t3);
verificar('...e a diretora também (a escola segue com a gestão de coord3)', ($r['json']['dados']['papel'] ?? '') === 'coordenador', $r['texto']);
$r = $novaConta('coord6@escola.test', ['nome_escola' => 'Escola Municipal Seis', 'municipio' => 'Santo André']);
verificar('escola fora do catálogo: a conta cria a escola (com o município)', ($r['json']['dados']['aprovacao'] ?? '') === 'equipe'
    && valor("SELECT municipio FROM tb_escola WHERE nome_escola = 'Escola Municipal Seis'") === 'SANTO ANDRÉ', $r['texto']);
$confirmar('coord6@escola.test');
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idDe('coord6@escola.test'), 'acao' => 'aprovar'], $tAdmin);
verificar('...e, aprovada pela equipe, vira a gestão dela', ($r['json']['dados']['papel'] ?? '') === 'gestor', $r['texto']);

// Um e-mail digitado errado (ou de outra pessoa) não fica preso: com o
// link vencido e nunca confirmado, o cadastro novo toma o lugar.
$novaConta('digitado@escola.test', ['id_escola' => $idHelen]);
$idDigitado = $idDe('digitado@escola.test');
$r = $novaConta('digitado@escola.test', ['nome_escola' => 'Escola Certa']);
verificar('e-mail cadastrado com o link ainda valendo: 409', $r['status'] === 409, $r['texto']);
sql("UPDATE tb_verificacao_email SET expira_em = NOW() - INTERVAL '1 minute' WHERE id_coordenacao = ?", [$idDigitado]);
$r = $novaConta('digitado@escola.test', ['nome_escola' => 'Escola Certa']);
verificar('link vencido e nunca confirmado: o cadastro novo toma o lugar do antigo, sem duplicar', $r['status'] === 200
    && (int) valor("SELECT count(*) FROM tb_coordenacao WHERE email_coordenacao = 'digitado@escola.test'") === 1
    && $idDe('digitado@escola.test') === $idDigitado && ($r['json']['dados']['nome_escola'] ?? '') === 'Escola Certa'
    && count(emailsPara('digitado@escola.test')) === 2, $r['texto']);

// ------------------------------------------------------------ configuração da escola
secao('Escola: horários das turmas, tolerância, calendário e aviso por limite');
$r = post('user/listarSalas.php', [], $t3);
verificar('escola nova começa sem turmas (não vê as da escola 1)', ($r['json']['dados'] ?? null) === [], $r['texto']);
$r = post('user/addSala.php', ['nome_sala' => ' 3º  A ', 'periodo_sala' => 'Matutino'], $t3);
$sala3 = (int) ($r['json']['dados']['id_sala'] ?? 0);
verificar('a coordenação cadastra a turma, com o horário padrão do período', $r['status'] === 201 && ($r['json']['dados']['nome_sala'] ?? '') === '3º A'
    && ($r['json']['dados']['horario'] ?? []) === ['inicio' => '07:00', 'fim' => '12:20'], $r['texto']);
$r = post('user/addSala.php', ['nome_sala' => '3º a', 'periodo_sala' => 'Matutino'], $t3);
verificar('turma com o mesmo nome na mesma escola: 409', $r['status'] === 409, $r['texto']);
$r = post('user/addSala.php', ['nome_sala' => '3º N', 'periodo_sala' => 'Noturno', 'horario_inicio' => '19:00', 'horario_fim' => '18:00'], $t3);
verificar('fim da aula antes do início: 422', $r['status'] === 422, $r['texto']);
$r = post('user/addSala.php', ['nome_sala' => '3º N', 'periodo_sala' => 'Madrugada'], $t3);
verificar('período fora do que o banco aceita: 422', $r['status'] === 422, $r['texto']);
$r = post('user/addSala.php', ['nome_sala' => '3º N', 'periodo_sala' => 'Noturno'], $t3);
$sala3n = (int) ($r['json']['dados']['id_sala'] ?? 0);
verificar('turma do noturno (padrão das 19:00 às 23:00)', ($r['json']['dados']['horario'] ?? []) === ['inicio' => '19:00', 'fim' => '23:00'], $r['texto']);
$r = post('user/addSala.php', ['nome_sala' => '3º I', 'periodo_sala' => 'Integral', 'horario_inicio' => '00:00', 'horario_fim' => '23:59'], $t3);
$sala3i = (int) ($r['json']['dados']['id_sala'] ?? 0);
verificar('período Integral (PEI), com horário próprio', $r['status'] === 201 && $sala3i > 0, $r['texto']);
$r = post('user/addSala.php', ['nome_sala' => '3º X', 'periodo_sala' => 'Noturno']);
verificar('cadastrar turma sem token: 401', $r['status'] === 401, $r['texto']);
$r = post('user/listarSalas.php', [], $t1);
verificar('as turmas da escola nova não aparecem para a escola 1', !in_array($sala3, array_column($r['json']['dados'] ?? [], 'id_sala'), true), $r['texto']);
$tina = (int) ($novoAluno($t3, $sala3, 'Tina Três')['json']['dados']['id_aluno'] ?? 0);
$nico = (int) ($novoAluno($t3, $sala3n, 'Nico Noite')['json']['dados']['id_aluno'] ?? 0);
$ivo = (int) ($novoAluno($t3, $sala3i, 'Ivo Integral')['json']['dados']['id_aluno'] ?? 0);
$r = post('user/filtroAluno.php', ['pagina' => 1, 'por_pagina' => 50], $t3);
verificar('escola nova lista só os próprios alunos', array_column($r['json']['dados']['alunos'] ?? [], 'nome_aluno') === ['Ivo Integral', 'Nico Noite', 'Tina Três'], $r['texto']);

$r = $atraso($nico, "$d1 22:30", $t3);
$esperadoUtc = (new DateTimeImmutable("$d1 22:30", $fuso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
verificar('entrada atrasada às 22:30 no noturno é gravada em UTC (01:30 do dia seguinte)', $r['status'] === 200
    && valor("SELECT to_char(dta_atraso, 'YYYY-MM-DD HH24:MI:SS') FROM tb_atraso WHERE id_atraso = ?", [$r['json']['dados']['id_atraso'] ?? 0]) === $esperadoUtc, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $nico, 'data_inicio' => $d1, 'data_fim' => $d1], $t3);
verificar('...e aparece no dia local certo', count($r['json']['dados']['atrasos'] ?? []) === 1, $r['texto']);
$r = $atraso($nico, "$d2 08:00", $t3);
verificar('aluno do noturno com "entrada atrasada" de manhã: 422', $r['status'] === 422, $r['texto']);

$r = post('escola/salvarTurma.php', ['id_sala' => $sala3, 'horario_inicio' => '07:30', 'horario_fim' => '12:50'], $t3);
verificar('a gestão muda o horário da turma', $r['status'] === 200 && ($r['json']['dados']['horario'] ?? []) === ['inicio' => '07:30', 'fim' => '12:50'], $r['texto']);
$r = $atraso($tina, "$d1 07:35", $t3);
verificar('com o horário novo (07:30), 07:35 está na tolerância: 422', $r['status'] === 422 && str_contains($r['json']['mensagem'] ?? '', '07:40'), $r['texto']);
$r = post('escola/salvarConfiguracao.php', ['tolerancia_minutos' => 0], $t3);
verificar('a gestão zera a tolerância', ($r['json']['dados']['tolerancia_minutos'] ?? null) === 0, $r['texto']);
$r = $atraso($tina, "$d1 07:35", $t3);
verificar('sem tolerância, 07:35 já é entrada atrasada', $r['status'] === 200, $r['texto']);
$r = post('escola/salvarConfiguracao.php', ['tolerancia_minutos' => 61], $t3);
verificar('tolerância acima de 60 minutos: 422', $r['status'] === 422, $r['texto']);
$r = post('escola/salvarTurma.php', ['id_sala' => $sala1, 'horario_inicio' => '08:00'], $t3);
verificar('a gestão não mexe em turma de outra escola: 404', $r['status'] === 404, $r['texto']);
$r = post('escola/salvarTurma.php', ['id_sala' => $sala3, 'horario_inicio' => '08:00'], $t4);
verificar('coordenador(a) não muda horário de turma: 403', $r['status'] === 403, $r['texto']);

$r = post('escola/adicionarCalendario.php', ['data_inicio' => $d2, 'tipo' => 'sem_aula', 'descricao' => 'Conselho de classe'], $t3);
$idConselho = (int) ($r['json']['dados']['id_calendario'] ?? 0);
verificar('a gestão marca um dia sem aula', $r['status'] === 201 && $idConselho > 0, $r['texto']);
$r = $atraso($tina, "$d2 08:00", $t3);
verificar('no dia sem aula da escola não há entrada atrasada: 422 com o motivo', $r['status'] === 422
    && str_contains($r['json']['mensagem'] ?? '', 'Conselho de classe'), $r['texto']);
$r = post('escola/adicionarCalendario.php', ['data_inicio' => $sabado, 'tipo' => 'aula_extra', 'descricao' => 'Sábado letivo'], $t3);
verificar('a gestão marca um sábado letivo', $r['status'] === 201, $r['texto']);
$r = $atraso($tina, "$sabado 08:00", $t3);
verificar('no sábado letivo a entrada atrasada é aceita', $r['status'] === 200, $r['texto']);
$r = post('escola/adicionarCalendario.php', ['data_inicio' => $d2, 'tipo' => 'feriado', 'descricao' => 'x'], $t3);
verificar('tipo de dia desconhecido: 422', $r['status'] === 422, $r['texto']);
$r = post('escola/adicionarCalendario.php', ['data_inicio' => $d1, 'data_fim' => $d2, 'tipo' => 'sem_aula', 'descricao' => 'x'], $t3);
verificar('data final antes da inicial: 422', $r['status'] === 422, $r['texto']);
$r = post('escola/adicionarCalendario.php', ['data_inicio' => $d2, 'tipo' => 'sem_aula', 'descricao' => 'x'], $t4);
verificar('coordenador(a) não mexe no calendário: 403', $r['status'] === 403, $r['texto']);
$r = post('escola/configuracao.php', ['ano' => (int) substr($d2, 0, 4)], $t3);
verificar('tela da escola: dados do catálogo, turmas com horário, feriados e calendário',
    (int) ($r['json']['dados']['escola']['codigo_cie'] ?? 0) === 30806 && count($r['json']['dados']['turmas'] ?? []) === 3
    && count($r['json']['dados']['feriados'] ?? []) >= 13 && in_array($idConselho, array_column($r['json']['dados']['calendario'] ?? [], 'id_calendario'), true)
    && ($r['json']['dados']['pode_editar'] ?? null) === true, $r['texto']);
$r = post('escola/configuracao.php', [], $t4);
verificar('coordenador(a) vê a tela da escola, sem poder editar', ($r['json']['dados']['pode_editar'] ?? null) === false, $r['texto']);
$r = post('escola/removerCalendario.php', ['id_calendario' => $idConselho], $t1);
verificar('outra escola removendo período do calendário: 404', $r['status'] === 404, $r['texto']);
$r = post('escola/removerCalendario.php', ['id_calendario' => $idConselho], $t3);
verificar('a gestão remove o período', $r['status'] === 200, $r['texto']);

// Aviso por limite logo depois do registro, sem ninguém abrir a tela de
// alertas: a 3ª entrada atrasada da Tina passa do limite.
$addResp($t3, $tina, gerarCpf(7), '(11) 97777-0001', 'Feminino');
$r = $atraso($tina, "$d3 08:00", $t3);
verificar('3ª entrada atrasada passa do limite: o aviso sai na hora', ($r['json']['dados']['avisos']['limite']['atrasos']['enviados'] ?? 0) === 1, $r['texto']);
verificar('o provedor recebeu o aviso da Tina', count(array_filter(enviosWhatsapp('5511977770001'), fn($e) => str_contains((string) ($e['texto'] ?? ''), 'Tina Três'))) === 1);
$r = $saida($tina, "$d3 10:00", $t3);
verificar('o registro seguinte, dentro do intervalo, não repete o aviso', ($r['json']['dados']['avisos']['limite']['atrasos']['ja_avisado'] ?? null) === true, $r['texto']);

// Presença do app depois da tolerância vira entrada atrasada sozinha.
// Turma de dia inteiro, sem tolerância, e hoje como dia letivo extra:
// o teste não depende da hora em que roda.
post('escola/adicionarCalendario.php', ['data_inicio' => $hoje, 'tipo' => 'aula_extra', 'descricao' => 'Dia de teste'], $t3);
$r = post('presenca/registrar.php', ['id_aluno' => $ivo, 'metodo' => 'manual'], $t3);
verificar('presença depois da tolerância já registra a entrada atrasada (origem: presença)', $r['status'] === 201
    && ($r['json']['dados']['entrada']['situacao'] ?? '') === 'atrasada' && ($r['json']['dados']['entrada']['id_atraso'] ?? 0) > 0
    && valor("SELECT origem FROM tb_atraso WHERE id_aluno = ?", [$ivo]) === 'presenca', $r['texto']);
post('escola/salvarConfiguracao.php', ['atraso_pela_presenca' => false], $t3);
$iara = (int) ($novoAluno($t3, $sala3i, 'Iara Integral')['json']['dados']['id_aluno'] ?? 0);
$r = post('presenca/registrar.php', ['id_aluno' => $iara, 'metodo' => 'manual'], $t3);
verificar('com a opção desligada, a presença fica só como presença', ($r['json']['dados']['entrada']['situacao'] ?? '') === 'atrasada'
    && array_key_exists('id_atraso', $r['json']['dados']['entrada'] ?? []) && $r['json']['dados']['entrada']['id_atraso'] === null
    && (int) valor("SELECT count(*) FROM tb_atraso WHERE id_aluno = ?", [$iara]) === 0, $r['texto']);
post('escola/salvarConfiguracao.php', ['atraso_pela_presenca' => true, 'tolerancia_minutos' => 10], $t3);

$idAtrasoIvo = (int) valor("SELECT id_atraso FROM tb_atraso WHERE id_aluno = ?", [$ivo]);
$r = post('registro/remover.php', ['tipo' => 'atraso', 'id' => $idAtrasoIvo], $t1);
verificar('outra escola apagando registro: 404', $r['status'] === 404, $r['texto']);
$r = post('registro/remover.php', ['tipo' => 'atraso', 'id' => $idAtrasoIvo], $t4);
verificar('coordenador(a) não apaga registro: 403', $r['status'] === 403, $r['texto']);
$r = post('registro/remover.php', ['tipo' => 'atraso', 'id' => $idAtrasoIvo], $t3);
verificar('a gestão apaga um registro feito por engano', $r['status'] === 200 && (int) valor("SELECT count(*) FROM tb_atraso WHERE id_aluno = ?", [$ivo]) === 0, $r['texto']);

// ------------------------------------------------------------ administração
secao('Administração do sistema (equipe)');
$r = post('admin/resumo.php', [], $tAdmin);
verificar('resumo: catálogo, escolas em uso e contas', ($r['json']['dados']['escolas_catalogo'] ?? 0) === 5795
    && ($r['json']['dados']['catalogo']['versao'] ?? '') === 'SEDUC-SP 08/2026' && ($r['json']['dados']['contas_ativas'] ?? 0) >= 5, $r['texto']);
$r = post('admin/resumo.php', [], $t1);
verificar('resumo para conta comum: 404', $r['status'] === 404, $r['texto']);
$r = post('admin/escolas.php', ['em_uso' => true], $tAdmin);
verificar('escolas em uso, com contas, turmas e alunos', in_array($idHelen, array_column($r['json']['dados']['escolas'] ?? [], 'id_escola'), true), $r['texto']);
$r = post('admin/escolas.php', ['busca' => 'adamantina'], $tAdmin);
verificar('a equipe busca no catálogo inteiro', ($r['json']['dados']['total_registros'] ?? 0) >= 2
    && count(array_filter($r['json']['dados']['escolas'] ?? [], fn($e) => $e['municipio'] !== 'ADAMANTINA' && $e['diretoria_ensino'] !== 'ADAMANTINA')) === 0, $r['texto']);
$novaConta('coord7@escola.test', ['id_escola' => $idHelen]);
$confirmar('coord7@escola.test');
$idCoord7 = $idDe('coord7@escola.test');
$r = post('admin/contas.php', ['situacao' => 'pendente'], $tAdmin);
verificar('a equipe vê as contas pendentes de todas as escolas', in_array('coord7@escola.test', array_column($r['json']['dados']['contas'] ?? [], 'email_coordenacao'), true), $r['texto']);
$pedidoCoord7 = array_values(array_filter($r['json']['dados']['contas'] ?? [], fn($c) => $c['email_coordenacao'] === 'coord7@escola.test'))[0] ?? [];
verificar('a lista diz se a escola do pedido já tem gestão (o modal de aprovação usa)', ($pedidoCoord7['escola_tem_gestao'] ?? null) === true, $r['texto']);
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord7, 'acao' => 'aprovar'], $tAdmin);
verificar('a equipe aprova uma conta de qualquer escola', $r['status'] === 200 && ($r['json']['dados']['situacao'] ?? '') === 'ativo', $r['texto']);
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord7, 'acao' => 'mover_escola', 'id_escola' => 1], $tAdmin);
verificar('a equipe move a conta para a escola certa (como coordenadora: lá já há gestão)', $r['status'] === 200
    && ($r['json']['dados']['id_escola'] ?? 0) === 1 && ($r['json']['dados']['papel'] ?? '') === 'coordenador', $r['texto']);
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord3, 'acao' => 'desativar'], $tAdmin);
verificar('nem a equipe deixa uma escola sem gestão ativa: 409', $r['status'] === 409, $r['texto']);
// Sem provedor de e-mail o link não chega: a equipe confirma no lugar da
// pessoa, depois de falar com ela.
$novaConta('coord8@escola.test', ['nome_escola' => 'Escola Oito']);
$idCoord8 = $idDe('coord8@escola.test');
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord8, 'acao' => 'confirmar_email'], $t1);
verificar('conta comum não confirma o e-mail de ninguém: 404', $r['status'] === 404, $r['texto']);
$r = post('admin/contas.php', ['situacao' => 'pendente', 'busca' => 'coord8'], $tAdmin);
verificar('a lista de contas mostra quem ainda não confirmou o e-mail', ($r['json']['dados']['contas'][0]['email_verificado'] ?? null) === false, $r['texto']);
verificar('escola nova, sem gestão: a lista avisa (quem for aprovado vira a gestão)', ($r['json']['dados']['contas'][0]['escola_tem_gestao'] ?? null) === false, $r['texto']);
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord8, 'acao' => 'confirmar_email'], $tAdmin);
verificar('a equipe confirma o e-mail no lugar da pessoa', $r['status'] === 200 && ($r['json']['dados']['email_verificado'] ?? null) === true, $r['texto']);
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord8, 'acao' => 'confirmar_email'], $tAdmin);
verificar('confirmar de novo: 409', $r['status'] === 409, $r['texto']);
$r = post('admin/atualizarConta.php', ['id_coordenacao' => $idCoord8, 'acao' => 'recusar'], $tAdmin);
verificar('a equipe recusa um pedido, e a pessoa fica sabendo por e-mail', $r['status'] === 200
    && count(emailsComAssunto('coord8@escola.test', 'não foi aprovado')) === 1, $r['texto']);
post('suporte/abrirChamado.php', ['categoria' => 'duvida', 'assunto' => 'Horário da turma', 'mensagem' => 'Como mudo o horário da turma do noturno?'], $t3);
$r = post('admin/chamados.php', ['status' => 'aberto'], $tAdmin);
$idChamado = (int) ($r['json']['dados']['chamados'][0]['id_chamado'] ?? 0);
verificar('a equipe vê os chamados abertos, com a escola de quem abriu', $idChamado > 0
    && ($r['json']['dados']['chamados'][0]['nome_escola'] ?? '') === 'E.E. Helen Keller', $r['texto']);
$r = post('admin/responderChamado.php', ['id_chamado' => $idChamado, 'status' => 'respondido'], $tAdmin);
verificar('marcar como respondido sem resposta: 422', $r['status'] === 422, $r['texto']);
$r = post('admin/responderChamado.php', ['id_chamado' => $idChamado, 'resposta' => 'Em Escola > Turmas e horários.'], $tAdmin);
verificar('a equipe responde o chamado', $r['status'] === 200, $r['texto']);
$r = post('suporte/listarChamados.php', [], $t3);
verificar('quem abriu vê a resposta em Meus chamados', ($r['json']['dados'][0]['status'] ?? '') === 'respondido'
    && str_contains($r['json']['dados'][0]['resposta'] ?? '', 'Turmas e horários'), $r['texto']);
$r = post('admin/responderChamado.php', ['id_chamado' => $idChamado, 'status' => 'fechado'], $t3);
verificar('conta comum respondendo chamado: 404', $r['status'] === 404, $r['texto']);

// ------------------------------------------------------------ biometria e presença
secao('Biometria facial cifrada, identificação e presença automática');
// Vetores de 128 posições como os da face-api.js: cada "pessoa" é um
// vetor unitário; outra foto da mesma pessoa é o mesmo vetor com uma
// pequena variação (distância ~0,2); pessoas diferentes ficam a ~1,4.
$vetor = function (int $semente): array {
    mt_srand($semente);
    $v = [];
    for ($i = 0; $i < 128; $i++) {
        $v[] = mt_rand(-1000, 1000) / 1000;
    }
    $norma = sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
    return array_map(fn($x) => $x / $norma, $v);
};
$variacao = function (array $base, int $semente): array {
    mt_srand($semente);
    return array_map(fn($x) => $x + mt_rand(-1000, 1000) / 1000 * 0.03, $base);
};
$rostoAna = $vetor(101);
$rostoBruno = $vetor(202);
$amostrasAna = [$variacao($rostoAna, 1), $variacao($rostoAna, 2), $variacao($rostoAna, 3)];
$cadastrarFacial = fn(int $aluno, array $amostras, string $token) => post('biometria/cadastrarBiometriaFacial.php',
    ['id_aluno' => $aluno, 'amostras' => $amostras, 'modelo' => 'faceapi-resnet34-128'], $token);
$identificar = fn(array $rosto, string $token) => post('biometria/identificarFacial.php',
    ['embedding' => $rosto, 'modelo' => 'faceapi-resnet34-128'], $token);

$r = $cadastrarFacial($ana, $amostrasAna, $t1);
verificar('cadastrar biometria sem o consentimento do responsável: 409', $r['status'] === 409
    && ($r['json']['dados']['falta_consentimento'] ?? null) === true, $r['texto']);
$r = post('biometria/consentimento.php', ['id_aluno' => $ana], $t1);
verificar('consentimento sem o nome do responsável: 422', $r['status'] === 422, $r['texto']);
$r = post('biometria/consentimento.php', ['id_aluno' => $ana, 'nome_responsavel' => 'Mãe da Ana'], $t2);
verificar('outra escola registrando consentimento: 404', $r['status'] === 404, $r['texto']);
$r = post('biometria/consentimento.php', ['id_aluno' => $ana, 'nome_responsavel' => 'Mãe da Ana'], $t1);
verificar('registrar o consentimento: 200', $r['status'] === 200, $r['texto']);
$r = $cadastrarFacial($ana, [], $t1);
verificar('cadastro sem amostras: 422', $r['status'] === 422, $r['texto']);
$r = $cadastrarFacial($ana, array_fill(0, 6, $rostoAna), $t1);
verificar('mais de 5 amostras: 422', $r['status'] === 422, $r['texto']);
$r = $cadastrarFacial($ana, $amostrasAna, $t1);
verificar('cadastrar a biometria facial (3 amostras): 200', $r['status'] === 200 && ($r['json']['dados']['amostras'] ?? 0) === 3, $r['texto']);
$gravado = sql("SELECT embedding, cifrado, amostras FROM tb_biometria_facial WHERE id_aluno = ?", [$ana])[0] ?? [];
verificar('vetores gravados cifrados (AES-GCM), sem os números em texto', str_starts_with($gravado['embedding'] ?? '', 'gcm1:')
    && ($gravado['cifrado'] ?? false) === true && (int) ($gravado['amostras'] ?? 0) === 3 && !str_contains($gravado['embedding'] ?? '', '.'));

post('biometria/consentimento.php', ['id_aluno' => $bruno, 'nome_responsavel' => 'Pai do Bruno'], $t1);
$r = $cadastrarFacial($bruno, [$variacao($rostoAna, 9)], $t1);
verificar('rosto que já é de outro aluno: 409 dizendo com quem parece (evita vincular à pessoa errada)', $r['status'] === 409
    && ($r['json']['dados']['id_aluno_parecido'] ?? 0) === $ana && str_contains($r['json']['mensagem'] ?? '', 'Ana Teste'), $r['texto']);
$r = $cadastrarFacial($bruno, [$variacao($rostoBruno, 4), $variacao($rostoBruno, 5)], $t1);
verificar('cadastrar o rosto do Bruno: 200', $r['status'] === 200, $r['texto']);

$r = $identificar($variacao($rostoAna, 7), $t1);
$idPresencaAna = (int) ($r['json']['dados']['presenca']['id_presenca'] ?? 0);
verificar('identificar: reconhece a Ana e registra a presença', $r['status'] === 200 && ($r['json']['dados']['reconhecido'] ?? null) === true
    && ($r['json']['dados']['aluno']['id_aluno'] ?? 0) === $ana && ($r['json']['dados']['aluno']['nome_sala'] ?? '') === '1A'
    && ($r['json']['dados']['presenca']['nova'] ?? null) === true, $r['texto']);
verificar('presença gravada no dia de hoje da escola, como facial',
    valor("SELECT dia_letivo::text || '|' || metodo FROM tb_presenca WHERE id_presenca = ?", [$idPresencaAna]) === "$hoje|facial");
$r = $identificar($variacao($rostoAna, 8), $t1);
verificar('identificar de novo no mesmo dia: avisa que já estava registrada, sem duplicar', ($r['json']['dados']['presenca']['nova'] ?? null) === false
    && ($r['json']['dados']['presenca']['id_presenca'] ?? 0) === $idPresencaAna
    && (int) valor("SELECT count(*) FROM tb_presenca WHERE id_aluno = ?", [$ana]) === 1, $r['texto']);
$r = $identificar($vetor(303), $t1);
verificar('rosto desconhecido: não reconhecido e nenhuma presença nova', ($r['json']['dados']['reconhecido'] ?? null) === false
    && (int) valor("SELECT count(*) FROM tb_presenca WHERE id_coordenacao = ?", [$idCoord1]) === 1, $r['texto']);
verificar('as tentativas ficam registradas, sem dado biométrico',
    (int) valor("SELECT count(*) FROM tb_identificacao_biometrica WHERE id_coordenacao = ?", [$idCoord1]) === 3
    && (int) valor("SELECT count(*) FROM tb_identificacao_biometrica WHERE NOT reconhecido") === 1);
$r = $identificar($variacao($rostoAna, 10), $t2);
verificar('outra escola não reconhece os alunos da escola 1', ($r['json']['dados']['reconhecido'] ?? null) !== true
    && (int) valor("SELECT count(*) FROM tb_presenca WHERE id_coordenacao = ?", [$idCoord2]) === 0, $r['texto']);
$respostas = paralelo(array_fill(0, 4, ['biometria/identificarFacial.php', ['embedding' => $variacao($rostoBruno, 11), 'modelo' => 'faceapi-resnet34-128'], $t1]));
verificar('4 tablets reconhecendo o Bruno ao mesmo tempo: uma presença só',
    count(array_filter($respostas, fn($x) => ($x['json']['dados']['reconhecido'] ?? null) === true)) === 4
    && count(array_filter($respostas, fn($x) => ($x['json']['dados']['presenca']['nova'] ?? null) === true)) === 1
    && (int) valor("SELECT count(*) FROM tb_presenca WHERE id_aluno = ?", [$bruno]) === 1, array_column($respostas, 'texto'));

$r = post('presenca/registrar.php', ['id_aluno' => $carla, 'metodo' => 'manual'], $t1);
verificar('presença manual (sem biometria): 201', $r['status'] === 201 && ($r['json']['dados']['presenca']['metodo'] ?? '') === 'manual', $r['texto']);
$r = post('presenca/registrar.php', ['id_aluno' => $carla, 'metodo' => 'manual'], $t1);
verificar('presença manual repetida: 200 com a já registrada', $r['status'] === 200 && ($r['json']['dados']['presenca']['nova'] ?? null) === false, $r['texto']);
$r = post('presenca/registrar.php', ['id_aluno' => $carla, 'metodo' => 'facial'], $t1);
verificar('presença "facial" só pela identificação: 422', $r['status'] === 422, $r['texto']);
$r = post('presenca/registrar.php', ['id_aluno' => $carla, 'metodo' => 'manual'], $t2);
verificar('outra escola registrando presença: 404', $r['status'] === 404, $r['texto']);

$r = post('presenca/listar.php', [], $t1);
verificar('presenças de hoje: 3 presentes (Ana, Bruno, Carla) e 4 ainda não registrados', ($r['json']['dados']['total_alunos'] ?? 0) === 7
    && count($r['json']['dados']['presentes'] ?? []) === 3 && count($r['json']['dados']['ausentes'] ?? []) === 4, $r['texto']);
$r = post('presenca/listar.php', ['id_sala' => $sala2], $t1);
verificar('presenças de turma de outra escola: 404', $r['status'] === 404, $r['texto']);
$r = post('presenca/listar.php', ['data' => '30/10/2026'], $t1);
verificar('data fora do formato: 422', $r['status'] === 422, $r['texto']);
$r = post('presenca/listar.php', ['data' => $ontem], $t1);
verificar('presenças de ontem: nenhuma', ($r['json']['dados']['presentes'] ?? null) === [], $r['texto']);

$r = post('biometria/alunos.php', [], $t1);
$porAluno = array_column($r['json']['dados'] ?? [], null, 'id_aluno');
verificar('lista do app: biometria, consentimento e presença de hoje de cada aluno', count($porAluno) === 7
    && ($porAluno[$ana]['tem_facial'] ?? null) === true && ($porAluno[$ana]['consentimento_responsavel'] ?? '') === 'Mãe da Ana'
    && ($porAluno[$ana]['presenca_hoje'] ?? null) !== null && ($porAluno[$diego]['tem_facial'] ?? null) === false, $r['texto']);
$r = post('biometria/alunos.php', [], $t2);
verificar('lista do app só com os alunos da própria escola', array_column($r['json']['dados'] ?? [], 'nome_aluno') === ['Zeca Vizinho'], $r['texto']);
$r = post('biometria/verificarBiometriaFacial.php', ['id_aluno' => $ana, 'embedding' => $variacao($rostoAna, 12)], $t1);
verificar('verificação 1:1 com o rosto da própria aluna: confere', ($r['json']['dados']['correspondeu'] ?? null) === true, $r['texto']);
$r = post('biometria/verificarBiometriaFacial.php', ['id_aluno' => $ana, 'embedding' => $rostoBruno], $t1);
verificar('verificação 1:1 com outro rosto: não confere', ($r['json']['dados']['correspondeu'] ?? null) === false, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $ana], $t1);
verificar('página do aluno mostra o consentimento e a biometria facial', ($r['json']['dados']['aluno']['consentimento_responsavel'] ?? '') === 'Mãe da Ana'
    && ($r['json']['dados']['aluno']['facial_amostras'] ?? 0) === 3, $r['texto']);

$r = post('biometria/excluirBiometria.php', ['id_aluno' => $ana, 'tipo' => 'facial'], $t2);
verificar('outra escola apagando biometria: 404', $r['status'] === 404, $r['texto']);
$r = post('biometria/excluirBiometria.php', ['id_aluno' => $ana, 'tipo' => 'facial'], $t1);
verificar('apagar a biometria facial (LGPD): 200', $r['status'] === 200, $r['texto']);
$r = $identificar($variacao($rostoAna, 13), $t1);
verificar('depois de apagada, a Ana não é mais reconhecida', ($r['json']['dados']['reconhecido'] ?? null) === false, $r['texto']);
verificar('as presenças já registradas continuam (são histórico, não biometria)', (int) valor("SELECT count(*) FROM tb_presenca WHERE id_aluno = ?", [$ana]) === 1);
$r = post('biometria/consentimento.php', ['id_aluno' => $bruno, 'revogar' => true], $t1);
verificar('revogar o consentimento apaga a biometria do aluno', $r['status'] === 200
    && (int) valor("SELECT count(*) FROM tb_biometria_facial WHERE id_aluno = ?", [$bruno]) === 0
    && (int) valor("SELECT count(*) FROM tb_consentimento_biometria WHERE id_aluno = ?", [$bruno]) === 0, $r['texto']);

secao('Biometria digital (preparada para o leitor)');
$template = base64_encode(random_bytes(400));
$digital = fn(string $tpl, string $token) => post('biometria/cadastrarBiometriaDigital.php',
    ['id_aluno' => $diego, 'dedo' => 'indicador direito', 'template' => $tpl, 'formato' => 'ISO 19794-2'], $token);
$r = $digital($template, $t1);
verificar('digital sem consentimento: 409', $r['status'] === 409, $r['texto']);
post('biometria/consentimento.php', ['id_aluno' => $diego, 'nome_responsavel' => 'Mãe do Diego'], $t1);
$r = $digital('não é base64', $t1);
verificar('template que não é base64: 422', $r['status'] === 422, $r['texto']);
$r = $digital($template, $t1);
verificar('cadastrar digital: 200 e gravada cifrada', $r['status'] === 200
    && str_starts_with((string) valor("SELECT template FROM tb_biometria_digital WHERE id_aluno = ?", [$diego]), 'gcm1:'), $r['texto']);
$r = post('biometria/templatesDigitais.php', [], $t1);
verificar('templates para o SDK do leitor: o mesmo template, só da própria escola', count($r['json']['dados'] ?? []) === 1
    && ($r['json']['dados'][0]['template'] ?? '') === $template, $r['status']);
$r = post('biometria/templatesDigitais.php', [], $t2);
verificar('outra escola não recebe as digitais', ($r['json']['dados'] ?? null) === [], $r['texto']);
$r = post('presenca/registrar.php', ['id_aluno' => $diego, 'metodo' => 'digital'], $t1);
verificar('presença pela digital identificada no aparelho: 201', $r['status'] === 201 && ($r['json']['dados']['presenca']['metodo'] ?? '') === 'digital', $r['texto']);

// ------------------------------------------------------------ celular do aluno
secao('Celular do aluno (fluxo anterior): check-in e ocorrências');
// Aparelho conectado antes do app da coordenação, como os que existem em
// produção: continuam aceitos, mas nenhum aparelho novo é registrado.
$uuid = 'aparelho-ci-' . bin2hex(random_bytes(4));
$segredo2 = bin2hex(random_bytes(32));
sql("INSERT INTO tb_dispositivo_aluno (id_aluno, device_uuid, device_secret) VALUES (?, ?, ?)", [$elisa, $uuid, $segredo2]);
$r = post('digital/checkinBiometrico.php', assinado($uuid, bin2hex(random_bytes(32)), 'atraso'));
verificar('assinatura com outro segredo: 401', $r['status'] === 401, $r['texto']);
$r = post('digital/checkinBiometrico.php', assinado($uuid, $segredo2, 'atraso'));
$idCheckin = (int) ($r['json']['dados']['id_checkin'] ?? 0);
verificar('check-in assinado: 200', $r['status'] === 200 && $idCheckin > 0, $r['texto']);
$r = post('digital/checkinBiometrico.php', assinado($uuid, $segredo2, 'atraso'));
verificar('segundo toque no mesmo dia devolve o mesmo check-in', ($r['json']['dados']['id_checkin'] ?? 0) === $idCheckin
    && (int) valor("SELECT count(*) FROM tb_checkin_biometrico WHERE id_aluno = ?", [$elisa]) === 1, $r['texto']);
$r = post('digital/checkinBiometrico.php', assinado($uuid, $segredo2, 'atraso', gmdate('Y-m-d\TH:i:s', time() - 900) . '.000Z'));
verificar('check-in com horário fora da janela de 5 min: 401', $r['status'] === 401, $r['texto']);
$r = post('digital/listarCheckinsPendentes.php', [], $t2);
verificar('outra escola não vê os check-ins', ($r['json']['dados'] ?? null) === [], $r['texto']);
$r = post('digital/confirmarCheckin.php', ['id_checkin' => $idCheckin], $t2);
verificar('outra escola confirmando check-in: 404', $r['status'] === 404, $r['texto']);
$r = post('digital/confirmarCheckin.php', ['id_checkin' => $idCheckin], $t1);
verificar('confirmar check-in: 200', $r['status'] === 200, $r['texto']);

// A ocorrência do celular chega "agora": para não depender da hora em que
// o teste roda, Elisa passa para uma turma de dia inteiro, sem tolerância,
// e hoje e ontem viram dias letivos extras na escola 1.
$sala1t = (int) (post('user/addSala.php', ['nome_sala' => '1T', 'periodo_sala' => 'Integral', 'horario_inicio' => '00:00', 'horario_fim' => '23:59'], $t1)['json']['dados']['id_sala'] ?? 0);
sql("UPDATE tb_aluno SET id_sala = ? WHERE id_aluno = ?", [$sala1t, $elisa]);
post('escola/salvarConfiguracao.php', ['tolerancia_minutos' => 0], $t1);
post('escola/adicionarCalendario.php', ['data_inicio' => $ontem, 'data_fim' => $hoje, 'tipo' => 'aula_extra', 'descricao' => 'Dias de teste'], $t1);

$ocorrencia = assinado($uuid, $segredo2, 'falta') + ['motivo' => 'Consulta médica', 'observacao' => 'Levo atestado', 'responsavel_ciente' => true];
$r = post('ocorrencia/enviarOcorrencia.php', $ocorrencia);
verificar('ocorrência enviada pelo app: 201 pendente', $r['status'] === 201 && ($r['json']['dados']['status'] ?? '') === 'pendente', $r['texto']);
verificar('a ocorrência não vira falta no banco', (int) valor("SELECT count(*) FROM tb_falta WHERE id_aluno = ?", [$elisa]) === 0);
$r = post('ocorrencia/enviarOcorrencia.php', assinado($uuid, $segredo2, 'falta') + ['motivo' => 'De novo']);
verificar('segundo envio do mesmo tipo no dia: 409', $r['status'] === 409, $r['texto']);
$r = post('ocorrencia/enviarOcorrencia.php', assinado('aparelho-desconhecido', 'x', 'falta') + ['motivo' => 'x']);
verificar('aparelho não vinculado: 401', $r['status'] === 401, $r['texto']);
$r = post('ocorrencia/enviarOcorrencia.php', assinado($uuid, 'segredo-errado', 'atraso') + ['motivo' => 'x']);
verificar('assinatura forjada: 401', $r['status'] === 401 && str_contains($r['json']['mensagem'] ?? '', 'Assinatura'), $r['texto']);
$r = post('ocorrencia/listarPendentes.php', [], $t2);
verificar('outra escola não vê a fila', ($r['json']['dados']['total'] ?? -1) === 0, $r['texto']);
$r = post('ocorrencia/listarPendentes.php', [], $t1);
$idOcorrencia = (int) ($r['json']['dados']['ocorrencias'][0]['id_ocorrencia'] ?? 0);
verificar('coordenação vê a ocorrência na fila', $idOcorrencia > 0, $r['texto']);
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrencia, 'decisao' => 'aprovar', 'justificado' => true], $t1);
verificar('aprovar ocorrência de falta: 422 (faltas não são mais registradas)', $r['status'] === 422
    && (int) valor("SELECT count(*) FROM tb_falta WHERE id_aluno = ?", [$elisa]) === 0, $r['texto']);
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrencia, 'decisao' => 'rejeitar'], $t1);
verificar('a ocorrência de falta é rejeitada e fica no histórico', $r['status'] === 200
    && valor("SELECT status FROM tb_ocorrencia_pendente WHERE id_ocorrencia = ?", [$idOcorrencia]) === 'rejeitado', $r['texto']);
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrencia, 'decisao' => 'rejeitar'], $t1);
verificar('segunda decisão na mesma ocorrência: 409', $r['status'] === 409, $r['texto']);
// Atraso enviado ontem e aprovado hoje: é do dia do envio.
post('ocorrencia/enviarOcorrencia.php', assinado($uuid, $segredo2, 'atraso') + ['motivo' => 'Ônibus quebrou']);
$idOcorrenciaOntem = (int) valor("SELECT id_ocorrencia FROM tb_ocorrencia_pendente WHERE id_aluno = ? AND tipo = 'atraso' AND status = 'pendente'", [$elisa]);
sql("UPDATE tb_ocorrencia_pendente SET criado_em = criado_em - INTERVAL '1 day' WHERE id_ocorrencia = ?", [$idOcorrenciaOntem]);
$diaEnvio = valor("SELECT " . dia('criado_em') . "::text FROM tb_ocorrencia_pendente WHERE id_ocorrencia = ?", [$idOcorrenciaOntem]);
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrenciaOntem, 'decisao' => 'aprovar'], $t1);
$idAtrasoCriado = (int) ($r['json']['dados']['id_registro'] ?? 0);
verificar('aprovar atraso: 200 e entrada atrasada criada (origem: ocorrência)', $r['status'] === 200 && $idAtrasoCriado > 0
    && valor("SELECT origem FROM tb_atraso WHERE id_atraso = ?", [$idAtrasoCriado]) === 'ocorrencia', $r['texto']);
verificar('o atraso é do dia do ENVIO, não do dia da aprovação, com o motivo do aluno',
    valor("SELECT " . dia('dta_atraso') . "::text || '|' || motivo_atraso FROM tb_atraso WHERE id_atraso = ?", [$idAtrasoCriado]) === "$diaEnvio|Ônibus quebrou");
$idAtraso = (int) (post('user/marcarAtraso.php', ['id_aluno' => $elisa], $t1)['json']['dados']['id_atraso'] ?? 0);
post('ocorrencia/enviarOcorrencia.php', assinado($uuid, $segredo2, 'atraso') + ['motivo' => 'Ônibus atrasou']);
$idOcorrenciaAtraso = (int) valor("SELECT id_ocorrencia FROM tb_ocorrencia_pendente WHERE id_aluno = ? AND tipo = 'atraso' AND status = 'pendente'", [$elisa]);
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrenciaAtraso, 'decisao' => 'aprovar'], $t1);
verificar('aprovar atraso de um dia já lançado à mão não cria outro', $r['status'] === 200 && ($r['json']['dados']['ja_existia'] ?? null) === true
    && ($r['json']['dados']['id_registro'] ?? 0) === $idAtraso
    && (int) valor("SELECT count(*) FROM tb_atraso WHERE id_aluno = ?", [$elisa]) === 2, $r['texto']);
secao('Celular do aluno (fluxo anterior): desconectar pela coordenação');
$r = post('digital/revogarDispositivo.php', ['id_aluno' => $elisa], $t2);
verificar('outra escola desconectando o celular: 404', $r['status'] === 404, $r['texto']);
$r = post('digital/revogarDispositivo.php', ['id_aluno' => $elisa], $t1);
verificar('desconectar o celular: 200', $r['status'] === 200 && ($r['json']['dados']['quantidade'] ?? 0) === 1, $r['texto']);
$r = post('digital/checkinBiometrico.php', assinado($uuid, $segredo2, 'presenca'));
verificar('celular desconectado: 401', $r['status'] === 401, $r['texto']);

// ------------------------------------------------------------ demais telas
secao('Acompanhamento, biometria facial e relatório XML');
$r = post('acompanhamento/addAcompanhamento.php', ['id_aluno' => $ana, 'canal' => 'Ligação para responsável', 'observacao' => 'Família avisada'], $t2);
verificar('outra escola registrando acompanhamento: 404', $r['status'] === 404, $r['texto']);
$r = post('acompanhamento/addAcompanhamento.php', ['id_aluno' => $ana, 'canal' => 'Ligação para responsável', 'observacao' => 'Família avisada'], $t1);
verificar('registrar acompanhamento: 200', $r['status'] === 200, $r['texto']);
$r = post('acompanhamento/listarAcompanhamento.php', ['pagina' => 1], $t2);
verificar('outra escola não vê os acompanhamentos', ($r['json']['dados']['total_registros'] ?? -1) === 0, $r['texto']);
$r = post('acompanhamento/listarAcompanhamento.php', ['pagina' => 1], $t1);
verificar('escola 1 vê o próprio acompanhamento', ($r['json']['dados']['total_registros'] ?? -1) === 1, $r['texto']);
$embedding = array_map(fn($i) => sin($i + 1), range(0, 15));
$r = post('biometria/cadastrarBiometriaFacial.php', ['id_aluno' => $ana, 'embedding' => $embedding, 'modelo' => 'teste'], $t2);
verificar('outra escola cadastrando biometria facial: 404', $r['status'] === 404, $r['texto']);
$r = post('biometria/cadastrarBiometriaFacial.php', ['id_aluno' => $ana, 'embedding' => $embedding, 'modelo' => 'teste'], $t1);
verificar('cadastrar biometria facial: 200', $r['status'] === 200, $r['texto']);
$r = post('biometria/verificarBiometriaFacial.php', ['id_aluno' => $ana, 'embedding' => $embedding], $t1);
verificar('verificar com o mesmo vetor: corresponde', ($r['json']['dados']['correspondeu'] ?? null) === true, $r['texto']);
$r = post('biometria/statusBiometriaFacial.php', ['id_aluno' => $ana], $t2);
verificar('outra escola consultando biometria: 404', $r['status'] === 404, $r['texto']);
$r = post('relatorio/exportarXml.php', ['relatorio' => 'alunos'], $t1);
$xml = @simplexml_load_string($r['texto']);
verificar('relatório XML: documento válido', $xml !== false && str_starts_with($r['h']['content-type'] ?? '', 'application/xml'), [$r['status'], mb_substr($r['texto'], 0, 200)]);
verificar('relatório XML: só alunos da escola', $xml !== false && count($xml->itens->aluno) === 7 && !str_contains($r['texto'], 'Zeca'), $r['texto']);
verificar('relatório XML de alunos: entradas atrasadas e saídas, sem faltas', $xml !== false && isset($xml->itens->aluno[0]->entradas_atrasadas)
    && !str_contains($r['texto'], 'falta'), mb_substr($r['texto'], 0, 300));
$r = post('relatorio/exportarXml.php', ['relatorio' => 'registros'], $t1);
$xml = @simplexml_load_string($r['texto']);
verificar('relatório XML de registros: entradas atrasadas e saídas antecipadas', $xml !== false && count($xml->itens->registro) >= 5
    && str_contains($r['texto'], 'saida_antecipada') && str_contains($r['texto'], 'Tia Rosa'), mb_substr($r['texto'], 0, 300));
foreach (['alunos', 'registros', 'alertas', 'acompanhamentos'] as $tipoRelatorio) {
    $r = post('relatorio/exportarXml.php', ['relatorio' => $tipoRelatorio], $t2);
    verificar("relatório XML de $tipoRelatorio pela escola 2: nada da escola 1", $r['status'] === 200
        && @simplexml_load_string($r['texto']) !== false && preg_match('/(Ana|Bruno|Carla|Diego|Elisa|Fabio|Gabi) Teste/', $r['texto']) === 0,
        [$r['status'], mb_substr($r['texto'], 0, 200)]);
}

// ------------------------------------------------------------ conta
// No fim de propósito: aqui sessões são encerradas e a senha da
// coordenação 1 muda - os tokens $t1/$t2 usados acima deixam de valer.
secao('Conta: dispositivos conectados, perfil, preferências e suporte');
$sidDe = fn(string $token) => json_decode(base64_decode(strtr(explode('.', $token . '..')[1], '-_', '+/')), true)['sid'] ?? null;
$uaChrome = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
$entrar = fn(string $email, string $senha, array $extra = [], array $cabecalhos = []) =>
    post('user/selectCoord.php', ['email' => $email, 'senha' => $senha] + $extra, null, $cabecalhos);
$verdadeiro = fn($valor) => in_array($valor, [true, 't', 'true', 1, '1'], true);

verificar('login comum já vem com sessão no token', $sidDe($t1) !== null && (int) valor("SELECT count(*) FROM tb_sessao WHERE id_sessao = ?", [$sidDe($t1)]) === 1);
$r = $entrar('coord1@escola.test', 'Senha@123', ['lembrar' => true], [$uaChrome]);
$tLembrar = $r['json']['dados']['token'] ?? '';
$validade = ($r['json']['dados']['expira_em'] ?? 0) - time();
verificar('"Lembrar de mim": sessão de 30 dias', $validade > 29 * 86400 && $validade <= 30 * 86400, $r['texto']);
$linha = sql("SELECT cliente, dispositivo, lembrar FROM tb_sessao WHERE id_sessao = ?", [$sidDe($tLembrar)])[0] ?? [];
verificar('a sessão guarda o nome do aparelho e o "lembrar"', ($linha['dispositivo'] ?? '') === 'Chrome no Windows' && $verdadeiro($linha['lembrar'] ?? null) && ($linha['cliente'] ?? '') === 'site', $linha);
$r = $entrar('coord1@escola.test', 'Senha@123', ['cliente' => 'app', 'lembrar' => true]);
$tApp = $r['json']['dados']['token'] ?? '';
$validade = ($r['json']['dados']['expira_em'] ?? 0) - time();
verificar('app: 12 horas (o "lembrar" não vale para o app)', $validade > 11 * 3600 && $validade <= 12 * 3600, $r['texto']);
verificar('sessão do app identificada como app', valor("SELECT dispositivo FROM tb_sessao WHERE id_sessao = ?", [$sidDe($tApp)]) === 'App BioFrequência (Android)');

$r = post('conta/sessoes.php', [], $tLembrar);
$lista = $r['json']['dados'] ?? [];
$ids = array_column($lista, 'id_sessao');
verificar('dispositivos conectados: lista as sessões da conta', $r['status'] === 200 && in_array($sidDe($t1), $ids, true) && in_array($sidDe($tApp), $ids, true), $r['texto']);
verificar('dispositivos conectados: marca este aparelho', array_column(array_filter($lista, fn($s) => $s['atual']), 'id_sessao') === [$sidDe($tLembrar)], $lista);
verificar('dispositivos conectados: nenhuma sessão de outra conta', !in_array($sidDe($t2), $ids, true));
$r = post('conta/encerrarSessao.php', ['id_sessao' => $sidDe($t2)], $tLembrar);
verificar('desconectar aparelho de outra conta: 404', $r['status'] === 404, $r['texto']);
verificar('...e a sessão da outra conta continua valendo', post('user/listarSalas.php', [], $t2)['status'] === 200);
$r = post('conta/encerrarSessao.php', ['id_sessao' => 'nao-e-um-id'], $tLembrar);
verificar('desconectar com id malformado: 422', $r['status'] === 422, $r['texto']);
$r = post('conta/encerrarSessao.php', ['id_sessao' => $sidDe($tApp)], $tLembrar);
verificar('desconectar o tablet: 200', $r['status'] === 200 && ($r['json']['dados']['era_este'] ?? null) === false, $r['texto']);
$r = post('biometria/alunos.php', [], $tApp);
verificar('token do tablet desconectado: 401 "Sessão encerrada"', $r['status'] === 401 && str_contains($r['json']['mensagem'] ?? '', 'encerrada'), $r['texto']);
$r = post('conta/encerrarSessao.php', ['outras' => true], $tLembrar);
verificar('desconectar todos os outros: 200', $r['status'] === 200 && ($r['json']['dados']['encerradas'] ?? 0) >= 1, $r['texto']);
verificar('...o login anterior caiu', post('user/listarSalas.php', [], $t1)['status'] === 401);
verificar('...e este aparelho continua conectado', post('user/listarSalas.php', [], $tLembrar)['status'] === 200);
$semSessao = jwtHs256(['iss' => 'biofrequencia-api', 'iat' => time(), 'exp' => time() + 600, 'sub' => $idCoord1], $jwtSecret);
verificar('token antigo, sem sessão, vale até vencer', post('user/listarSalas.php', [], $semSessao)['status'] === 200);
$r = post('conta/sair.php', [], $tLembrar);
verificar('sair: 200 e o token deixa de valer na hora', $r['status'] === 200 && post('user/listarSalas.php', [], $tLembrar)['status'] === 401, $r['texto']);
verificar('sair: some de dispositivos conectados', $verdadeiro(valor("SELECT encerrada_em IS NOT NULL FROM tb_sessao WHERE id_sessao = ?", [$sidDe($tLembrar)])));

$tConta = $entrar('coord1@escola.test', 'Senha@123')['json']['dados']['token'] ?? '';
$r = post('conta/perfil.php', [], $tConta);
verificar('perfil: dados da conta', ($r['json']['dados']['email_coordenacao'] ?? '') === 'coord1@escola.test' && isset($r['json']['dados']['nome_escola']), $r['texto']);
$perfil = ['nome_coordenacao' => 'Coordenação Central', 'email_coordenacao' => 'coord1@escola.test'];
$r = post('conta/atualizarPerfil.php', $perfil, $tConta);
verificar('perfil: o nome muda sem pedir senha', $r['status'] === 200 && ($r['json']['dados']['escola_do_catalogo'] ?? null) === true
    && valor("SELECT nome_coordenacao FROM tb_coordenacao WHERE id_coordenacao = ?", [$idCoord1]) === 'Coordenação Central', $r['texto']);
$r = post('conta/atualizarPerfil.php', ['nome_escola' => 'Escola Estadual Central'] + $perfil, $tConta);
verificar('perfil: o nome de uma escola do catálogo é o oficial e não muda: 409', $r['status'] === 409
    && valor("SELECT nome_escola FROM tb_escola WHERE id_escola = 1") === 'E.E. Maria Nilde Mascellani', $r['texto']);
$tSeis = $entrar('coord6@escola.test', 'Senha@123')['json']['dados']['token'] ?? '';
$r = post('conta/atualizarPerfil.php', ['nome_coordenacao' => 'Coordenação Seis', 'email_coordenacao' => 'coord6@escola.test', 'nome_escola' => 'EMEF Seis'], $tSeis);
verificar('perfil: a gestão de escola fora do catálogo muda o nome da escola', $r['status'] === 200 && ($r['json']['dados']['nome_escola'] ?? '') === 'EMEF Seis', $r['texto']);
$r = post('conta/atualizarPerfil.php', ['email_coordenacao' => 'nova@escola.test'] + $perfil, $tConta);
verificar('perfil: trocar o e-mail sem a senha: 403', $r['status'] === 403, $r['texto']);
$r = post('conta/atualizarPerfil.php', ['email_coordenacao' => 'nova@escola.test', 'senha_atual' => 'errada'] + $perfil, $tConta);
verificar('perfil: trocar o e-mail com a senha errada: 403', $r['status'] === 403, $r['texto']);
$r = post('conta/atualizarPerfil.php', ['email_coordenacao' => 'COORD2@escola.test', 'senha_atual' => 'Senha@123'] + $perfil, $tConta);
verificar('perfil: e-mail de outra conta: 409', $r['status'] === 409, $r['texto']);
sql("UPDATE tb_verificacao_email SET criado_em = criado_em - INTERVAL '2 minutes' WHERE id_coordenacao = ?", [$idCoord1]);
$r = post('conta/atualizarPerfil.php', ['email_coordenacao' => ' Nova@Escola.test ', 'senha_atual' => 'Senha@123'] + $perfil, $tConta);
verificar('perfil: trocar o e-mail manda o link para o endereço novo; o login continua o atual até confirmar', $r['status'] === 200
    && ($r['json']['dados']['email_pendente'] ?? '') === 'nova@escola.test' && codigoDoLink('nova@escola.test') !== ''
    && valor("SELECT email_coordenacao FROM tb_coordenacao WHERE id_coordenacao = ?", [$idCoord1]) === 'coord1@escola.test', $r['texto']);
verificar('...o e-mail novo ainda não entra', $entrar('nova@escola.test', 'Senha@123')['status'] === 401);
$r = post('conta/confirmarEmail.php', ['codigo' => codigoDoLink('nova@escola.test')]);
verificar('perfil: o link confirma a troca, gravada em minúsculas', $r['status'] === 200 && ($r['json']['dados']['finalidade'] ?? '') === 'troca_email'
    && valor("SELECT email_coordenacao FROM tb_coordenacao WHERE id_coordenacao = ?", [$idCoord1]) === 'nova@escola.test', $r['texto']);
verificar('...e o login passa a ser pelo e-mail novo', $entrar('nova@escola.test', 'Senha@123')['status'] === 200);
// Antes da migração 009, trocar o próprio e-mail para um de ADMIN_EMAILS
// sem conta (equipe2) dava acesso à área da equipe na hora.
sql("UPDATE tb_verificacao_email SET criado_em = criado_em - INTERVAL '2 minutes' WHERE id_coordenacao = ?", [$idCoord1]);
$r = post('conta/atualizarPerfil.php', ['nome_coordenacao' => 'Coordenação Central', 'email_coordenacao' => 'equipe2@escola.test', 'senha_atual' => 'Senha@123'], $tConta);
verificar('trocar o e-mail para um da equipe do sistema: sai só o link, e a conta não vira equipe', $r['status'] === 200
    && post('admin/resumo.php', [], $tConta)['status'] === 404 && $entrar('equipe2@escola.test', 'Senha@123')['status'] === 401, $r['texto']);
$r = post('conta/atualizarPerfil.php', ['nome_escola' => str_repeat('x', 151)] + $perfil, $tConta);
verificar('perfil: nome de escola longo demais: 422', $r['status'] === 422, $r['texto']);

$tOutroAparelho = $entrar('nova@escola.test', 'Senha@123')['json']['dados']['token'] ?? '';
$r = post('conta/alterarSenha.php', ['senha_atual' => 'errada', 'senha_nova' => 'NovaSenha@1'], $tConta);
verificar('senha: atual errada: 403 (sem derrubar a sessão)', $r['status'] === 403 && post('user/listarSalas.php', [], $tConta)['status'] === 200, $r['texto']);
$r = post('conta/alterarSenha.php', ['senha_atual' => 'Senha@123', 'senha_nova' => '123'], $tConta);
verificar('senha: nova fraca: 422 com o que falta (tamanho, maiúscula, minúscula e especial)', $r['status'] === 422
    && count($r['json']['dados']['requisitos_senha'] ?? []) === 4, $r['texto']);
// O e-mail da conta já é o novo (nova@escola.test): o perfil vai com ele.
$perfilAtual = ['email_coordenacao' => 'nova@escola.test'] + $perfil;
$r = post('conta/atualizarPerfil.php', ['cargo' => 'vice_direcao'] + $perfilAtual, $tConta);
verificar('perfil: a pessoa informa o próprio cargo', $r['status'] === 200 && ($r['json']['dados']['cargo'] ?? '') === 'vice_direcao', $r['texto']);
$r = post('conta/atualizarPerfil.php', ['cargo' => 'reitor'] + $perfilAtual, $tConta);
verificar('perfil: cargo fora da lista: 422', $r['status'] === 422, $r['texto']);
$r = post('conta/atualizarPerfil.php', $perfilAtual, $tConta);
verificar('perfil: sem mandar o cargo, ele continua o mesmo', ($r['json']['dados']['cargo'] ?? '') === 'vice_direcao', $r['texto']);
$r = post('conta/alterarSenha.php', ['senha_atual' => 'Senha@123', 'senha_nova' => 'Senha@123'], $tConta);
verificar('senha: igual à atual: 422', $r['status'] === 422, $r['texto']);
$r = post('conta/alterarSenha.php', ['senha_atual' => 'Senha@123', 'senha_nova' => 'NovaSenha@1'], $tConta);
verificar('senha: trocada, desconectando os outros aparelhos', $r['status'] === 200 && ($r['json']['dados']['sessoes_encerradas'] ?? 0) >= 1, $r['texto']);
verificar('...o outro aparelho caiu', post('user/listarSalas.php', [], $tOutroAparelho)['status'] === 401);
verificar('...este continua conectado', post('user/listarSalas.php', [], $tConta)['status'] === 200);
verificar('...a senha antiga não entra mais', $entrar('nova@escola.test', 'Senha@123')['status'] === 401);
verificar('...a nova entra', $entrar('nova@escola.test', 'NovaSenha@1')['status'] === 200);

$tCoord2 = $entrar('coord2@escola.test', 'Senha@123')['json']['dados']['token'] ?? '';
$r = post('conta/preferencias.php', [], $tConta);
verificar('preferências: padrão para quem nunca salvou', ($r['json']['dados'] ?? null) === ['tema' => 'sistema', 'sons_ativos' => true, 'volume_sons' => 70,
    'som_confirmacao' => true, 'som_notificacao' => true, 'som_erro' => true], $r['texto']);
$r = post('conta/salvarPreferencias.php', ['tema' => 'escuro', 'volume_sons' => 30, 'som_erro' => false], $tConta);
verificar('preferências: salvar só o que mudou', $r['status'] === 200 && ($r['json']['dados']['tema'] ?? '') === 'escuro' && ($r['json']['dados']['sons_ativos'] ?? null) === true, $r['texto']);
$r = post('conta/preferencias.php', [], $tConta);
verificar('preferências: ficam salvas na conta', ($r['json']['dados']['volume_sons'] ?? null) === 30 && ($r['json']['dados']['som_erro'] ?? null) === false, $r['texto']);
$r = post('conta/salvarPreferencias.php', ['sons_ativos' => false], $tConta);
verificar('preferências: segunda gravação atualiza a mesma linha', $r['status'] === 200 && ($r['json']['dados']['tema'] ?? '') === 'escuro'
    && (int) valor("SELECT count(*) FROM tb_preferencia_coordenacao WHERE id_coordenacao = ?", [$idCoord1]) === 1, $r['texto']);
$r = post('conta/salvarPreferencias.php', ['volume_sons' => 150], $tConta);
verificar('preferências: volume fora da faixa: 422', $r['status'] === 422, $r['texto']);
verificar('preferências: outra conta não é afetada', (post('conta/preferencias.php', [], $tCoord2)['json']['dados']['tema'] ?? '') === 'sistema');

$chamado = ['categoria' => 'biometria', 'assunto' => 'Rosto não reconhecido', 'mensagem' => 'O tablet não acha o rosto de nenhum aluno.'];
$r = post('suporte/abrirChamado.php', ['categoria' => 'outra'] + $chamado, $tConta);
verificar('chamado: categoria inválida: 422', $r['status'] === 422, $r['texto']);
$r = post('suporte/abrirChamado.php', $chamado, $tConta);
verificar('chamado: aberto (201, status "aberto")', $r['status'] === 201 && ($r['json']['dados']['status'] ?? '') === 'aberto', $r['texto']);
$idChamado = (int) ($r['json']['dados']['id_chamado'] ?? 0);
sql("UPDATE tb_chamado_suporte SET status = 'respondido', resposta = 'Atualize o app para a versão 2.0.1.', respondido_em = NOW() WHERE id_chamado = ?", [$idChamado]);
$r = post('suporte/listarChamados.php', [], $tConta);
verificar('chamados: a coordenação vê a resposta da equipe', ($r['json']['dados'][0]['resposta'] ?? '') === 'Atualize o app para a versão 2.0.1.', $r['texto']);
verificar('chamados: outra conta não vê', (post('suporte/listarChamados.php', [], $tCoord2)['json']['dados'] ?? null) === []);
for ($i = 0; $i < 9; $i++) {
    sql("INSERT INTO tb_chamado_suporte (id_coordenacao, categoria, assunto, mensagem) VALUES (?, 'duvida', 'Teste', 'Mensagem de teste')", [$idCoord1]);
}
$r = post('suporte/abrirChamado.php', $chamado, $tConta);
verificar('chamados: limite de 10 por dia: 429', $r['status'] === 429, $r['texto']);

// ------------------------------------------------------------ senhas
secao('Senhas: Argon2id, limite de tentativas e senha nova pelo e-mail');
// Cada bloco simula uma rede (o IP vem do X-Forwarded-For, como atrás do
// balanceador do Render): os erros daqui não esbarram no resto da suíte.
$redeA = 'X-Forwarded-For: 198.51.100.7';
$redeB = 'X-Forwarded-For: 198.51.100.8';
$redeC = 'X-Forwarded-For: 198.51.100.9';
$tentar = fn(string $email, string $senha, string $rede) => post('user/selectCoord.php', ['email' => $email, 'senha' => $senha], null, [$rede]);
$hashDe = fn(string $email) => (string) valor("SELECT senha_coordenacao FROM tb_coordenacao WHERE email_coordenacao = ?", [$email]);

verificar('senha de conta nova guardada em Argon2id', str_starts_with($hashDe('coord2@escola.test'), '$argon2id$'));
sql("UPDATE tb_coordenacao SET senha_coordenacao = ? WHERE email_coordenacao = 'coord2@escola.test'", [password_hash('Senha@123', PASSWORD_BCRYPT)]);
$r = $tentar('coord2@escola.test', 'Senha@123', $redeA);
verificar('hash bcrypt antigo: entra e o hash vira Argon2id', $r['status'] === 200 && str_starts_with($hashDe('coord2@escola.test'), '$argon2id$'), $r['texto']);

for ($i = 0; $i < 4; $i++) {
    $tentar('nova@escola.test', 'errada', $redeA);
}
verificar('4 erros e a senha certa: entra (e os erros são zerados)', $tentar('nova@escola.test', 'NovaSenha@1', $redeA)['status'] === 200);
for ($i = 0; $i < 5; $i++) {
    $tentar('nova@escola.test', 'errada', $redeA);
}
$r = $tentar('nova@escola.test', 'NovaSenha@1', $redeA);
verificar('5 erros em seguida: até a senha certa espera (429), com o tempo no Retry-After', $r['status'] === 429
    && str_contains($r['json']['mensagem'] ?? '', 'espere') && ($r['json']['dados']['espera_segundos'] ?? 0) > 800
    && (int) ($r['h']['retry-after'] ?? 0) === ($r['json']['dados']['espera_segundos'] ?? -1), $r['texto']);
verificar('o bloqueio é do e-mail: de outra rede também espera', $tentar('nova@escola.test', 'NovaSenha@1', $redeB)['status'] === 429);
verificar('outro e-mail na mesma rede entra normalmente', $tentar('coord2@escola.test', 'Senha@123', $redeA)['status'] === 200);
verificar('o banco guarda só hashes: nem o e-mail nem o IP em texto', (int) valor("SELECT count(*) FROM tb_tentativa_login") >= 5
    && (int) valor("SELECT count(*) FROM tb_tentativa_login WHERE chave_email LIKE '%@%' OR chave_rede LIKE '%.%'") === 0);
for ($i = 0; $i < 20; $i++) {
    $tentar("robo$i@escola.test", 'Qualquer@1', $redeC);
}
verificar('20 erros da mesma rede: a rede espera, até com conta e senha certas', $tentar('coord2@escola.test', 'Senha@123', $redeC)['status'] === 429);
verificar('...e as outras redes seguem entrando', $tentar('coord2@escola.test', 'Senha@123', $redeB)['status'] === 200);
sql("DELETE FROM tb_tentativa_login");

// "Esqueci minha senha". Os links mais antigos da conta saem da janela de
// 24 horas, para o limite de envios (5 por dia) não atrapalhar o teste.
sql("UPDATE tb_verificacao_email SET criado_em = criado_em - INTERVAL '2 days' WHERE id_coordenacao = ?", [$idCoord1]);
$antes = count(emailsPara('nova@escola.test'));
$tNova = $tentar('nova@escola.test', 'NovaSenha@1', $redeB)['json']['dados']['token'] ?? '';
$r = post('conta/esqueciSenha.php', ['email' => 'Nova@Escola.test']);
$emailsSenha = array_slice(emailsPara('nova@escola.test'), $antes);
verificar('esqueci a senha: resposta genérica e o link sai por e-mail, valendo 1 hora', $r['status'] === 200 && count($emailsSenha) === 1
    && str_contains((string) ($emailsSenha[0]['texto'] ?? ''), "$siteUrl/redefinir-senha?codigo=") && str_contains((string) ($emailsSenha[0]['texto'] ?? ''), '1 hora'), $r['texto']);
$semConta = post('conta/esqueciSenha.php', ['email' => 'ninguem@escola.test']);
verificar('e-mail sem conta: a mesma resposta, e nenhum e-mail sai', $semConta['status'] === 200
    && ($semConta['json']['mensagem'] ?? '') === ($r['json']['mensagem'] ?? '-') && emailsPara('ninguem@escola.test') === []);
$r = post('conta/esqueciSenha.php', ['email' => 'nova@escola.test']);
verificar('pedir de novo em menos de um minuto: mesma resposta, sem outro e-mail', $r['status'] === 200 && count(emailsPara('nova@escola.test')) === $antes + 1);
$r = post('conta/esqueciSenha.php', ['email' => 'sem-arroba']);
verificar('e-mail inválido: 422', $r['status'] === 422, $r['texto']);
$codigoSenha = codigoDaSenhaNova('nova@escola.test');
verificar('o banco guarda só o hash do código, para 1 hora', valor("SELECT finalidade FROM tb_verificacao_email WHERE token_hash = ?", [hash('sha256', $codigoSenha)]) === 'redefinir_senha'
    && (int) valor("SELECT EXTRACT(EPOCH FROM (expira_em - criado_em))::int FROM tb_verificacao_email WHERE token_hash = ?", [hash('sha256', $codigoSenha)]) === 3600);
$r = post('conta/confirmarEmail.php', ['codigo' => $codigoSenha]);
verificar('o link de senha nova não serve para confirmar e-mail: 404', $r['status'] === 404, $r['texto']);
$r = post('conta/redefinirSenha.php', ['codigo' => $codigoSenha, 'senha_nova' => 'fraca']);
verificar('senha nova fraca: 422 com a lista do que falta, e o link continua valendo', $r['status'] === 422
    && count($r['json']['dados']['requisitos_senha'] ?? []) >= 3
    && valor("SELECT usado_em FROM tb_verificacao_email WHERE token_hash = ?", [hash('sha256', $codigoSenha)]) === null, $r['texto']);
$r = post('conta/redefinirSenha.php', ['codigo' => $codigoSenha, 'senha_nova' => 'Recuperada#2026']);
verificar('senha nova criada, desconectando todos os aparelhos', $r['status'] === 200 && ($r['json']['dados']['sessoes_encerradas'] ?? 0) >= 1, $r['texto']);
verificar('...a sessão aberta antes caiu', post('user/listarSalas.php', [], $tNova)['status'] === 401);
verificar('...a senha antiga não entra mais', $tentar('nova@escola.test', 'NovaSenha@1', $redeB)['status'] === 401);
verificar('...a nova entra, guardada em Argon2id', $tentar('nova@escola.test', 'Recuperada#2026', $redeB)['status'] === 200
    && str_starts_with($hashDe('nova@escola.test'), '$argon2id$'));
$r = post('conta/redefinirSenha.php', ['codigo' => $codigoSenha, 'senha_nova' => 'Outra#Senha99']);
verificar('o mesmo link de novo: 410', $r['status'] === 410, $r['texto']);
$r = post('conta/redefinirSenha.php', ['codigo' => str_repeat('a', 64), 'senha_nova' => 'Outra#Senha99']);
verificar('código que não existe: 404', $r['status'] === 404, $r['texto']);
sql("UPDATE tb_verificacao_email SET criado_em = criado_em - INTERVAL '2 minutes' WHERE id_coordenacao = ?", [$idCoord1]);
post('conta/esqueciSenha.php', ['email' => 'nova@escola.test']);
$codigoVencido = codigoDaSenhaNova('nova@escola.test');
sql("UPDATE tb_verificacao_email SET expira_em = NOW() - INTERVAL '1 minute' WHERE token_hash = ?", [hash('sha256', $codigoVencido)]);
$r = post('conta/redefinirSenha.php', ['codigo' => $codigoVencido, 'senha_nova' => 'Outra#Senha99']);
verificar('link vencido: 410', $codigoVencido !== $codigoSenha && $r['status'] === 410, $r['texto']);

// ------------------------------------------------------------ resultado
$passaram = $total - count($falhas);
echo "\nResultado: $passaram/$total verificações passaram.\n";
if (!empty($falhas)) {
    echo "\nFALHARAM:\n";
    foreach ($falhas as $falha) {
        echo "  - $falha\n";
    }
    exit(1);
}
exit(0);
