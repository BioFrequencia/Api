<?php
/**
 * Testes unitários puros (sem banco, sem rede) dos helpers da API.
 * Não usa nenhum framework de propósito - são poucos testes, um script
 * simples com asserts é suficiente e roda em qualquer PHP sem depender
 * de Composer dev-dependencies.
 *
 * Rodar: php tests/helpers_test.php
 */

require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/validacaoResponsavel.php';
require_once __DIR__ . '/../helpers/frequencia.php';
require_once __DIR__ . '/../helpers/biometriaFacial.php';
require_once __DIR__ . '/../helpers/corsRegras.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../helpers/escalonamento.php';
require_once __DIR__ . '/../helpers/dispositivo.php';
require_once __DIR__ . '/../helpers/registroEvento.php';
require_once __DIR__ . '/../helpers/criptografia.php';

$totalTestes = 0;
$falhas = [];

function afirmar(string $descricao, bool $condicao): void {
    global $totalTestes, $falhas;
    $totalTestes++;
    if (!$condicao) {
        $falhas[] = $descricao;
    }
}

// ---------- campoPreenchido ----------
afirmar('campoPreenchido: string normal', campoPreenchido('João') === true);
afirmar('campoPreenchido: string vazia', campoPreenchido('') === false);
afirmar('campoPreenchido: só espaços', campoPreenchido('   ') === false);
afirmar('campoPreenchido: null', campoPreenchido(null) === false);
afirmar('campoPreenchido: número (não é string)', campoPreenchido(123) === false);

// ---------- emailValido ----------
afirmar('emailValido: email correto', emailValido('a@b.com') === true);
afirmar('emailValido: sem @', emailValido('a.com') === false);
afirmar('emailValido: vazio', emailValido('') === false);
afirmar('emailValido: null', emailValido(null) === false);
afirmar('emailValido: com espaço nas pontas', emailValido('  a@b.com  ') === true);

// ---------- senhaForte ----------
afirmar('senhaForte: 6+ caracteres', senhaForte('senha123') === true);
afirmar('senhaForte: menos de 6', senhaForte('abc') === false);
afirmar('senhaForte: vazia', senhaForte('') === false);
afirmar('senhaForte: limite customizado', senhaForte('12345678', 8) === true);

// ---------- inteiroValido ----------
afirmar('inteiroValido: inteiro positivo', inteiroValido(5) === true);
afirmar('inteiroValido: string numérica', inteiroValido('5') === true);
afirmar('inteiroValido: zero é inválido (ids começam em 1)', inteiroValido(0) === false);
afirmar('inteiroValido: negativo', inteiroValido(-3) === false);
afirmar('inteiroValido: decimal', inteiroValido(1.5) === false);
afirmar('inteiroValido: texto não numérico', inteiroValido('abc') === false);
afirmar('inteiroValido: null', inteiroValido(null) === false);

// ---------- dataNascimentoValida ----------
afirmar('dataNascimentoValida: data válida no passado', dataNascimentoValida('2012-05-10') === true);
afirmar('dataNascimentoValida: data futura', dataNascimentoValida(date('Y-m-d', strtotime('+1 day'))) === false);
afirmar('dataNascimentoValida: 30 de fevereiro (não existe)', dataNascimentoValida('2024-02-30') === false);
afirmar('dataNascimentoValida: formato errado', dataNascimentoValida('10/05/2012') === false);
afirmar('dataNascimentoValida: idade implausível (>120 anos)', dataNascimentoValida('1800-01-01') === false);
afirmar('dataNascimentoValida: vazia', dataNascimentoValida('') === false);
afirmar('dataNascimentoValida: hoje é válido (recém-nascido)', dataNascimentoValida(date('Y-m-d')) === true);

// ---------- calcularFrequencia / calcularStatusFrequencia ----------
afirmar('calcularFrequencia: 0 faltas = 100%', calcularFrequencia(0) === 100);
afirmar('calcularFrequencia: 20 faltas (todos os dias letivos) = 0%', calcularFrequencia(20) === 0);
afirmar('calcularFrequencia: nunca fica negativo mesmo com mais faltas que dias letivos', calcularFrequencia(50) === 0);
afirmar('calcularStatusFrequencia: 100% = Excelente', calcularStatusFrequencia(100) === 'Excelente');
afirmar('calcularStatusFrequencia: 95% = Regular', calcularStatusFrequencia(95) === 'Regular');
afirmar('calcularStatusFrequencia: 87% = Atenção', calcularStatusFrequencia(87) === 'Atenção');
afirmar('calcularStatusFrequencia: 80% = Em risco', calcularStatusFrequencia(80) === 'Em risco');
afirmar('calcularStatusFrequencia: limite exato 85% não é Em risco', calcularStatusFrequencia(85) === 'Atenção');
afirmar('calcularStatusFrequencia: limite exato 98% é Excelente', calcularStatusFrequencia(98) === 'Excelente');

// ---------- calcularFaltasConsecutivas ----------
afirmar('calcularFaltasConsecutivas: lista vazia = 0', calcularFaltasConsecutivas([]) === 0);
afirmar(
    'calcularFaltasConsecutivas: 3 dias seguidos (hoje, ontem, anteontem)',
    calcularFaltasConsecutivas([date('Y-m-d'), date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-2 day'))]) === 3
);
afirmar(
    'calcularFaltasConsecutivas: só uma falta há muito tempo = sequência encerrada (0)',
    calcularFaltasConsecutivas([date('Y-m-d', strtotime('-30 days'))]) === 0
);
// Este teste usava datas fixas de setembro sem fixar "hoje": passava no
// dia em que foi escrito e falhava depois (era a falha "antiga" que a
// AUDITORIA.md registrava como 50/51). "Hoje" agora é parâmetro.
afirmar(
    'calcularFaltasConsecutivas: sexta + segunda seguinte conta como consecutivo (tolera fim de semana)',
    calcularFaltasConsecutivas(['2026-09-14', '2026-09-11'], '2026-09-14') === 2 // segunda, sexta anterior
);
afirmar(
    'calcularFaltasConsecutivas: gap de mais de 3 dias corridos quebra a sequência',
    calcularFaltasConsecutivas([date('Y-m-d'), date('Y-m-d', strtotime('-6 days'))]) === 1
);
afirmar(
    'calcularFaltasConsecutivas: terça e sexta NÃO são consecutivas (quarta e quinta foram dias de aula)',
    calcularFaltasConsecutivas(['2026-10-30', '2026-10-27'], '2026-10-30') === 1
);
afirmar(
    'calcularFaltasConsecutivas: segunda a sexta = 5',
    calcularFaltasConsecutivas(['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'], '2026-10-30') === 5
);
afirmar(
    'calcularFaltasConsecutivas: a mesma data repetida conta uma vez',
    calcularFaltasConsecutivas(['2026-10-29', '2026-10-29', '2026-10-28'], '2026-10-29') === 2
);
afirmar(
    'calcularFaltasConsecutivas: sequência que terminou numa sexta continua "atual" na segunda',
    calcularFaltasConsecutivas(['2026-10-29', '2026-10-30'], '2026-11-02') === 2
);
afirmar(
    'calcularFaltasConsecutivas: com um dia útil sem falta depois, a sequência acabou',
    calcularFaltasConsecutivas(['2026-10-26', '2026-10-27'], '2026-10-29') === 0
);
afirmar('diasUteisEntre: sexta e segunda = 0', diasUteisEntre('2026-10-30', '2026-11-02') === 0);
afirmar('diasUteisEntre: terça e sexta = 2', diasUteisEntre('2026-10-27', '2026-10-30') === 2);
afirmar('diasUteisEntre: ordem não importa', diasUteisEntre('2026-10-30', '2026-10-27') === 2);

// ---------- fuso da escola (helpers/tempo.php) ----------
$madrugadaUtc = new DateTimeImmutable('2026-11-01 01:30:00', new DateTimeZone('UTC')); // 31/10 22:30 em São Paulo
afirmar('hojeNaEscola: 01:30 UTC ainda é o dia anterior em São Paulo', hojeNaEscola($madrugadaUtc) === '2026-10-31');
afirmar('inicioDoMesUtc: mês corrente no fuso da escola, em UTC', inicioDoMesUtc(0, $madrugadaUtc) === '2026-10-01 03:00:00');
afirmar('inicioDoMesUtc: próximo mês (-1)', inicioDoMesUtc(-1, $madrugadaUtc) === '2026-11-01 03:00:00');
afirmar('inicioDoMesUtc: mês passado (1)', inicioDoMesUtc(1, $madrugadaUtc) === '2026-09-01 03:00:00');
afirmar('inicioDoMesLocal: rótulo do mês', inicioDoMesLocal(0, $madrugadaUtc) === '2026-10-01');
afirmar('inicioDoDiaUtc: 7 dias atrás', inicioDoDiaUtc(7, $madrugadaUtc) === '2026-10-24 03:00:00');
afirmar('localParaUtc: meia-noite local = 03:00 UTC', localParaUtc('2026-10-29') === '2026-10-29 03:00:00');
afirmar('dataNaEscola: 01:00 UTC é o dia anterior', dataNaEscola('2026-10-30 01:00:00') === '2026-10-29');
afirmar('dataNaEscola: aceita microssegundos', dataNaEscola('2026-10-30 14:25:11.870724') === '2026-10-30');
afirmar('dataIsoValida: data real', dataIsoValida('2026-02-28') === true);
afirmar('dataIsoValida: 30 de fevereiro', dataIsoValida('2026-02-30') === false);
afirmar('dataIsoValida: formato brasileiro', dataIsoValida('28/02/2026') === false);
afirmar('lerMomentoDoEvento: só a data vira meio-dia local (15:00 UTC)', lerMomentoDoEvento('2026-10-28') === '2026-10-28 15:00:00');
afirmar('lerMomentoDoEvento: data e hora locais', lerMomentoDoEvento('2026-10-28 22:30') === '2026-10-29 01:30:00');
afirmar('lerMomentoDoEvento: vazio = agora (null)', lerMomentoDoEvento(null) === null);
afirmar('sqlDataNaEscola: converte de UTC para o fuso da escola', sqlDataNaEscola('f.dta_falta') === "((f.dta_falta) AT TIME ZONE 'UTC' AT TIME ZONE 'America/Sao_Paulo')::date");

// ---------- timestamp assinado do app (helpers/dispositivo.php) ----------
$ts = lerTimestampAssinado('2026-10-29T13:45:00.123Z');
afirmar('lerTimestampAssinado: toISOString() do app é UTC', $ts !== false && $ts->getTimestamp() === (new DateTimeImmutable('2026-10-29 13:45:00', new DateTimeZone('UTC')))->getTimestamp());
$ts = lerTimestampAssinado('2026-10-29T10:45:00-03:00');
afirmar('lerTimestampAssinado: com fuso explícito', $ts !== false && $ts->getTimestamp() === (new DateTimeImmutable('2026-10-29 13:45:00', new DateTimeZone('UTC')))->getTimestamp());
afirmar('lerTimestampAssinado: sem milissegundos', lerTimestampAssinado('2026-10-29T13:45:00Z') !== false);
afirmar('lerTimestampAssinado: texto inválido', lerTimestampAssinado('ontem') === false);
afirmar('assinaturaCheckin: HMAC igual ao do app (crypto-js)', assinaturaCheckin('uuid-1', 'falta', '2026-10-29T13:45:00.123Z', 'segredo') === hash_hmac('sha256', 'uuid-1|falta|2026-10-29T13:45:00.123Z', 'segredo'));

// ---------- CPF / responsável ----------
afirmar('cpfValido: CPF real com pontuação', cpfValido('529.982.247-25') === true);
afirmar('cpfValido: CPF real só dígitos', cpfValido('52998224725') === true);
afirmar('cpfValido: dígito verificador errado', cpfValido('52998224724') === false);
afirmar('cpfValido: todos os dígitos iguais', cpfValido('11111111111') === false);
afirmar('cpfValido: curto', cpfValido('1234567890') === false);
afirmar('normalizarGeneroResponsavel: "Outro" vira "Indefinido" (valor aceito pelo banco)', normalizarGeneroResponsavel('Outro') === 'Indefinido');
afirmar('normalizarGeneroResponsavel: valor do banco', normalizarGeneroResponsavel('Feminino') === 'Feminino');
afirmar('normalizarGeneroResponsavel: inválido', normalizarGeneroResponsavel('X') === null);
afirmar('mensagemDuplicidadeResponsavel: telefone', str_contains(mensagemDuplicidadeResponsavel('duplicate key value violates unique constraint "tb_responsavel_telefone_responsavel_key"'), 'telefone'));
afirmar('mensagemDuplicidadeResponsavel: email', str_contains(mensagemDuplicidadeResponsavel('... "tb_responsavel_email_responsavel_key"'), 'email'));
afirmar('mensagemDuplicidadeResponsavel: vínculo repetido', str_contains(mensagemDuplicidadeResponsavel('... "tb_aluno_responsavel_pkey"'), 'vinculado'));

// ---------- status de notificação (valores do banco de produção) ----------
afirmar('statusNotificacaoParaBanco: pendente', statusNotificacaoParaBanco('pendente') === 'Pendente');
afirmar('statusNotificacaoParaBanco: enviada', statusNotificacaoParaBanco('enviada') === 'Enviado');
afirmar('statusNotificacaoParaBanco: erro', statusNotificacaoParaBanco('ERRO') === 'Erro');
afirmar('statusNotificacaoParaBanco: inválido', statusNotificacaoParaBanco('lida') === null);
afirmar('statusNotificacaoParaApi: Enviado -> enviada', statusNotificacaoParaApi('Enviado') === 'enviada');
afirmar('statusNotificacaoParaApi: Pendente -> pendente', statusNotificacaoParaApi('Pendente') === 'pendente');

// ---------- CORS ----------
afirmar('corsNormalizarOrigem: barra final e maiúsculas', corsNormalizarOrigem(' https://Site.Vercel.app/ ') === 'https://site.vercel.app');
$origens = corsOrigensPermitidas('https://site.vercel.app/, https://site-git-*-equipe.vercel.app');
afirmar('corsOrigemPermitida: origem configurada com barra final', corsOrigemPermitida('https://site.vercel.app', $origens));
afirmar('corsOrigemPermitida: preview da Vercel pelo padrão', corsOrigemPermitida('https://site-git-ajuste-login-equipe.vercel.app', $origens));
afirmar('corsOrigemPermitida: "*" não atravessa ponto', !corsOrigemPermitida('https://site-git-x.evil.com-equipe.vercel.app', $origens));
afirmar('corsOrigemPermitida: outra origem', !corsOrigemPermitida('https://outro.vercel.app', $origens));
afirmar('corsOrigemPermitida: sem Origin', !corsOrigemPermitida('', $origens));
afirmar('corsOrigemPermitida: http no lugar de https', !corsOrigemPermitida('http://site.vercel.app', $origens));
afirmar('corsPadraoAceito: *.vercel.app inteiro é recusado', corsPadraoAceito('https://*.vercel.app') === false);
afirmar('corsOrigemPermitida: padrão amplo demais é ignorado', !corsOrigemPermitida('https://qualquer.vercel.app', corsOrigensPermitidas('https://*.vercel.app')));
afirmar('corsOrigensPermitidas: sem configuração vale o padrão local', corsOrigensPermitidas('') === CORS_ORIGENS_PADRAO);

// ---------- escalonamento dos alertas ----------
$agora = strtotime('2026-10-29 12:00:00 UTC');
$alta = [['prioridade' => 'alta', 'tipo' => 'frequencia']];
afirmar('calcularNivelAluno: alerta alto sem histórico = 3', calcularNivelAluno($alta, null, $agora)['nivel'] === 3);
afirmar(
    'calcularNivelAluno: alto mantido há 8 dias escala para 4',
    calcularNivelAluno($alta, ['nivel' => 3, 'atualizado_em' => '2026-10-21 12:00:00'], $agora)['nivel'] === 4
);
afirmar(
    'calcularNivelAluno: depois de escalar, NÃO volta a 3 no dia seguinte (oscilava 3-4-3-4)',
    calcularNivelAluno($alta, ['nivel' => 4, 'atualizado_em' => '2026-10-28 12:00:00'], $agora)['nivel'] === 4
);
afirmar(
    'calcularNivelAluno: alto há menos de 7 dias continua 3',
    calcularNivelAluno($alta, ['nivel' => 3, 'atualizado_em' => '2026-10-25 12:00:00'], $agora)['nivel'] === 3
);
afirmar(
    'calcularNivelAluno: quem saiu do alerta (nível zerado) não escala na hora ao voltar',
    calcularNivelAluno($alta, ['nivel' => 0, 'atualizado_em' => '2026-09-01 12:00:00'], $agora)['nivel'] === 3
);
afirmar(
    'calcularNivelAluno: dois alertas altos = crítico (4)',
    calcularNivelAluno([['prioridade' => 'alta', 'tipo' => 'frequencia'], ['prioridade' => 'alta', 'tipo' => 'faltas']], null, $agora)['nivel'] === 4
);
afirmar(
    'calcularNivelAluno: melhora (de 4 para só alerta baixo) desce o nível',
    calcularNivelAluno([['prioridade' => 'baixa', 'tipo' => 'atrasos']], ['nivel' => 4, 'atualizado_em' => '2026-10-27 12:00:00'], $agora)['nivel'] === 1
);

// ---------- similaridadeCosseno ----------
afirmar('similaridadeCosseno: vetor idêntico a si mesmo = 1.0', abs(similaridadeCosseno([1, 2, 3], [1, 2, 3]) - 1.0) < 0.0001);
afirmar('similaridadeCosseno: vetores ortogonais = 0.0', abs(similaridadeCosseno([1, 0], [0, 1]) - 0.0) < 0.0001);
afirmar('similaridadeCosseno: vetores opostos = -1.0', abs(similaridadeCosseno([1, 1], [-1, -1]) - (-1.0)) < 0.0001);
afirmar('similaridadeCosseno: tamanhos diferentes = 0.0 (não quebra)', similaridadeCosseno([1, 2], [1, 2, 3]) === 0.0);
afirmar('similaridadeCosseno: vetor de zeros = 0.0 (não divide por zero)', similaridadeCosseno([0, 0], [1, 1]) === 0.0);

// ---------- embeddingValido ----------
afirmar('embeddingValido: array numérico de tamanho razoável', embeddingValido(array_fill(0, 128, 0.5)) === true);
afirmar('embeddingValido: array pequeno demais (< 8)', embeddingValido([1, 2, 3]) === false);
afirmar('embeddingValido: não é array', embeddingValido('não é array') === false);
afirmar('embeddingValido: contém elemento não numérico', embeddingValido([1, 2, 3, 4, 5, 6, 7, 'oito']) === false);

afirmar('embeddingValido: número em texto não vale (o app manda números)', embeddingValido(array_fill(0, 8, '0.5')) === false);

// ---------- amostras e identificação facial (helpers/biometriaFacial.php) ----------
afirmar('amostrasValidas: 3 amostras de 128', amostrasValidas(array_fill(0, 3, array_fill(0, 128, 0.1))) === true);
afirmar('amostrasValidas: nenhuma amostra', amostrasValidas([]) === false);
afirmar('amostrasValidas: mais que o máximo', amostrasValidas(array_fill(0, MAXIMO_AMOSTRAS_FACIAIS + 1, array_fill(0, 128, 0.1))) === false);
afirmar('amostrasValidas: tamanhos diferentes', amostrasValidas([array_fill(0, 128, 0.1), array_fill(0, 64, 0.1)]) === false);
afirmar('distanciaEuclidiana: 3-4-5', abs(distanciaEuclidiana([0, 0], [3, 4]) - 5.0) < 1e-9);
afirmar('distanciaEuclidiana: tamanhos diferentes = infinito', distanciaEuclidiana([1, 2], [1, 2, 3]) === INF);

$cadastros = [10 => [[0.0, 0.0], [0.1, 0.0]], 20 => [[1.0, 1.0]], 30 => [[0.0, 2.0]]];
$r = identificarRosto([0.05, 0.05], $cadastros, 0.5, 0.06);
afirmar('identificarRosto: reconhece o aluno mais próximo (menor distância entre as amostras dele)', $r['id_aluno'] === 10 && $r['distancia'] < 0.08);
$r = identificarRosto([5.0, 5.0], $cadastros, 0.5, 0.06);
afirmar('identificarRosto: longe de todos = não reconhecido', $r['id_aluno'] === null && $r['distancia'] > 0.5);
$r = identificarRosto([0.5, 0.5], [10 => [[0.3, 0.3]], 20 => [[0.7, 0.7]]], 0.5, 0.06);
afirmar('identificarRosto: dois alunos à mesma distância = recusa (não decide pela foto)', $r['id_aluno'] === null);
$r = identificarRosto([0.0, 0.0], [], 0.5, 0.06);
afirmar('identificarRosto: sem cadastros = não reconhecido', $r['id_aluno'] === null && $r['distancia'] === null);

// ---------- criptografia da biometria (helpers/criptografia.php) ----------
$chaveTeste = chaveBiometria(str_repeat('ab', 32));
afirmar('chaveBiometria: 64 hexadecimais = 32 bytes', is_string($chaveTeste) && strlen($chaveTeste) === 32);
afirmar('chaveBiometria: base64 de 32 bytes (generateValue do Render)', strlen((string) chaveBiometria(base64_encode(str_repeat('x', 32)))) === 32);
afirmar('chaveBiometria: curta = desligada', chaveBiometria('abc') === null);
afirmar('chaveBiometria: vazia = desligada', chaveBiometria('') === null);
$guardado = cifrarDado('[[0.1,0.2]]', $chaveTeste, 'facial:aluno:7');
afirmar('cifrarDado: formato gcm1 e sem o texto original', str_starts_with($guardado, 'gcm1:') && !str_contains($guardado, '0.1'));
afirmar('cifrarDado: duas cifras do mesmo dado são diferentes (IV aleatório)', $guardado !== cifrarDado('[[0.1,0.2]]', $chaveTeste, 'facial:aluno:7'));
afirmar('decifrarDado: volta ao original', decifrarDado($guardado, $chaveTeste, 'facial:aluno:7') === '[[0.1,0.2]]');
afirmar('decifrarDado: outro aluno (contexto) não abre', decifrarDado($guardado, $chaveTeste, 'facial:aluno:8') === null);
afirmar('decifrarDado: outra chave não abre', decifrarDado($guardado, chaveBiometria(str_repeat('cd', 32)), 'facial:aluno:7') === null);
$adulterado = substr($guardado, 0, -2) . (substr($guardado, -2, 1) === 'A' ? 'B' : 'A') . substr($guardado, -1);
afirmar('decifrarDado: dado adulterado não abre', decifrarDado($adulterado, $chaveTeste, 'facial:aluno:7') === null);
afirmar('decifrarDado: texto sem o prefixo não abre', decifrarDado('[[0.1,0.2]]', $chaveTeste, 'facial:aluno:7') === null);

// ---------- resultado ----------
$passou = $totalTestes - count($falhas);
echo "Resultado: $passou/$totalTestes testes passaram.\n";
if (!empty($falhas)) {
    echo "\nFALHARAM:\n";
    foreach ($falhas as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
exit(0);
