<?php
/**
 * Testes de integração da API: requisições HTTP de verdade contra a API
 * rodando (php -S no CI, ou o container Docker com Apache) e um
 * PostgreSQL de teste com o schema de produção (migrações 001-005).
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
 *   CADASTRO_CODIGO_ACESSO  código de cadastro configurado na API
 *   CORS_ORIGENS            a primeira origem é usada nos testes de CORS
 *   WHATSAPP_LOG            arquivo onde tests/mock_whatsapp.php registra os envios
 *   APACHE=1                liga os testes que dependem do .htaccess
 *
 * Rodar: php tests/integracao_test.php
 */

$api = rtrim(getenv('API_URL') ?: 'http://127.0.0.1:8080', '/');
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$apache = getenv('APACHE') === '1';
$jwtSecret = (string) getenv('JWT_SECRET_KEY');
$codigoCadastro = (string) getenv('CADASTRO_CODIGO_ACESSO');
$origens = array_map('trim', explode(',', (string) getenv('CORS_ORIGENS')));
$origem = $origens[0] ?? '';
$logWhatsapp = getenv('WHATSAPP_LOG') ?: sys_get_temp_dir() . '/whatsapp.log';

$bancoLocal = in_array($dbHost, ['127.0.0.1', 'localhost'], true) || preg_match('/^[a-z0-9-]+$/i', $dbHost) === 1;
if (getenv('BANCO_DE_TESTE') !== '1' || !$bancoLocal) {
    fwrite(STDERR, "Recusado: este teste apaga o banco. Use BANCO_DE_TESTE=1 com um banco local (localhost ou serviço do Docker).\n");
    exit(2);
}
foreach (['JWT_SECRET_KEY' => $jwtSecret, 'CADASTRO_CODIGO_ACESSO' => $codigoCadastro, 'CORS_ORIGENS' => $origem] as $nome => $valor) {
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

$hoje = (new DateTimeImmutable('today', $fuso))->format('Y-m-d');
$ontem = (new DateTimeImmutable('today', $fuso))->modify('-1 day')->format('Y-m-d');
$inicioMesLocal = (new DateTimeImmutable('first day of this month', $fuso))->format('Y-m-d');

// ------------------------------------------------------------ preparo
secao('Preparo (banco limpo)');
$pdo->exec("
    TRUNCATE tb_ocorrencia_pendente, tb_checkin_biometrico, tb_dispositivo_aluno, tb_codigo_vinculo,
             tb_notificacao, tb_alerta_nivel, tb_alerta_encerrado, tb_acompanhamento, tb_verificacao_facial,
             tb_biometria_facial, tb_aluno_responsavel, tb_responsavel, tb_falta, tb_atraso, tb_aluno,
             tb_sala, tb_coordenacao RESTART IDENTITY CASCADE
");
$pdo->exec("DELETE FROM tb_escola WHERE id_escola <> 1");
@unlink($logWhatsapp);
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
secao('Cadastro de coordenação e login');
$cadastrar = fn(string $email, ?string $codigo) => post('user/addCoord.php',
    ['nome' => 'Coordenação Teste', 'email' => $email, 'senha' => 'Senha@123'] + ($codigo === null ? [] : ['codigo_acesso' => $codigo]));
$r = $cadastrar('coord1@escola.test', null);
verificar('cadastro sem código de acesso: 403', $r['status'] === 403, $r['texto']);
$r = $cadastrar('coord1@escola.test', 'codigo-errado');
verificar('cadastro com código errado: 403', $r['status'] === 403, $r['texto']);
$r = $cadastrar('Coord1@Escola.test', $codigoCadastro);
verificar('cadastro com o código certo: 200', $r['status'] === 200, $r['texto']);
verificar('email gravado em minúsculas', valor("SELECT email_coordenacao FROM tb_coordenacao ORDER BY id_coordenacao LIMIT 1") === 'coord1@escola.test');
$r = $cadastrar('COORD1@escola.test', $codigoCadastro);
verificar('mesmo email com outra caixa: 409', $r['status'] === 409, $r['texto']);
$r = $cadastrar('coord2@escola.test', $codigoCadastro);
verificar('segunda conta: 200', $r['status'] === 200, $r['texto']);
$idCoord1 = (int) valor("SELECT id_coordenacao FROM tb_coordenacao WHERE email_coordenacao = 'coord1@escola.test'");
verificar('conta nova cai na escola padrão (1)', (int) valor("SELECT id_escola FROM tb_coordenacao WHERE id_coordenacao = ?", [$idCoord1]) === 1);
sql("UPDATE tb_coordenacao SET id_escola = ? WHERE email_coordenacao = 'coord2@escola.test'", [$escola2]);

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

// ------------------------------------------------------------ faltas e atrasos
secao('Faltas e atrasos');
$r = post('user/marcarFalta.php', ['id_aluno' => $ana, 'motivo_falta' => 'Consulta'], $t1);
verificar('lançar falta: 200', $r['status'] === 200, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana, 'motivo_falta' => 'Consulta'], $t1);
verificar('segunda falta no mesmo dia: 409', $r['status'] === 409, $r['texto']);
verificar('uma falta só no banco', (int) valor("SELECT count(*) FROM tb_falta WHERE id_aluno = ?", [$ana]) === 1);
$r = post('user/marcarAtraso.php', ['id_aluno' => $ana], $t1);
verificar('lançar atraso: 200', $r['status'] === 200, $r['texto']);
$r = post('user/marcarAtraso.php', ['id_aluno' => $ana], $t1);
verificar('segundo atraso no mesmo dia: 409', $r['status'] === 409, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana, 'dta_falta' => $ontem], $t1);
verificar('falta com data informada (ontem): 200', $r['status'] === 200, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana, 'dta_falta' => "$ontem 21:30"], $t1);
verificar('21:30 de ontem é o mesmo dia de ontem (fuso da escola): 409', $r['status'] === 409, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana, 'dta_falta' => '2099-01-01'], $t1);
verificar('data no futuro: 422', $r['status'] === 422, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana, 'dta_falta' => 'ontem'], $t1);
verificar('data em formato inválido: 422 (antes virava 500)', $r['status'] === 422, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana, 'motivo_falta' => str_repeat('x', 151)], $t1);
verificar('motivo com mais de 150 caracteres: 422 (antes virava 500)', $r['status'] === 422, $r['texto']);
$r = post('user/marcarFalta.php', ['id_aluno' => $ana], $t2);
verificar('outra escola lançando falta no aluno: 404', $r['status'] === 404, $r['texto']);

$corrida = paralelo(array_fill(0, 4, ['user/marcarFalta.php', ['id_aluno' => $bruno], $t1]));
$statusCorrida = array_column($corrida, 'status');
sort($statusCorrida);
verificar('4 lançamentos simultâneos: 1 grava e 3 recebem 409', $statusCorrida === [200, 409, 409, 409], $statusCorrida);
verificar('lançamentos simultâneos: uma falta só no banco', (int) valor("SELECT count(*) FROM tb_falta WHERE id_aluno = ?", [$bruno]) === 1);
$r = post('user/marcarFalta.php', ['id_aluno' => $bruno, 'dta_falta' => "$ontem 22:30"], $t1);
$esperadoUtc = (new DateTimeImmutable("$ontem 22:30", $fuso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
verificar('falta às 22:30 locais é gravada em UTC (01:30 do dia seguinte)', $r['status'] === 200
    && valor("SELECT to_char(dta_falta, 'YYYY-MM-DD HH24:MI:SS') FROM tb_falta WHERE id_falta = ?", [$r['json']['dados']['id_falta'] ?? 0]) === $esperadoUtc, $r['texto']);

// ------------------------------------------------------------ histórico e contagem
secao('Histórico e contagem por dia');
$r = post('user/historicoAluno.php', ['id_aluno' => $ana, 'data_inicio' => $ontem, 'data_fim' => $hoje], $t1);
verificar('histórico de ontem a hoje: 2 faltas e 1 atraso', count($r['json']['dados']['faltas'] ?? []) === 2 && count($r['json']['dados']['atrasos'] ?? []) === 1, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $bruno, 'data_inicio' => $ontem, 'data_fim' => $ontem], $t1);
verificar('falta das 22:30 aparece no dia local certo (ontem)', count($r['json']['dados']['faltas'] ?? []) === 1, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $ana, 'data_inicio' => '2026-13-01'], $t1);
verificar('período inválido: 422', $r['status'] === 422, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $ana, 'data_inicio' => $hoje, 'data_fim' => $ontem], $t1);
verificar('início depois do fim: 422', $r['status'] === 422, $r['texto']);
$r = post('user/historicoAluno.php', ['id_aluno' => $ana], $t2);
verificar('histórico de aluno de outra escola: 404', $r['status'] === 404, $r['texto']);
// Uma linha duplicada inserida direto no banco (como os 2 atrasos com
// 26 s de diferença que existem em produção) conta como um dia só.
sql("INSERT INTO tb_falta (id_aluno, id_coordenacao, dta_falta) SELECT id_aluno, id_coordenacao, dta_falta + INTERVAL '1 minute' FROM tb_falta WHERE id_aluno = ? ORDER BY id_falta LIMIT 1", [$ana]);
$r = post('user/filtroAluno.php', ['busca' => 'Ana Teste', 'pagina' => 1, 'por_pagina' => 10], $t1);
$esperadas = $ontem >= $inicioMesLocal ? 2 : 1;
verificar("faltas do mês contam dias distintos ($esperadas), não linhas", ($r['json']['dados']['alunos'][0]['faltas'] ?? null) === $esperadas, $r['texto']);

// ------------------------------------------------------------ responsáveis
secao('Responsáveis');
$cpfA = gerarCpf(1);
$cpfB = gerarCpf(2);
$cpfC = gerarCpf(3);
$addResp = fn(string $token, int $aluno, string $cpf, string $telefone, string $genero = 'Outro') => post('responsavel/addResponsavel.php', [
    'id_aluno' => $aluno, 'nome_responsavel' => 'Maria Responsável', 'cpf_responsavel' => $cpf,
    'telefone_responsavel' => $telefone, 'email_responsavel' => null, 'genero_responsavel' => $genero,
], $token);
$r = $addResp($t1, $carla, $cpfA, '(11) 99999-0001');
$respA = (int) ($r['json']['dados']['id_responsavel'] ?? 0);
verificar('cadastrar responsável com gênero "Outro": 200 (antes: 500 pela restrição do banco)', $r['status'] === 200 && $respA > 0, $r['texto']);
verificar('gênero gravado como "Indefinido" (valor aceito pelo banco)', valor("SELECT genero_responsavel FROM tb_responsavel WHERE id_responsavel = ?", [$respA]) === 'Indefinido');
$r = $addResp($t1, $carla, '123.456.789-00', '(11) 99999-0009');
verificar('CPF com dígito verificador errado: 422', $r['status'] === 422, $r['texto']);
$r = $addResp($t1, $diego, $cpfA, '(11) 99999-0002');
verificar('mesmo CPF para outro aluno da escola: 409 com o id para vincular', $r['status'] === 409 && ($r['json']['dados']['id_responsavel'] ?? null) === $respA, $r['texto']);
$r = post('responsavel/buscarResponsavelPorCpf.php', ['cpf_responsavel' => $cpfA], $t1);
verificar('buscar por CPF na própria escola: encontra', ($r['json']['dados']['id_responsavel'] ?? null) === $respA, $r['texto']);
$r = post('responsavel/vincularResponsavel.php', ['id_aluno' => $diego, 'id_responsavel' => $respA], $t1);
verificar('vincular o mesmo responsável a outro aluno (irmãos): 200', $r['status'] === 200, $r['texto']);
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
verificar('listar responsáveis do aluno: 1', count($r['json']['dados'] ?? []) === 1, $r['texto']);
$respC = (int) ($addResp($t1, $fabio, $cpfC, '(11) 99999-0005')['json']['dados']['id_responsavel'] ?? 0);
$r = post('responsavel/removerVinculo.php', ['id_aluno' => $fabio, 'id_responsavel' => $respC], $t1);
verificar('remover vínculo: 200', $r['status'] === 200, $r['texto']);
$r = $addResp($t2, $zeca, $cpfC, '(11) 99999-0006', 'Feminino');
verificar('CPF sem vínculo nenhum é reaproveitado no novo cadastro', $r['status'] === 200 && ($r['json']['dados']['id_responsavel'] ?? null) === $respC, $r['texto']);

// ------------------------------------------------------------ notificações
secao('Notificações aos responsáveis');
$r = post('user/marcarFalta.php', ['id_aluno' => $carla, 'motivo_falta' => 'Doente'], $t1);
verificar('falta de aluno com responsável: 200', $r['status'] === 200, $r['texto']);
verificar('notificação criada com o status do banco ("Pendente") - antes era recusada e nunca existia',
    valor("SELECT status_notificacao FROM tb_notificacao WHERE id_aluno = ? ORDER BY id_notificacao DESC LIMIT 1", [$carla]) === 'Pendente');
$r = post('notificacao/listarNotificacoes.php', ['status' => 'pendente'], $t1);
$idNotificacao = (int) ($r['json']['dados']['notificacoes'][0]['id_notificacao'] ?? 0);
verificar('listar pendentes: a notificação aparece como "pendente"', $idNotificacao > 0 && ($r['json']['dados']['notificacoes'][0]['status_notificacao'] ?? '') === 'pendente', $r['texto']);
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

// ------------------------------------------------------------ alertas e contato automático
secao('Alertas, escalonamento e contato automático');
// Diego (responsável A, telefone 11 99999-0001) e Gabi (responsável cujo
// número simula falha do provedor): 5 faltas em dias úteis seguidos e um
// nível 3 gravado há 8 dias -> nível 4 -> contato automático.
$respG = (int) ($addResp($t1, $gabi, gerarCpf(4), '(11) 98888-0000')['json']['dados']['id_responsavel'] ?? 0);
foreach ([$diego, $gabi] as $aluno) {
    foreach (ultimosDiasUteis(5) as $dia) {
        sql("INSERT INTO tb_falta (id_aluno, id_coordenacao, dta_falta) VALUES (?, ?, ?)", [$aluno, $idCoord1, localParaUtcTeste("$dia 10:00")]);
    }
    sql("INSERT INTO tb_alerta_nivel (id_aluno, nivel, motivo_nivel, atualizado_em) VALUES (?, 3, 'teste', NOW() - INTERVAL '8 days')", [$aluno]);
}
// Fábio não tem alerta nenhum, mas tem um nível antigo esquecido.
sql("INSERT INTO tb_alerta_nivel (id_aluno, nivel, motivo_nivel, atualizado_em) VALUES (?, 3, 'antigo', NOW() - INTERVAL '30 days')", [$fabio]);

$respostas = paralelo(array_fill(0, 3, ['user/alertas.php', [], $t1]));
verificar('3 aberturas simultâneas da tela de alertas: todas 200', array_column($respostas, 'status') === [200, 200, 200], array_column($respostas, 'texto'));
$contatosDiego = 0;
foreach ($respostas as $resposta) {
    foreach ($resposta['json']['dados']['contatos'] ?? [] as $contato) {
        if ($contato['id_aluno'] === $diego) $contatosDiego++;
    }
}
verificar('o contato do Diego aparece em UMA resposta só', $contatosDiego === 1, $contatosDiego);
verificar('nível 4 gravado para o Diego', (int) valor("SELECT nivel FROM tb_alerta_nivel WHERE id_aluno = ?", [$diego]) === 4);
verificar('um único WhatsApp registrado para o Diego (sem duplicar)', (int) valor("SELECT count(*) FROM tb_notificacao WHERE id_aluno = ? AND canal = 'whatsapp'", [$diego]) === 1);
$envios = enviosWhatsapp('5511999990001');
verificar('o provedor recebeu exatamente 1 envio para o número, no formato internacional', count($envios) === 1, $envios);
verificar('envio autenticado com o token do provedor', ($envios[0]['auth'] ?? '') === 'Bearer ' . getenv('WHATSAPP_TOKEN'), $envios);
verificar('mensagem registrada como "Enviado"', valor("SELECT status_notificacao FROM tb_notificacao WHERE id_aluno = ? AND canal = 'whatsapp'", [$diego]) === 'Enviado');
verificar('falha do provedor fica registrada como "Erro" (Gabi)', valor("SELECT status_notificacao FROM tb_notificacao WHERE id_aluno = ? AND canal = 'whatsapp'", [$gabi]) === 'Erro');
verificar('nível antigo do Fábio (sem alerta) foi zerado', (int) valor("SELECT nivel FROM tb_alerta_nivel WHERE id_aluno = ?", [$fabio]) === 0);
post('user/alertas.php', [], $t1);
verificar('abrir os alertas de novo no mesmo dia não reenvia', count(enviosWhatsapp('5511999990001')) === 1
    && (int) valor("SELECT count(*) FROM tb_notificacao WHERE id_aluno = ? AND canal = 'whatsapp'", [$diego]) === 1);
$r = post('user/alertas.php', ['id_sala' => $sala1], $t1);
$alertasDiego = array_filter($r['json']['dados']['alertas'] ?? [], fn($a) => $a['id_aluno'] === $diego);
verificar('filtro por turma (id_sala) mantém os alertas do Diego, com nível 4', count($alertasDiego) > 0 && reset($alertasDiego)['nivel'] === 4, $r['texto']);
$r = post('user/alertas.php', ['id_sala' => $sala1b], $t1);
verificar('filtro por outra turma não traz o Diego', count(array_filter($r['json']['dados']['alertas'] ?? [], fn($a) => $a['id_aluno'] === $diego)) === 0, $r['texto']);
$r = post('user/alertas.php', [], $t2);
verificar('outra escola não vê os alertas', ($r['json']['dados']['total'] ?? -1) === 0, $r['texto']);
$r = post('user/encerrarAlerta.php', ['id_aluno' => $diego], $t2);
verificar('outra escola encerrando alerta: 404', $r['status'] === 404, $r['texto']);
$r = post('user/encerrarAlerta.php', ['id_aluno' => $diego], $t1);
verificar('encerrar alerta: 200', $r['status'] === 200, $r['texto']);
$r = post('user/alertas.php', [], $t1);
verificar('alerta encerrado some da lista no mesmo dia', count(array_filter($r['json']['dados']['alertas'] ?? [], fn($a) => $a['id_aluno'] === $diego)) === 0, $r['texto']);

// ------------------------------------------------------------ dashboard
secao('Dashboard');
$r = post('user/dashboard.php', [], $t2);
verificar('dashboard da escola 2 lista só a turma dela', array_column($r['json']['dados']['turmas'] ?? [], 'nome_sala') === ['2A'], $r['texto']);
verificar('dashboard da escola 2 não soma faltas/atrasos da escola 1', ($r['json']['dados']['frequencia_geral'] ?? null) == 100 && ($r['json']['dados']['atrasos_mes'] ?? null) === 0, $r['texto']);
$r = post('user/dashboard.php', [], $t1);
verificar('dashboard da escola 1 lista 1A e 1B', array_column($r['json']['dados']['turmas'] ?? [], 'nome_sala') === ['1A', '1B'], $r['texto']);
verificar('dashboard da escola 1 tem 6 meses de evolução', count($r['json']['dados']['evolucao_mensal'] ?? []) === 6, $r['texto']);

// ------------------------------------------------------------ app do aluno
secao('App do aluno: vínculo, check-in e ocorrências');
$r = post('digital/gerarCodigoVinculo.php', ['id_aluno' => $elisa], $t2);
verificar('outra escola gerando código de vínculo: 404', $r['status'] === 404, $r['texto']);
$r = post('digital/gerarCodigoVinculo.php', ['id_aluno' => $elisa], $t1);
$codigo = $r['json']['dados']['codigo'] ?? '';
$validade = (int) valor("SELECT EXTRACT(EPOCH FROM expira_em - criado_em)::int FROM tb_codigo_vinculo WHERE codigo = ?", [$codigo]);
verificar('código vale 15 minutos no relógio do banco (antes valia 2h15)', abs($validade - 900) <= 5, $validade);
$uuid = 'aparelho-ci-' . bin2hex(random_bytes(4));
$r = post('digital/vincularDispositivo.php', ['codigo' => strtolower($codigo), 'device_uuid' => $uuid]);
$segredo = $r['json']['dados']['device_secret'] ?? '';
verificar('vincular aparelho: 200 e segredo entregue', $r['status'] === 200 && strlen($segredo) === 64, $r['texto']);
$r = post('digital/vincularDispositivo.php', ['codigo' => $codigo, 'device_uuid' => 'outro-aparelho']);
verificar('mesmo código de novo: 401', $r['status'] === 401, $r['texto']);
$codigo2 = post('digital/gerarCodigoVinculo.php', ['id_aluno' => $elisa], $t1)['json']['dados']['codigo'] ?? '';
$r = post('digital/vincularDispositivo.php', ['codigo' => $codigo2, 'device_uuid' => $uuid]);
$segredo2 = $r['json']['dados']['device_secret'] ?? '';
verificar('vincular de novo o MESMO aparelho: 200 (antes: 500 por device_uuid único)', $r['status'] === 200 && $segredo2 !== '' && $segredo2 !== $segredo, $r['texto']);
$r = post('digital/checkinBiometrico.php', assinado($uuid, $segredo, 'atraso'));
verificar('segredo antigo deixou de valer: 401', $r['status'] === 401, $r['texto']);
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

$ocorrencia = assinado($uuid, $segredo2, 'falta') + ['motivo' => 'Consulta médica', 'observacao' => 'Levo atestado', 'responsavel_ciente' => true];
$r = post('ocorrencia/enviarOcorrencia.php', $ocorrencia);
verificar('ocorrência enviada pelo app: 201 pendente', $r['status'] === 201 && ($r['json']['dados']['status'] ?? '') === 'pendente', $r['texto']);
verificar('ainda nenhuma falta no banco (só depois da aprovação)', (int) valor("SELECT count(*) FROM tb_falta WHERE id_aluno = ?", [$elisa]) === 0);
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
// Aprovada "no dia seguinte": a falta tem de ser do dia do envio.
sql("UPDATE tb_ocorrencia_pendente SET criado_em = criado_em - INTERVAL '1 day' WHERE id_ocorrencia = ?", [$idOcorrencia]);
$diaEnvio = valor("SELECT " . dia('criado_em') . "::text FROM tb_ocorrencia_pendente WHERE id_ocorrencia = ?", [$idOcorrencia]);
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrencia, 'decisao' => 'aprovar', 'justificado' => true], $t1);
$idFaltaCriada = (int) ($r['json']['dados']['id_registro'] ?? 0);
verificar('aprovar: 200 e falta criada', $r['status'] === 200 && $idFaltaCriada > 0 && ($r['json']['dados']['ja_existia'] ?? null) === false, $r['texto']);
verificar('a falta é do dia do ENVIO, não do dia da aprovação', valor("SELECT " . dia('dta_falta') . "::text FROM tb_falta WHERE id_falta = ?", [$idFaltaCriada]) === $diaEnvio);
verificar('motivo escrito pelo aluno chegou na falta', valor("SELECT motivo_falta FROM tb_falta WHERE id_falta = ?", [$idFaltaCriada]) === 'Consulta médica');
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrencia, 'decisao' => 'rejeitar'], $t1);
verificar('segunda decisão na mesma ocorrência: 409', $r['status'] === 409, $r['texto']);
$idAtraso = (int) (post('user/marcarAtraso.php', ['id_aluno' => $elisa], $t1)['json']['dados']['id_atraso'] ?? 0);
post('ocorrencia/enviarOcorrencia.php', assinado($uuid, $segredo2, 'atraso') + ['motivo' => 'Ônibus atrasou']);
$idOcorrenciaAtraso = (int) valor("SELECT id_ocorrencia FROM tb_ocorrencia_pendente WHERE id_aluno = ? AND tipo = 'atraso'", [$elisa]);
$r = post('ocorrencia/revisarOcorrencia.php', ['id_ocorrencia' => $idOcorrenciaAtraso, 'decisao' => 'aprovar'], $t1);
verificar('aprovar atraso de um dia já lançado à mão não cria outro', $r['status'] === 200 && ($r['json']['dados']['ja_existia'] ?? null) === true
    && ($r['json']['dados']['id_registro'] ?? 0) === $idAtraso
    && (int) valor("SELECT count(*) FROM tb_atraso WHERE id_aluno = ?", [$elisa]) === 1, $r['texto']);
$r = post('digital/revogarDispositivo.php', ['id_aluno' => $elisa], $t2);
verificar('outra escola revogando aparelho: 404', $r['status'] === 404, $r['texto']);
$r = post('digital/revogarDispositivo.php', ['id_aluno' => $elisa], $t1);
verificar('revogar aparelho: 200', $r['status'] === 200 && ($r['json']['dados']['quantidade'] ?? 0) === 1, $r['texto']);
$r = post('digital/checkinBiometrico.php', assinado($uuid, $segredo2, 'presenca'));
verificar('aparelho revogado: 401', $r['status'] === 401, $r['texto']);

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
