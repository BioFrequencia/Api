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
require_once __DIR__ . '/../helpers/biometriaFacial.php';
require_once __DIR__ . '/../helpers/corsRegras.php';
require_once __DIR__ . '/../helpers/notificacao.php';
require_once __DIR__ . '/../helpers/escalonamento.php';
require_once __DIR__ . '/../helpers/dispositivo.php';
require_once __DIR__ . '/../helpers/registroEvento.php';
require_once __DIR__ . '/../helpers/criptografia.php';
require_once __DIR__ . '/../helpers/conta.php';
require_once __DIR__ . '/../helpers/calendario.php';
require_once __DIR__ . '/../helpers/configuracaoEscola.php';
require_once __DIR__ . '/../helpers/indicadores.php';
require_once __DIR__ . '/../helpers/catalogoEscolas.php';
require_once __DIR__ . '/../helpers/avisos.php';
require_once __DIR__ . '/../helpers/equipe.php';
require_once __DIR__ . '/../helpers/emailConta.php';
require_once __DIR__ . '/../helpers/senha.php';
require_once __DIR__ . '/../helpers/tentativas.php';
require_once __DIR__ . '/../helpers/importacao.php';
require_once __DIR__ . '/../helpers/importacaoOcorrencias.php';
require_once __DIR__ . '/../helpers/mensagemManual.php';

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

// ---------- senha forte (8+, maiúscula, minúscula, número e especial) ----------
afirmar('senhaForte: cumpre tudo', senhaForte('Senha@123') === true);
afirmar('senhaForte: acentos contam como letras', senhaForte('Ação#2026') === true);
afirmar('senhaForte: só minúsculas e números (a regra antiga aceitava)', senhaForte('senha123') === false);
afirmar('senhaForte: vazia', senhaForte('') === false);
afirmar('requisitos: curta lista tudo o que falta', requisitosSenhaPendentes('abc') === [
    'pelo menos 8 caracteres', 'uma letra maiúscula', 'um número', 'um caractere especial (ex.: ! @ # $ %)']);
afirmar('requisitos: espaço não conta como caractere especial', requisitosSenhaPendentes('Senha 1234') === ['um caractere especial (ex.: ! @ # $ %)']);
afirmar('requisitos: sem minúscula', requisitosSenhaPendentes('SENHA@123') === ['uma letra minúscula']);
afirmar('requisitos: mais de 128 caracteres', requisitosSenhaPendentes('Aa1!' . str_repeat('x', 125)) === ['no máximo 128 caracteres']);
afirmar('requisitos: exatamente 8 é aceito', requisitosSenhaPendentes('Abcde1!x') === []);
afirmar('mensagem com dois itens', mensagemRequisitosSenha(['uma letra maiúscula', 'um número']) === 'A senha precisa ter uma letra maiúscula e um número.');
afirmar('mensagem com três itens', mensagemRequisitosSenha(['pelo menos 8 caracteres', 'uma letra maiúscula', 'um número'])
    === 'A senha precisa ter pelo menos 8 caracteres, uma letra maiúscula e um número.');
afirmar('mensagem com um item', mensagemRequisitosSenha(['um número']) === 'A senha precisa ter um número.');

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

// ---------- calendário escolar (helpers/calendario.php) ----------
afirmar('Páscoa de 2024, 2025, 2026 e 2027', domingoDePascoa(2024) === '2024-03-31' && domingoDePascoa(2025) === '2025-04-20'
    && domingoDePascoa(2026) === '2026-04-05' && domingoDePascoa(2027) === '2027-03-28');
$feriados2026 = feriadosDoAno(2026);
afirmar('2026: Carnaval na segunda e na terça (16 e 17/02)', ($feriados2026['2026-02-16'] ?? '') === 'Carnaval' && ($feriados2026['2026-02-17'] ?? '') === 'Carnaval');
afirmar('2026: Sexta-feira Santa (03/04) e Corpus Christi (04/06)', isset($feriados2026['2026-04-03']) && ($feriados2026['2026-06-04'] ?? '') === 'Corpus Christi');
afirmar('2026: 9 de julho, feriado de São Paulo', isset($feriados2026['2026-07-09']));
afirmar('2026: Finados (02/11) e Consciência Negra (20/11)', isset($feriados2026['2026-11-02'], $feriados2026['2026-11-20']));
afirmar('Consciência Negra só é feriado nacional desde 2024', !isset(feriadosDoAno(2023)['2023-11-20']));
afirmar('terça-feira comum é dia letivo', situacaoDoDia('2026-11-03') === ['letivo' => true, 'motivo' => null]);
afirmar('sábado não é dia letivo', situacaoDoDia('2026-10-31') === ['letivo' => false, 'motivo' => 'sábado']);
afirmar('domingo não é dia letivo', situacaoDoDia('2026-11-01')['motivo'] === 'domingo');
afirmar('feriado não é dia letivo, com o nome no motivo', situacaoDoDia('2026-11-02') === ['letivo' => false, 'motivo' => 'feriado de Finados']);
$calendarioEscola = [
    ['data_inicio' => '2026-07-13', 'data_fim' => '2026-07-24', 'tipo' => 'sem_aula', 'descricao' => 'Férias de julho'],
    ['data_inicio' => '2026-10-31', 'data_fim' => '2026-10-31', 'tipo' => 'aula_extra', 'descricao' => 'Sábado letivo'],
];
afirmar('período sem aula da escola (férias)', situacaoDoDia('2026-07-15', $calendarioEscola) === ['letivo' => false, 'motivo' => 'Férias de julho']);
afirmar('sábado letivo da escola vale mais que o fim de semana', situacaoDoDia('2026-10-31', $calendarioEscola)['letivo'] === true);
afirmar('diasLetivosEntre: 26/10 a 01/11/2026 = 5 dias (fim de semana fora)', count(diasLetivosEntre('2026-10-26', '2026-11-01')) === 5);
afirmar('diasLetivosEntre: 01 a 06/11/2026 sem o domingo e Finados', diasLetivosEntre('2026-11-01', '2026-11-06') === ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06']);
afirmar('horário padrão do Matutino', horarioDaTurma('Matutino') === ['inicio' => '07:00', 'fim' => '12:20']);
afirmar('horário próprio da turma vale mais que o padrão', horarioDaTurma('Matutino', '07:30', '12:50') === ['inicio' => '07:30', 'fim' => '12:50']);
afirmar('turma sem período conhecido usa o dia escolar inteiro', horarioDaTurma(null) === ['inicio' => '07:00', 'fim' => '23:00']);
afirmar('horaValida: 07:05 sim; 7:05 e 24:00 não', horaValida('07:05') && !horaValida('7:05') && !horaValida('24:00'));

$letivo = ['letivo' => true, 'motivo' => null];
$manha = ['inicio' => '07:00', 'fim' => '12:20'];
$conferir = fn(string $tipo, string $data, string $hora, ?array $dia = null) => conferirHorarioDoRegistro($tipo, $data, $hora, $dia ?? $letivo, $manha, 10, '1A');
afirmar('entrada atrasada às 07:30 na turma da manhã: pode', $conferir('atraso', '2026-11-03', '07:30') === null);
afirmar('entrada atrasada às 17:00 de aluno da manhã: recusada (caso de produção)', str_contains((string) $conferir('atraso', '2026-11-03', '17:00'), 'das 07:00 às 12:20'));
afirmar('chegada às 07:10 está dentro da tolerância de 10 min: não é atraso', str_contains((string) $conferir('atraso', '2026-11-03', '07:10'), 'tolerância'));
afirmar('chegada às 07:11 já é entrada atrasada', $conferir('atraso', '2026-11-03', '07:11') === null);
afirmar('entrada antes do início da aula: recusada', $conferir('atraso', '2026-11-03', '06:50') !== null);
afirmar('entrada atrasada num sábado: recusada', str_contains((string) $conferir('atraso', '2026-10-31', '08:00', situacaoDoDia('2026-10-31')), 'sábado'));
afirmar('entrada atrasada num feriado: recusada', str_contains((string) $conferir('atraso', '2026-11-02', '08:00', situacaoDoDia('2026-11-02')), 'Finados'));
afirmar('saída antecipada às 10:00: pode', $conferir('saida', '2026-11-03', '10:00') === null);
afirmar('saída logo depois do início também pode (tolerância é só da entrada)', $conferir('saida', '2026-11-03', '07:05') === null);
afirmar('saída no horário do fim da aula não é antecipada: recusada', $conferir('saida', '2026-11-03', '12:20') !== null);
afirmar('presença às 07:05 (tolerância): no horário', situacaoDaEntrada('07:05', $letivo, $manha, 10) === 'no_horario');
afirmar('presença às 07:25: entrada atrasada', situacaoDaEntrada('07:25', $letivo, $manha, 10) === 'atrasada');
afirmar('presença às 13:00 de aluno da manhã: fora do horário (não vira atraso)', situacaoDaEntrada('13:00', $letivo, $manha, 10) === 'fora_do_horario');
afirmar('presença num domingo: sem aula', situacaoDaEntrada('08:00', situacaoDoDia('2026-11-01'), $manha, 10) === 'sem_aula');

// ---------- indicadores (helpers/indicadores.php) ----------
afirmar('taxa: 4 dias com atraso em 18 dias de aula = 22,2%', taxaPercentual(4, 18) === 22.2);
afirmar('taxa sem nenhum dia de aula é null (nunca 0% ou 100% inventados)', taxaPercentual(0, 0) === null);
afirmar('taxa nunca passa de 100%', taxaPercentual(5, 3) === 100.0);
afirmar('pontualidade: 2 atrasos em 40 dias-aluno = 95%', pontualidadePercentual(2, 40) === 95.0);
afirmar('pontualidade sem dia de aula é null', pontualidadePercentual(0, 0) === null);
$padrao = CONFIGURACAO_PADRAO;
afirmar('classificar: 3 atrasos em 15 dias (20%) = média (avisa o responsável)', classificarTaxa(20.0, 3, $padrao) === 'media');
afirmar('classificar: 6 em 15 dias (40%) = alta', classificarTaxa(40.0, 6, $padrao) === 'alta');
afirmar('classificar: 2 em 18 dias (11%) = baixa (perto do limite)', classificarTaxa(11.1, 2, $padrao) === 'baixa');
afirmar('classificar: 1 atraso em 2 dias (50%) não é alerta (mínimo de ocorrências)', classificarTaxa(50.0, 1, $padrao) === null);
afirmar('classificar: 2 atrasos a 50% ficam em baixa (mínimo de 3 para avisar)', classificarTaxa(50.0, 2, $padrao) === 'baixa');
afirmar('classificar: com o limite da escola em 30%, 25% é só baixa', classificarTaxa(25.0, 4, ['limite_alerta_percentual' => 30] + $padrao) === 'baixa');
afirmar('situação do aluno: a pior entre atrasos e saídas', situacaoDoAluno('baixa', 'alta', 10) === 'Crítico' && situacaoDoAluno(null, 'media', 10) === 'Acima do limite');
afirmar('situação sem ocorrência: Regular; sem dia de aula: Sem dias de aula', situacaoDoAluno(null, null, 5) === 'Regular' && situacaoDoAluno(null, null, 0) === 'Sem dias de aula');
afirmar('dias de aula do aluno contam desde o cadastro', diasDeAulaDoAluno(['2026-11-03', '2026-11-04', '2026-11-05'], '2026-11-04') === 2);
afirmar('aluno sem data de cadastro (antigo) conta o período todo', diasDeAulaDoAluno(['2026-11-03', '2026-11-04'], null) === 2);
afirmar('registro de um dia de aula antes do cadastro: os dias contam desde ele (taxa nunca passa de 100%)',
    diasDeAulaDoAluno(['2026-11-03', '2026-11-04', '2026-11-05'], '2026-11-05', ['2026-11-04']) === 2);
afirmar('registro antigo num domingo não estica os dias de aula do aluno',
    diasDeAulaDoAluno(['2026-11-03', '2026-11-04'], '2026-11-04', ['2026-11-01']) === 1);
afirmar('aluno cadastrado num sábado com registros da semana: os dias de aula da semana contam',
    diasDeAulaDoAluno(['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'], '2026-11-07', ['2026-11-06', '2026-11-04']) === 3);
$resumoComRegistroAntigo = resumoDoPeriodo(
    [['id_aluno' => 3, 'cadastrado_em' => '2026-11-06']],
    ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'],
    [3 => ['2026-11-04']],
    []
);
afirmar('resumo: entrada atrasada antes do cadastro conta com os dias de aula desde ela (3 dias, não 1)',
    $resumoComRegistroAntigo['aluno_dias'] === 3 && $resumoComRegistroAntigo['atrasos'] === 1);
$resumo = resumoDoPeriodo(
    [['id_aluno' => 1, 'cadastrado_em' => null], ['id_aluno' => 2, 'cadastrado_em' => '2026-11-05']],
    ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'],
    // 01/11/2026 é domingo: o registro antigo desse dia não conta.
    [1 => ['2026-11-03', '2026-11-01'], 2 => ['2026-11-06']],
    [1 => ['2026-11-04']]
);
afirmar('resumo: dias-aluno somam o cadastro de cada um (4 + 2)', $resumo['aluno_dias'] === 6);
afirmar('resumo: atraso num domingo (registro antigo) não conta', $resumo['atrasos'] === 2);
afirmar('resumo: pontualidade = 100% - 2/6', $resumo['pontualidade'] === 66.7);
afirmar('resumo: saídas só em dia letivo', $resumo['saidas'] === 1);
afirmar('resumo sem alunos: pontualidade null (não 100%)', resumoDoPeriodo([], ['2026-11-03'], [], [])['pontualidade'] === null);
afirmar('numeroBr: vírgula decimal e inteiro sem ",0"', numeroBr(22.5) === '22,5' && numeroBr(20.0) === '20');

// ---------- configuração da escola (helpers/configuracaoEscola.php) ----------
afirmar('escola sem configuração gravada usa o padrão', normalizarConfiguracao(null) === CONFIGURACAO_PADRAO);
$doBanco = normalizarConfiguracao(['tolerancia_minutos' => '15', 'aviso_automatico' => 'f']);
afirmar('configuração do banco: número e booleano com os tipos certos', $doBanco['tolerancia_minutos'] === 15 && $doBanco['aviso_automatico'] === false);
[$nova, $erroConfig] = lerConfiguracao(['limite_alerta_percentual' => 25, 'aviso_cada_registro' => true], CONFIGURACAO_PADRAO);
afirmar('alteração parcial: só muda o que veio', $erroConfig === null && $nova['limite_alerta_percentual'] === 25
    && $nova['aviso_cada_registro'] === true && $nova['tolerancia_minutos'] === 10);
afirmar('tolerância acima de 60 minutos: recusada', lerConfiguracao(['tolerancia_minutos' => 90], CONFIGURACAO_PADRAO)[1] !== null);
afirmar('limite de 0%: recusado', lerConfiguracao(['limite_alerta_percentual' => 0], CONFIGURACAO_PADRAO)[1] !== null);
afirmar('booleano em texto: recusado', lerConfiguracao(['aviso_automatico' => 'sim'], CONFIGURACAO_PADRAO)[1] !== null);
afirmar('número decimal: recusado', lerConfiguracao(['janela_alerta_dias' => 30.5], CONFIGURACAO_PADRAO)[1] !== null);
$porTipo = normalizarConfiguracao(['aviso_cada_registro' => 't', 'aviso_automatico' => 'f', 'aviso_entrada_atrasada' => 't',
    'aviso_saida_antecipada' => 'f', 'aviso_limite_atrasos' => 't', 'aviso_limite_saidas' => 'f']);
afirmar('avisos por tipo vindos do banco; as chaves antigas saem deles', $porTipo['aviso_entrada_atrasada'] === true
    && $porTipo['aviso_saida_antecipada'] === false && $porTipo['aviso_limite_atrasos'] === true && $porTipo['aviso_limite_saidas'] === false
    && $porTipo['aviso_cada_registro'] === true && $porTipo['aviso_automatico'] === true);
$semTipos = normalizarConfiguracao(['aviso_cada_registro' => 't', 'aviso_automatico' => 'f']);
afirmar('banco sem a migração 011: os tipos vêm das chaves antigas', $semTipos['aviso_entrada_atrasada'] === true
    && $semTipos['aviso_saida_antecipada'] === true && $semTipos['aviso_limite_atrasos'] === false && $semTipos['aviso_limite_saidas'] === false);
[$soEntrada] = lerConfiguracao(['aviso_entrada_atrasada' => true], CONFIGURACAO_PADRAO);
afirmar('liga só o aviso de entrada atrasada', $soEntrada['aviso_entrada_atrasada'] === true && $soEntrada['aviso_saida_antecipada'] === false
    && $soEntrada['aviso_cada_registro'] === true);
[$programaAntigo] = lerConfiguracao(['tolerancia_minutos' => 12, 'aviso_cada_registro' => true, 'aviso_automatico' => true], $soEntrada);
afirmar('programa antigo salvando sem mudar as chaves antigas não desfaz a escolha por tipo', $programaAntigo['tolerancia_minutos'] === 12
    && $programaAntigo['aviso_entrada_atrasada'] === true && $programaAntigo['aviso_saida_antecipada'] === false);
[$antigoDesliga] = lerConfiguracao(['aviso_cada_registro' => false], $soEntrada);
afirmar('programa antigo desligando "a cada registro" desliga os dois tipos', $antigoDesliga['aviso_entrada_atrasada'] === false
    && $antigoDesliga['aviso_saida_antecipada'] === false && $antigoDesliga['aviso_cada_registro'] === false);
afirmar('tipo de aviso em texto: recusado', lerConfiguracao(['aviso_limite_saidas' => 'nao'], CONFIGURACAO_PADRAO)[1] !== null);

// ---------- catálogo de escolas (helpers/catalogoEscolas.php) ----------
afirmar('nome de exibição: escola estadual ganha "E.E."', nomeDeExibicaoEscola('FLEURIDES CAVALLINI MENECHINO PROFA', 8) === 'E.E. Fleurides Cavallini Menechino Profa.');
afirmar('nome de exibição: "EE" da planilha vira "E.E." uma vez só', nomeDeExibicaoEscola('EE MARIA NILDE MASCELLANI', 8) === 'E.E. Maria Nilde Mascellani');
afirmar('nome de exibição: preposição minúscula, número e romano mantidos',
    nomeDeExibicaoEscola('9 DE JULHO', 8) === 'E.E. 9 de Julho' && nomeDeExibicaoEscola('DOM PEDRO II', 8) === 'E.E. Dom Pedro II');
afirmar('nome de exibição: outros tipos sem prefixo, siglas mantidas', nomeDeExibicaoEscola('CEL JTO A EE HELEN KELLER', 6) === 'CEL Jto a EE Helen Keller');
afirmar('busca sem acento e sem pontuação', normalizarTextoBusca(' São  Bernardo-do Campo ') === 'SAO BERNARDO DO CAMPO');
afirmar('mesma escola com prefixos diferentes tem a mesma chave', chaveNomeEscola('E.E. Maria Nilde Mascellani') === chaveNomeEscola('EE MARIA NILDE MASCELLANI'));
$catalogo = lerCatalogoCsv(ARQUIVO_CATALOGO_ESCOLAS);
afirmar('catálogo da SEDUC: 5.795 escolas com código CIE único', count($catalogo) === 5795
    && count(array_unique(array_column($catalogo, 'codigo_cie'))) === 5795);
afirmar('catálogo: CEP com 8 dígitos (zero à esquerda mantido)', count(array_filter($catalogo, fn($e) => $e['cep'] !== null && !preg_match('/^\d{8}$/', $e['cep']))) === 0);

// ---------- avisos aos responsáveis (helpers/avisos.php) ----------
$contatos = ['telefone_responsavel' => '11999990001', 'email_responsavel' => 'mae@exemplo.test'];
afirmar('canal preferido WhatsApp', canaisDoResponsavel($contatos + ['canal_preferido' => 'whatsapp']) === ['whatsapp']);
afirmar('canal "ambos"', canaisDoResponsavel($contatos + ['canal_preferido' => 'ambos']) === ['whatsapp', 'email']);
afirmar('prefere e-mail mas não tem e-mail: vai pelo WhatsApp', canaisDoResponsavel(['telefone_responsavel' => '11999990001', 'email_responsavel' => null, 'canal_preferido' => 'email']) === ['whatsapp']);
afirmar('sem telefone válido nem e-mail: nenhum canal', canaisDoResponsavel(['telefone_responsavel' => '123', 'email_responsavel' => '', 'canal_preferido' => 'whatsapp']) === []);
afirmar('mensagem de limite: números que a família entende',
    str_contains(mensagemAvisoLimite('E.E. Teste', 'Ana', 'atrasos', 4, 18, 22.2, 30), '4 entradas atrasadas em 18 dias de aula nos últimos 30 dias (22,2%)'));
afirmar('mensagem de limite no singular', str_contains(mensagemAvisoLimite('E.E. Teste', 'Ana', 'saidas', 1, 5, 20.0, 30), '1 saída antecipada'));
$mensagemRegistro = mensagemAvisoRegistro('E.E. Teste', 'Ana', 'saida', '2026-11-03', '10:15', 'Consulta', 'Maria (mãe)');
afirmar('mensagem do registro: data, hora, motivo e quem buscou', str_contains($mensagemRegistro, 'saída antecipada de Ana em 03/11/2026 às 10:15')
    && str_contains($mensagemRegistro, 'Motivo: Consulta.') && str_contains($mensagemRegistro, 'Retirado(a) por: Maria (mãe).'));
afirmar('WhatsApp sem modelo configurado: texto livre', corpoWhatsapp('5511999990001', 'Oi')['type'] === 'text');
putenv('WHATSAPP_TEMPLATE=aviso_escola');
$corpoModelo = corpoWhatsapp('5511999990001', 'Oi');
$corpoVariasLinhas = corpoWhatsapp('5511999990001', "Olá, Maria.\r\n\nA reunião é amanhã.\tAté     logo.\n");
putenv('WHATSAPP_TEMPLATE');
afirmar('WhatsApp com modelo aprovado: a mensagem vai na variável do modelo', $corpoModelo['type'] === 'template'
    && $corpoModelo['template']['name'] === 'aviso_escola' && $corpoModelo['template']['language']['code'] === 'pt_BR'
    && $corpoModelo['template']['components'][0]['parameters'][0]['text'] === 'Oi');
// A Meta recusa variável de modelo com quebra de linha, tabulação ou mais de 4 espaços seguidos (erro 132018).
afirmar('WhatsApp com modelo: mensagem de várias linhas vai numa linha só, sem tabulação nem espaços repetidos',
    $corpoVariasLinhas['template']['components'][0]['parameters'][0]['text'] === 'Olá, Maria. A reunião é amanhã. Até logo.');
afirmar('WhatsApp sem modelo: o texto livre mantém as quebras de linha',
    corpoWhatsapp('5511999990001', "Linha 1\nLinha 2")['text']['body'] === "Linha 1\nLinha 2");

// ---------- equipe da escola (helpers/equipe.php) ----------
// Toda conta nova é pendente; a aprovação exige o e-mail confirmado (migração 009).
$pendente = ['situacao' => 'pendente', 'papel' => 'coordenador', 'email_verificado' => true];
$gestorAtivo = ['situacao' => 'ativo', 'papel' => 'gestor', 'email_verificado' => true];
afirmar('aprovar conta pendente numa escola com gestão: coordenador', proximoEstadoDoMembro($pendente, 'aprovar', false) === ['ativo', 'coordenador', null]);
afirmar('aprovar a primeira conta de uma escola sem gestão: vira gestor', proximoEstadoDoMembro($pendente, 'aprovar', true) === ['ativo', 'gestor', null]);
afirmar('conta pedida como gestora, aprovada quando a escola já tem gestão: coordenador',
    proximoEstadoDoMembro(['papel' => 'gestor'] + $pendente, 'aprovar', false) === ['ativo', 'coordenador', null]);
// Cargo e permissão (migração 010): quem aprova escolhe; sem escolha, vale a sugerida pelo cargo.
afirmar('diretora aprovada numa escola que já tem gestão: Gestão (sugerida pelo cargo)',
    proximoEstadoDoMembro(['cargo' => 'direcao'] + $pendente, 'aprovar', false) === ['ativo', 'gestor', null]);
afirmar('vice-direção: também Gestão', proximoEstadoDoMembro(['cargo' => 'vice_direcao'] + $pendente, 'aprovar', false)[1] === 'gestor');
afirmar('coordenadora pedagógica: Coordenação', proximoEstadoDoMembro(['cargo' => 'coordenacao'] + $pendente, 'aprovar', false)[1] === 'coordenador');
afirmar('quem aprova escolhe Coordenação para a diretora',
    proximoEstadoDoMembro(['cargo' => 'direcao'] + $pendente, 'aprovar', false, 'coordenador') === ['ativo', 'coordenador', null]);
afirmar('quem aprova escolhe Gestão para a secretaria',
    proximoEstadoDoMembro(['cargo' => 'secretaria'] + $pendente, 'aprovar', false, 'gestor') === ['ativo', 'gestor', null]);
afirmar('escola sem gestão: a pessoa aprovada é gestor mesmo com Coordenação escolhida',
    proximoEstadoDoMembro(['cargo' => 'secretaria'] + $pendente, 'aprovar', true, 'coordenador') === ['ativo', 'gestor', null]);
afirmar('cargo: válidos e inválidos', cargoValido('direcao') && cargoValido('outro') && !cargoValido('diretor') && !cargoValido(null) && !cargoValido(''));
afirmar('cargo: sugestão de permissão', papelSugeridoPeloCargo('direcao') === 'gestor' && papelSugeridoPeloCargo('vice_direcao') === 'gestor'
    && papelSugeridoPeloCargo('coordenacao') === 'coordenador' && papelSugeridoPeloCargo(null) === 'coordenador');
$semEmail = ['email_verificado' => false] + $pendente;
afirmar('aprovar conta que não confirmou o e-mail: recusado, sem mudar nada', proximoEstadoDoMembro($semEmail, 'aprovar', true) === ['pendente', 'coordenador',
    proximoEstadoDoMembro($semEmail, 'aprovar', true)[2]] && str_contains((string) proximoEstadoDoMembro($semEmail, 'aprovar', true)[2], 'e-mail'));
afirmar('recusar conta que não confirmou o e-mail: pode (limpa a fila)', proximoEstadoDoMembro($semEmail, 'recusar', false)[0] === 'recusado');
afirmar('reativar conta recusada sem e-mail confirmado: recusado',
    proximoEstadoDoMembro(['situacao' => 'recusado', 'papel' => 'coordenador', 'email_verificado' => false], 'reativar', false)[2] !== null);
afirmar('aprovar conta já ativa: recusado', proximoEstadoDoMembro($gestorAtivo, 'aprovar', false)[2] !== null);
afirmar('recusar conta pendente', proximoEstadoDoMembro($pendente, 'recusar', false)[0] === 'recusado');
afirmar('desativar a última gestão: recusado (a escola ficaria sem gestor)', proximoEstadoDoMembro($gestorAtivo, 'desativar', true)[2] !== null);
afirmar('desativar gestor quando há outro', proximoEstadoDoMembro($gestorAtivo, 'desativar', false)[0] === 'desativado');
afirmar('rebaixar a última gestão: recusado', proximoEstadoDoMembro($gestorAtivo, 'tornar_coordenador', true)[2] !== null);
afirmar('promover coordenador ativo', proximoEstadoDoMembro(['situacao' => 'ativo', 'papel' => 'coordenador'], 'tornar_gestor', false) === ['ativo', 'gestor', null]);
afirmar('promover conta pendente: recusado (aprove antes)', proximoEstadoDoMembro($pendente, 'tornar_gestor', false)[2] !== null);
afirmar('reativar conta desativada', proximoEstadoDoMembro(['situacao' => 'desativado', 'papel' => 'coordenador', 'email_verificado' => true], 'reativar', false)[0] === 'ativo');

// ---------- confirmação de e-mail (helpers/emailConta.php) ----------
afirmar('link: pode pedir o primeiro', esperaParaNovoLink(0, null) === null);
afirmar('link: menos de um minuto depois do anterior, espera', str_contains((string) esperaParaNovoLink(1, 30.0), 'minuto'));
afirmar('link: depois de um minuto, pode', esperaParaNovoLink(1, 61.0) === null);
afirmar('link: no máximo cinco por dia', esperaParaNovoLink(LINKS_POR_DIA, 7200.0) !== null && esperaParaNovoLink(LINKS_POR_DIA - 1, 7200.0) === null);
$codigo = str_repeat('a1', 32);
afirmar('código do link: 64 caracteres hexadecimais', codigoDeConfirmacaoValido($codigo) && !codigoDeConfirmacaoValido(substr($codigo, 1))
    && !codigoDeConfirmacaoValido(str_repeat('g', 64)) && !codigoDeConfirmacaoValido(null) && !codigoDeConfirmacaoValido(['x']));
afirmar('o banco guarda só o hash do código', hashDoCodigo($codigo) === hash('sha256', $codigo) && hashDoCodigo($codigo) !== $codigo);

putenv('SITE_URL=https://biofrequencia.example/');
afirmar('endereço do site: SITE_URL, sem a barra no fim', urlDoSite() === 'https://biofrequencia.example'
    && linkDeConfirmacao($codigo) === "https://biofrequencia.example/confirmar-email?codigo=$codigo");
putenv('SITE_URL');
putenv('CORS_ORIGENS=https://site-git-*-equipe.vercel.app,https://site.example');
afirmar('sem SITE_URL: a primeira origem fixa do CORS (pula as com *)', urlDoSite() === 'https://site.example');
putenv('CORS_ORIGENS');

[$assunto, $texto, $html] = mensagemDeConfirmacao('Ana  Lima <b>', 'E.E. Helen Keller', 'https://site.example/confirmar-email?codigo=x', 'cadastro');
afirmar('e-mail de confirmação: assunto, primeiro nome, escola e link', str_contains($assunto, 'Confirme o seu e-mail') && str_contains($texto, 'Olá, Ana!')
    && str_contains($texto, 'E.E. Helen Keller') && str_contains($texto, 'https://site.example/confirmar-email?codigo=x'));
afirmar('e-mail de confirmação: o HTML escapa o que veio da pessoa', str_contains($html, 'href="https://site.example/confirmar-email?codigo=x"')
    && !str_contains(mensagemDeConfirmacao('<script>', 'E.E. <i>', 'https://x', 'cadastro')[2], '<script>')
    && !str_contains(mensagemDeConfirmacao('<script>', 'E.E. <i>', 'https://x', 'cadastro')[2], '<i>'));
afirmar('e-mail de troca: fala do e-mail novo e diz o que acontece se não foi a pessoa',
    str_contains(mensagemDeConfirmacao('Ana', 'E.E. X', 'https://x', 'troca_email')[0], 'novo e-mail')
    && str_contains(mensagemDeConfirmacao('Ana', 'E.E. X', 'https://x', 'troca_email')[1], 'continua o mesmo'));

afirmar('cadastro com o e-mail enviado: diz para onde foi e quem aprova (equipe)',
    str_contains(mensagemDoCadastro('ana@escola.test', 'E.E. X', 'equipe', ENVIO_OK), 'ana@escola.test')
    && str_contains(mensagemDoCadastro('ana@escola.test', 'E.E. X', 'equipe', ENVIO_OK), 'equipe do BioFrequência'));
afirmar('cadastro numa escola com gestão: quem aprova é a gestão', str_contains(mensagemDoCadastro('ana@escola.test', 'E.E. X', 'gestao', ENVIO_OK), 'gestão da E.E. X'));
afirmar('cadastro sem provedor de e-mail: não finge que enviou', str_contains(mensagemDoCadastro('a@b.test', 'E.E. X', 'equipe', ENVIO_SEM_PROVEDOR), 'não foi configurado')
    && !str_contains(mensagemDoCadastro('a@b.test', 'E.E. X', 'equipe', ENVIO_SEM_PROVEDOR), 'Enviamos'));
afirmar('cadastro com falha no envio: sugere reenviar', str_contains(mensagemDoCadastro('a@b.test', 'E.E. X', 'equipe', ENVIO_FALHOU), 'Reenviar'));

[$assunto, $texto] = mensagemDePedidoDeAcesso(['nome_coordenacao' => 'Bia Souza', 'email_coordenacao' => 'bia@escola.test',
    'nome_escola' => 'E.E. X', 'municipio' => 'SANTOS'], 'https://site.example/admin?aba=contas');
afirmar('pedido de acesso para quem aprova: quem, de que escola e onde aprovar', str_contains($assunto, 'Bia Souza') && str_contains($texto, 'bia@escola.test')
    && str_contains($texto, 'E.E. X (SANTOS)') && str_contains($texto, 'https://site.example/admin?aba=contas'));
$aprovada = mensagemDoResultadoDoPedido('Bia Souza', 'E.E. X', 'aprovar', 'https://site.example');
afirmar('conta aprovada: e-mail com o link do login', $aprovada !== null && str_contains($aprovada[0], 'aprovada') && str_contains($aprovada[1], 'https://site.example/login'));
$recusada = mensagemDoResultadoDoPedido('Bia Souza', 'E.E. X', 'recusar', 'https://site.example');
afirmar('conta recusada: e-mail avisando', $recusada !== null && str_contains($recusada[0], 'não foi aprovado'));
afirmar('outras ações não mandam e-mail', mensagemDoResultadoDoPedido('Bia', 'E.E. X', 'desativar', 'https://x') === null);
putenv('ADMIN_EMAILS= Equipe@Escola.test , outra@escola.test');
afirmar('administradores vêm de ADMIN_EMAILS, sem diferenciar maiúsculas',
    ehAdministrador('equipe@escola.test') && ehAdministrador('OUTRA@escola.test') && !ehAdministrador('coord@escola.test'));
putenv('ADMIN_EMAILS');
afirmar('sem ADMIN_EMAILS ninguém é administrador', !ehAdministrador('equipe@escola.test'));

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
$alta = [['prioridade' => 'alta', 'tipo' => 'atrasos']];
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
    calcularNivelAluno([['prioridade' => 'alta', 'tipo' => 'atrasos'], ['prioridade' => 'alta', 'tipo' => 'saidas']], null, $agora)['nivel'] === 4
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

// ---------- conta: duração da sessão ----------
afirmar('sessão do site: 2 horas', duracaoDaSessao('site', false) === 2 * 3600);
afirmar('site com "Lembrar de mim": 30 dias', duracaoDaSessao('site', true) === 30 * 86400);
afirmar('app: 12 horas, com ou sem lembrar', duracaoDaSessao('app', false) === 12 * 3600 && duracaoDaSessao('app', true) === 12 * 3600);

// ---------- conta: nome do aparelho ----------
$uaChromeWindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
$uaEdge = $uaChromeWindows . ' Edg/130.0.0.0';
$uaChromeAndroid = 'Mozilla/5.0 (Linux; Android 14; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
$uaSafariIphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
$uaFirefoxLinux = 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0';
afirmar('aparelho: Chrome no Windows', descreverDispositivo($uaChromeWindows, 'site') === 'Chrome no Windows');
afirmar('aparelho: Edge antes de Chrome', descreverDispositivo($uaEdge, 'site') === 'Edge no Windows');
afirmar('aparelho: Android antes de Linux', descreverDispositivo($uaChromeAndroid, 'site') === 'Chrome no Android');
afirmar('aparelho: iPhone antes de macOS', descreverDispositivo($uaSafariIphone, 'site') === 'Safari no iOS');
afirmar('aparelho: Firefox no Linux', descreverDispositivo($uaFirefoxLinux, 'site') === 'Firefox no Linux');
afirmar('aparelho: o software desktop', descreverDispositivo('BioFrequencia-Desktop/2.0.0 (Windows NT 10.0)', 'site') === 'Software desktop (Windows)');
afirmar('aparelho: sem User-Agent', descreverDispositivo(null, 'site') === 'Navegador');
afirmar('aparelho: login do app', descreverDispositivo($uaChromeAndroid, 'app') === 'App BioFrequência (Android)');

// ---------- conta: preferências ----------
[$prefs, $erro] = lerPreferencias(['tema' => 'escuro', 'volume_sons' => 40], PREFERENCIAS_PADRAO);
afirmar('preferências: muda só o que veio', $erro === null && $prefs['tema'] === 'escuro' && $prefs['volume_sons'] === 40 && $prefs['sons_ativos'] === true);
[$prefs, $erro] = lerPreferencias(['campo_estranho' => 1], PREFERENCIAS_PADRAO);
afirmar('preferências: campo desconhecido é ignorado', $erro === null && $prefs === PREFERENCIAS_PADRAO);
afirmar('preferências: tema inválido', lerPreferencias(['tema' => 'azul'], PREFERENCIAS_PADRAO)[1] !== null);
afirmar('preferências: volume acima de 100', lerPreferencias(['volume_sons' => 101], PREFERENCIAS_PADRAO)[1] !== null);
afirmar('preferências: volume não inteiro', lerPreferencias(['volume_sons' => 50.5], PREFERENCIAS_PADRAO)[1] !== null);
afirmar('preferências: liga/desliga precisa ser booleano', lerPreferencias(['som_erro' => 'false'], PREFERENCIAS_PADRAO)[1] !== null);

// ---------- conta: chamado de suporte ----------
[$chamado, $erro] = lerChamado(['categoria' => 'biometria', 'assunto' => '  Rosto não reconhecido ', 'mensagem' => 'O tablet não acha o rosto de nenhum aluno.']);
afirmar('chamado válido, com espaços aparados', $erro === null && $chamado['assunto'] === 'Rosto não reconhecido');
afirmar('chamado: categoria desconhecida', lerChamado(['categoria' => 'outra', 'assunto' => 'Assunto', 'mensagem' => 'Mensagem longa o bastante.'])[1] !== null);
afirmar('chamado: assunto curto demais', lerChamado(['categoria' => 'duvida', 'assunto' => 'Oi', 'mensagem' => 'Mensagem longa o bastante.'])[1] !== null);
afirmar('chamado: mensagem curta demais', lerChamado(['categoria' => 'duvida', 'assunto' => 'Assunto', 'mensagem' => 'Curta'])[1] !== null);
afirmar('chamado: mensagem longa demais', lerChamado(['categoria' => 'duvida', 'assunto' => 'Assunto', 'mensagem' => str_repeat('a', 4001)])[1] !== null);

// ---------- hash de senha (helpers/senha.php) ----------
$comArgon = defined('PASSWORD_ARGON2ID');
$hashNovo = gerarHashSenha('Senha@123');
afirmar('hash novo: Argon2id (ou bcrypt num PHP sem argon2)', str_starts_with($hashNovo, $comArgon ? '$argon2id$' : '$2y$'));
afirmar('hash novo confere e não precisa atualizar', verificarSenhaComMigracao('Senha@123', $hashNovo) === ['valida' => true, 'precisaAtualizar' => false]);
afirmar('senha errada contra hash novo', verificarSenhaComMigracao('Senha@124', $hashNovo) === ['valida' => false, 'precisaAtualizar' => false]);
$hashBcrypt = password_hash('Senha@123', PASSWORD_BCRYPT);
afirmar('hash bcrypt antigo: confere e pede conversão (com argon2)', verificarSenhaComMigracao('Senha@123', $hashBcrypt) === ['valida' => true, 'precisaAtualizar' => $comArgon]);
afirmar('hash antigo com senha errada: não pede conversão', verificarSenhaComMigracao('errada', $hashBcrypt) === ['valida' => false, 'precisaAtualizar' => false]);
afirmar('texto puro legado: confere e pede conversão', verificarSenhaComMigracao('antiga', 'antiga') === ['valida' => true, 'precisaAtualizar' => true]);
afirmar('hash para e-mail inexistente nunca confere', !password_verify('Senha@123', HASH_PARA_EMAIL_INEXISTENTE)
    && str_starts_with(HASH_PARA_EMAIL_INEXISTENTE, '$argon2id$'));

// ---------- limite de tentativas de login (helpers/tentativas.php) ----------
afirmar('login: 4 erros com o e-mail ainda deixam tentar', esperaParaNovoLogin([10, 20, 30, 40], []) === null);
afirmar('login: 5 erros em 15 min bloqueiam até o quinto mais novo sair da janela', esperaParaNovoLogin([10, 20, 30, 40, 50], []) === 850);
afirmar('login: erro de mais de 15 min não conta', esperaParaNovoLogin([10, 20, 30, 40, 1000], []) === null);
afirmar('login: com 6 erros, vale o quinto mais novo', esperaParaNovoLogin([60, 10, 50, 20, 40, 30], []) === 850);
afirmar('login: 19 erros da rede ainda deixam tentar', esperaParaNovoLogin([], array_fill(0, 19, 5)) === null);
afirmar('login: 20 erros da rede bloqueiam a rede', esperaParaNovoLogin([], array_fill(0, 20, 100)) === 800);
afirmar('login: com os dois limites, vale a espera maior', esperaParaNovoLogin([1, 2, 3, 4, 5], array_fill(0, 20, 600)) === 895);
afirmar('login: mensagem em minutos (arredonda para cima)', mensagemDeEsperaDoLogin(61) === 'Muitas tentativas erradas. Por segurança, espere 2 minutos e tente de novo.'
    && str_contains(mensagemDeEsperaDoLogin(30), 'espere 1 minuto '));
afirmar('login: e-mail igual com caixa e espaços diferentes = mesma chave', chaveDoEmailDeLogin(' Ana@Escola.test ') === chaveDoEmailDeLogin('ana@escola.test'));
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9';
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
afirmar('IP: o último do X-Forwarded-For (o que o balanceador acrescentou)', ipDoCliente() === '203.0.113.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'lixo';
afirmar('IP: X-Forwarded-For inválido cai no REMOTE_ADDR', ipDoCliente() === '10.0.0.1');
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);

// ---------- e-mail de senha nova (helpers/emailConta.php) ----------
[$assuntoSenha, $textoSenha, $htmlSenha] = mensagemDeNovaSenha('Ana Lima', 'https://site.test/redefinir-senha?codigo=abc');
afirmar('e-mail de senha nova: link, validade de 1 hora e "ignore se não foi você"', str_contains($textoSenha, 'https://site.test/redefinir-senha?codigo=abc')
    && str_contains($textoSenha, '1 hora') && str_contains($textoSenha, 'ignore') && str_contains($assuntoSenha, 'senha nova')
    && str_contains($htmlSenha, 'Criar uma senha nova'));

// ---------- importação de turmas e alunos por planilha ----------
afirmar('importação: cabeçalho sem acento e sem diferença de maiúsculas', normalizarTexto('  Período  da AULA ') === 'periodo da aula');
$cabecalho = mapearCabecalho([1 => 'Nome completo', 2 => 'TURMA', 3 => 'Data de Nascimento', 4 => 'Sexo', 5 => 'Observações'], COLUNAS_ALUNOS, COLUNAS_OBRIGATORIAS['alunos']);
afirmar('importação: acha as colunas pelos nomes aceitos (Sexo = Gênero)', $cabecalho['mapa'] === ['nome' => 1, 'turma' => 2, 'nascimento' => 3, 'genero' => 4]
    && $cabecalho['faltando'] === [] && $cabecalho['ignoradas'] === ['Observações']);
$semGenero = mapearCabecalho([1 => 'Aluno', 2 => 'Sala', 3 => 'Nascimento'], COLUNAS_ALUNOS, COLUNAS_OBRIGATORIAS['alunos']);
afirmar('importação: diz qual coluna obrigatória falta', $semGenero['faltando'] === ['Genero']);
afirmar('importação: período por sinônimo', lerPeriodo('manhã') === 'Matutino' && lerPeriodo('TARDE') === 'Vespertino'
    && lerPeriodo('Noite') === 'Noturno' && lerPeriodo('integral') === 'Integral' && lerPeriodo('Indefinido') === null && lerPeriodo('') === null);
afirmar('importação: gênero do aluno por letra ou nome', lerGeneroAluno('f') === 'Feminino' && lerGeneroAluno(' MASC ') === 'Masculino' && lerGeneroAluno('X') === null);
afirmar('importação: canal em branco vale WhatsApp', lerCanal('') === 'whatsapp' && lerCanal('E-mail') === 'email' && lerCanal('Ambos') === 'ambos' && lerCanal('carta') === null);
afirmar('importação: horário em texto', lerHora('7:05') === ['07:05', null] && lerHora('07h30') === ['07:30', null] && lerHora('13h') === ['13:00', null]);
afirmar('importação: horário guardado pelo Excel (fração do dia)', lerHora(0.3125) === ['07:30', null] && lerHora(null) === [null, null]);
afirmar('importação: horário impossível', lerHora('25:00')[1] !== null && lerHora('tarde')[1] !== null);
afirmar('importação: data guardada pelo Excel (dias desde 30/12/1899)', lerDataDeNascimento(40179) === ['2010-01-01', null] && lerDataDeNascimento('40179') === ['2010-01-01', null]);
afirmar('importação: data em texto', lerDataDeNascimento('05/03/2014') === ['2014-03-05', null] && lerDataDeNascimento('5-3-2014') === ['2014-03-05', null]
    && lerDataDeNascimento('2014-03-05') === ['2014-03-05', null]);
afirmar('importação: data inválida, futura ou em branco', lerDataDeNascimento('31/02/2014')[1] !== null
    && lerDataDeNascimento(date('d/m/Y', strtotime('+2 days')))[1] !== null && lerDataDeNascimento('')[1] !== null);
afirmar('importação: célula com número inteiro vira texto sem ".0"', textoDaCelula(12345.0) === '12345' && textoDaCelula('  1º   A ') === '1º A');

$turmaOk = conferirLinhaDeTurma(['turma' => ' 6º  B ', 'periodo' => 'tarde', 'inicio' => '13:00', 'fim' => '18:20']);
afirmar('importação: turma válida sai arrumada', $turmaOk['erros'] === [] && $turmaOk['nome'] === '6º B' && $turmaOk['periodo'] === 'Vespertino'
    && $turmaOk['inicio'] === '13:00' && $turmaOk['fim'] === '18:20');
$turmaRuim = conferirLinhaDeTurma(['turma' => '', 'periodo' => 'madrugada', 'inicio' => '10:00', 'fim' => null]);
afirmar('importação: turma sem nome, com período desconhecido e só o início', count($turmaRuim['erros']) === 3);
afirmar('importação: fim da aula antes do início', in_array('o fim da aula precisa ser depois do início',
    conferirLinhaDeTurma(['turma' => '1º A', 'periodo' => 'Matutino', 'inicio' => '12:00', 'fim' => '07:00'])['erros'], true));

$alunoOk = conferirLinhaDeAluno(['nome' => 'Ana Lima', 'turma' => '1º A', 'nascimento' => '02/03/2015', 'genero' => 'F']);
afirmar('importação: aluno sem responsável', $alunoOk['erros'] === [] && $alunoOk['responsavel'] === null && $alunoOk['nascimento'] === '2015-03-02');
$comResponsavel = conferirLinhaDeAluno(['nome' => 'Ana Lima', 'turma' => '1º A', 'nascimento' => '02/03/2015', 'genero' => 'F',
    'responsavel' => 'Maria Lima', 'cpf' => 52998224725, 'telefone' => '(11) 98888-7777', 'email' => 'Maria@Escola.test', 'parentesco' => 'Mãe', 'canal' => 'ambos']);
afirmar('importação: responsável com CPF e telefone só com dígitos e e-mail minúsculo', $comResponsavel['erros'] === []
    && $comResponsavel['responsavel']['cpf'] === '52998224725' && $comResponsavel['responsavel']['telefone'] === '11988887777'
    && $comResponsavel['responsavel']['email'] === 'maria@escola.test' && $comResponsavel['responsavel']['canal'] === 'ambos');
$cpfSemZero = conferirLinhaDeAluno(['nome' => 'Bia', 'turma' => '1º A', 'nascimento' => '02/03/2015', 'genero' => 'F',
    'responsavel' => 'Rui', 'cpf' => 4580213696.0, 'telefone' => '11977776666']);
afirmar('importação: CPF que o Excel guardou como número volta com o zero da frente', $cpfSemZero['responsavel']['cpf'] === '04580213696' && $cpfSemZero['erros'] === []);
$responsavelIncompleto = conferirLinhaDeAluno(['nome' => 'Ana', 'turma' => '1º A', 'nascimento' => '02/03/2015', 'genero' => 'F', 'email' => 'a@b.test', 'canal' => 'e-mail']);
afirmar('importação: responsável começado pede nome, CPF e telefone', count($responsavelIncompleto['erros']) === 3);
afirmar('importação: avisar por e-mail sem e-mail', in_array('para avisar por e-mail, informe o e-mail do responsável', conferirLinhaDeAluno([
    'nome' => 'Ana', 'turma' => '1º A', 'nascimento' => '02/03/2015', 'genero' => 'F', 'responsavel' => 'Rui', 'cpf' => '52998224725',
    'telefone' => '11977776666', 'canal' => 'E-mail'])['erros'], true));

$escolaVazia = ['turmas' => [], 'alunos' => [], 'responsaveis' => [], 'telefones' => [], 'emails' => []];
$escola = [
    'turmas' => ['1o a' => ['id_sala' => 1, 'nome_sala' => '1º A']],
    'alunos' => ['carla dias|2014-05-01' => true],
    'responsaveis' => [
        '52998224725' => ['id_responsavel' => 7, 'nome' => 'Maria Lima', 'telefone' => '11988887777', 'email' => null, 'vinculos' => 1, 'na_escola' => true],
        '11144477735' => ['id_responsavel' => 8, 'nome' => 'Outra Escola', 'telefone' => '11955554444', 'email' => null, 'vinculos' => 2, 'na_escola' => false],
    ],
    'telefones' => ['11988887777' => '52998224725', '11955554444' => '11144477735'],
    'emails' => [],
];
$previa = montarPreviaDaImportacao([
    'turmas' => [
        [2, ['turma' => '1º A', 'periodo' => 'Matutino']],
        [3, ['turma' => '2º B', 'periodo' => 'Vespertino']],
        [4, ['turma' => '2º  b', 'periodo' => 'Vespertino']],
        [5, ['turma' => '3º C', 'periodo' => '']],
    ],
    'alunos' => [
        [2, ['nome' => 'Ana Lima', 'turma' => '1º a', 'nascimento' => '02/03/2015', 'genero' => 'F', 'responsavel' => 'Maria', 'cpf' => '52998224725', 'telefone' => '11988887777']],
        [3, ['nome' => 'Bruno Lima', 'turma' => '2º B', 'nascimento' => '10/10/2013', 'genero' => 'M', 'responsavel' => 'João Souza', 'cpf' => '39053344705', 'telefone' => '11933332222']],
        [4, ['nome' => 'Caio Lima', 'turma' => '2º B', 'nascimento' => '11/11/2016', 'genero' => 'M', 'responsavel' => 'João Souza', 'cpf' => '39053344705', 'telefone' => '11933332222']],
        [5, ['nome' => 'Carla Dias', 'turma' => '1º A', 'nascimento' => '01/05/2014', 'genero' => 'F']],
        [6, ['nome' => 'Davi', 'turma' => '3º C', 'nascimento' => '01/01/2015', 'genero' => 'M']],
        [7, ['nome' => 'Eva', 'turma' => '9º Z', 'nascimento' => '01/01/2015', 'genero' => 'F']],
        [8, ['nome' => 'Bruno  LIMA', 'turma' => '2º B', 'nascimento' => '10/10/2013', 'genero' => 'M']],
        [9, ['nome' => 'Fábio', 'turma' => '1º A', 'nascimento' => '01/01/2015', 'genero' => 'M', 'responsavel' => 'X', 'cpf' => '11144477735', 'telefone' => '11911112222']],
        [10, ['nome' => 'Gil', 'turma' => '1º A', 'nascimento' => '01/01/2015', 'genero' => 'M', 'responsavel' => 'Y', 'cpf' => '86288366757', 'telefone' => '11955554444']],
    ],
], $escola);
$situacoes = fn(array $linhas) => array_map(fn($l) => $l['linha'] . ':' . $l['situacao'], $linhas);
afirmar('importação: turma que a escola já tem fica como está; repetida e sem período são erro',
    $situacoes($previa['turmas']) === ['2:existente', '3:nova', '4:erro', '5:erro']);
afirmar('importação: alunos novos, irmãos, já cadastrado, turma com erro, turma inexistente, repetido, CPF de outra escola e telefone de outro',
    $situacoes($previa['alunos']) === ['2:novo', '3:novo', '4:novo', '5:existente', '6:erro', '7:erro', '8:erro', '9:erro', '10:erro']);
afirmar('importação: o aluno usa o nome da turma como está na escola', $previa['alunos'][0]['turma'] === '1º A');
afirmar('importação: responsável que já está na escola só é vinculado', $previa['alunos'][0]['responsavel']['situacao'] === 'vincular'
    && $previa['alunos'][0]['responsavel']['id_responsavel'] === 7);
afirmar('importação: irmãos com o mesmo CPF - o segundo usa o responsável do primeiro',
    $previa['alunos'][1]['responsavel']['situacao'] === 'novo' && $previa['alunos'][2]['responsavel']['situacao'] === 'mesmo');
afirmar('importação: os erros dizem o motivo', str_contains(implode(' ', $previa['alunos'][4]['erros']), 'tem erro na aba Turmas')
    && str_contains(implode(' ', $previa['alunos'][5]['erros']), 'não existe na escola')
    && str_contains(implode(' ', $previa['alunos'][6]['erros']), 'repetido na planilha (linha 3)')
    && str_contains(implode(' ', $previa['alunos'][7]['erros']), 'outra escola')
    && str_contains(implode(' ', $previa['alunos'][8]['erros']), 'telefone do responsável já é de outro'));
afirmar('importação: resumo da prévia', $previa['resumo'] === [
    'turmas_novas' => 1, 'turmas_existentes' => 1, 'turmas_com_erro' => 2,
    'alunos_novos' => 3, 'alunos_existentes' => 1, 'alunos_com_erro' => 5,
    'responsaveis_novos' => 1, 'responsaveis_vinculados' => 2,
]);
$soAlunos = montarPreviaDaImportacao(['alunos' => [[2, ['nome' => 'Ana', 'turma' => '1º A', 'nascimento' => '02/03/2015', 'genero' => 'F']]]], $escolaVazia);
afirmar('importação: sem a turma na escola nem no arquivo, o aluno não entra', $soAlunos['alunos'][0]['situacao'] === 'erro' && $soAlunos['turmas'] === []);
afirmar('importação: mensagem final com plural e "e" antes do último', mensagemDaImportacao(2, 3, 1) === 'Importação concluída: 2 turmas, 3 alunos e 1 responsável cadastrados.');
afirmar('importação: mensagem final só com turmas concorda no feminino', mensagemDaImportacao(1, 0, 0) === 'Importação concluída: 1 turma cadastrada.'
    && mensagemDaImportacao(4, 0, 0) === 'Importação concluída: 4 turmas cadastradas.');
afirmar('importação: mensagem final no singular e sem turmas', mensagemDaImportacao(0, 1, 0) === 'Importação concluída: 1 aluno cadastrado.'
    && mensagemDaImportacao(0, 2, 2) === 'Importação concluída: 2 alunos e 2 responsáveis cadastrados.');

// ---------- importação de entradas e saídas registradas sem internet ----------
afirmar('ocorrências: tipo pelo nome ou pela palavra solta', lerTipoDeOcorrencia('Entrada atrasada') === 'atraso' && lerTipoDeOcorrencia(' ATRASO ') === 'atraso'
    && lerTipoDeOcorrencia('Saída antecipada') === 'saida' && lerTipoDeOcorrencia('saida') === 'saida' && lerTipoDeOcorrencia('falta') === null);
afirmar('ocorrências: "Justificada?" em branco é não', lerSimNao(null) === false && lerSimNao('Sim') === true && lerSimNao('NÃO') === false
    && lerSimNao(true) === true && lerSimNao('talvez') === null);
afirmar('ocorrências: código do aluno', lerCodigoDoAluno(12.0) === 12 && lerCodigoDoAluno('#7') === 7 && lerCodigoDoAluno('') === null
    && lerCodigoDoAluno('abc') === false && lerCodigoDoAluno('0') === false);
afirmar('ocorrências: data com ano de dois dígitos e número do Excel', lerDataDaCelula('05/11/26', true) === '2026-11-05'
    && lerDataDaCelula('05/11/26') === null && lerDataDaCelula(46331) === '2026-11-05' && lerDataDaCelula('31/02/2026', true) === null);
$linhaVazia = conferirLinhaDeOcorrencia([]);
afirmar('ocorrências: linha sem aluno, data, horário e tipo diz tudo o que falta', $linhaVazia['erros'] === [
    'informe o código ou o nome do aluno', 'falta a data', 'falta o horário', 'falta o tipo (Entrada atrasada ou Saída antecipada)',
], $linhaVazia['erros']);
$linhaAtraso = conferirLinhaDeOcorrencia(['codigo' => 3, 'data' => '05/11/2026', 'horario' => 0.3125, 'tipo' => 'Entrada atrasada', 'retirado_por' => 'Mãe']);
afirmar('ocorrências: "Quem buscou" numa entrada atrasada vira aviso e fica de fora', $linhaAtraso['erros'] === [] && $linhaAtraso['hora'] === '07:30'
    && $linhaAtraso['retirado_por'] === null && count($linhaAtraso['avisos']) === 1);
$linhaRuim = conferirLinhaDeOcorrencia(['nome' => 'Ana', 'data' => 'ontem', 'horario' => '25:00', 'tipo' => 'falta', 'motivo' => str_repeat('x', 151), 'justificada' => 'talvez']);
afirmar('ocorrências: data, horário, tipo, motivo e "Justificada?" inválidos', count($linhaRuim['erros']) === 5, $linhaRuim['erros']);

// Ana (1º A, manhã 07:00-12:20), dois Bruno Lima (1º A e 2º B) e Caio (2º B,
// 13:30-18:00). Quinta 05/11/2026 é dia de aula; 02/11 é Finados; 31/10 é sábado.
$escolaDeOcorrencias = [
    'alunos' => [
        10 => ['nome' => 'Ana Souza', 'turma' => '1º A', 'periodo' => 'Matutino', 'inicio' => null, 'fim' => null],
        11 => ['nome' => 'Bruno Lima', 'turma' => '1º A', 'periodo' => 'Matutino', 'inicio' => null, 'fim' => null],
        12 => ['nome' => 'Bruno Lima', 'turma' => '2º B', 'periodo' => 'Vespertino', 'inicio' => null, 'fim' => null],
        13 => ['nome' => 'Caio Reis', 'turma' => '2º B', 'periodo' => 'Vespertino', 'inicio' => '13:30', 'fim' => '18:00'],
    ],
    'registros' => ['atraso|10|2026-11-04' => '07:45'],
    'calendario' => [],
    'tolerancia' => 10,
];
$ocorrencia = fn(int $linha, array $valores) => [$linha, $valores + ['data' => '05/11/2026', 'tipo' => 'Entrada atrasada']];
$previaOc = montarPreviaDeOcorrencias([
    $ocorrencia(2, ['codigo' => 10, 'horario' => '07:40']),                                           // nova
    $ocorrencia(3, ['codigo' => 10, 'horario' => '08:00']),                                           // repetida no arquivo
    $ocorrencia(4, ['codigo' => 10, 'data' => '04/11/2026', 'horario' => '07:50']),                   // já registrada
    $ocorrencia(5, ['codigo' => 99, 'horario' => '07:40']),                                           // código de outra escola
    $ocorrencia(6, ['codigo' => 11, 'nome' => 'Ana Souza', 'horario' => '07:40']),                    // código de outro aluno
    $ocorrencia(7, ['nome' => 'Bruno Lima', 'horario' => '07:40']),                                   // homônimos sem turma
    $ocorrencia(8, ['nome' => 'bruno  lima', 'turma' => '2º B', 'horario' => '15:00', 'tipo' => 'Saída antecipada', 'retirado_por' => 'Pai']), // nova
    $ocorrencia(9, ['codigo' => 13, 'data' => '31/10/2026', 'horario' => '14:00', 'tipo' => 'saida']), // sábado
    $ocorrencia(10, ['codigo' => 13, 'horario' => '13:35']),                                          // dentro da tolerância
    $ocorrencia(11, ['codigo' => 11, 'data' => '06/11/2026', 'horario' => '11:00']),                  // depois de agora
    $ocorrencia(12, ['codigo' => 11, 'turma' => '2º B', 'horario' => '10:00', 'tipo' => 'saida']),    // turma errada
    $ocorrencia(13, ['codigo' => 11, 'horario' => '10:00', 'tipo' => 'Saída antecipada', 'justificada' => 'Sim', 'motivo' => 'Consulta']), // nova
    $ocorrencia(14, ['codigo' => 13, 'horario' => '10:00']),                                          // fora do horário da turma
    $ocorrencia(15, ['codigo' => 10, 'data' => '02/11/2026', 'horario' => '08:00']),                  // feriado
], $escolaDeOcorrencias, '2026-11-06 10:30');
$situacoesOc = array_column($previaOc['linhas'], 'situacao', 'linha');
$errosOc = array_map(fn($l) => implode(' | ', $l['erros']), array_column($previaOc['linhas'], null, 'linha'));
afirmar('ocorrências: situação de cada linha', $situacoesOc === [
    2 => 'nova', 3 => 'erro', 4 => 'existente', 5 => 'erro', 6 => 'erro', 7 => 'erro', 8 => 'nova', 9 => 'erro',
    10 => 'erro', 11 => 'erro', 12 => 'erro', 13 => 'nova', 14 => 'erro', 15 => 'erro',
], $situacoesOc);
afirmar('ocorrências: os erros dizem o motivo', str_contains($errosOc[3], 'a linha 2 já tem entrada atrasada')
    && str_contains($errosOc[5], 'código 99') && str_contains($errosOc[6], 'é de Bruno Lima, não de Ana Souza')
    && str_contains($errosOc[7], 'mais de um aluno') && str_contains($errosOc[9], 'sábado') && str_contains($errosOc[10], 'tolerância')
    && str_contains($errosOc[11], 'futuro') && str_contains($errosOc[12], 'é da turma 1º A, não da 2º B')
    && str_contains($errosOc[14], 'das 13:30 às 18:00') && str_contains($errosOc[15], 'feriado'), $errosOc);
$linha8 = $previaOc['linhas'][6];
afirmar('ocorrências: sem código, nome e turma acham o aluno (o nome sai como no cadastro)', $linha8['id_aluno'] === 12
    && $linha8['aluno'] === 'Bruno Lima' && $linha8['tipo'] === 'saida' && $linha8['retirado_por'] === 'Pai');
afirmar('ocorrências: já registrada no sistema avisa o horário que está lá', str_contains(implode(' ', $previaOc['linhas'][2]['avisos']), '07:45'));
afirmar('ocorrências: resumo da prévia', $previaOc['resumo'] === [
    'novas' => 3, 'existentes' => 1, 'com_erro' => 10, 'atrasos_novos' => 1, 'saidas_novas' => 2,
], $previaOc['resumo']);
afirmar('ocorrências: mensagem final com plural e concordância', mensagemDaImportacaoDeOcorrencias(3, 1) === 'Importação concluída: 3 entradas atrasadas e 1 saída antecipada registradas.'
    && mensagemDaImportacaoDeOcorrencias(1, 0) === 'Importação concluída: 1 entrada atrasada registrada.'
    && mensagemDaImportacaoDeOcorrencias(0, 2) === 'Importação concluída: 2 saídas antecipadas registradas.');

// ---------- mensagens que a coordenação envia (helpers/mensagemManual.php) ----------
afirmar('mensagem: as variáveis viram os dados (a desconhecida fica)', montarMensagem('Olá, {responsavel}. {aluno} ({turma}) - {escola}, {data}. {outra}', [
    'responsavel' => 'Maria', 'aluno' => 'Ana Souza', 'turma' => '1º A', 'escola' => 'E.E. Teste', 'data' => '06/11/2026',
]) === 'Olá, Maria. Ana Souza (1º A) - E.E. Teste, 06/11/2026. {outra}');
afirmar('mensagem: primeiro nome do responsável', primeiroNomeDoResponsavel('  Maria  da Silva ') === 'Maria');
afirmar('mensagem: vazia ou longa demais é recusada', conferirTextoDaMensagem('   ')[1] !== null
    && conferirTextoDaMensagem(str_repeat('x', 1001))[1] !== null && conferirTextoDaMensagem(" Oi\r\ntudo bem ")[0] === "Oi\ntudo bem");
[$destinosLidos, $erroDestinos] = lerDestinos([['id_responsavel' => 5, 'canal' => 'whatsapp'], ['id_responsavel' => '5', 'canal' => 'whatsapp'],
    ['id_responsavel' => 5, 'canal' => 'email']]);
afirmar('mensagem: destinos sem repetidos', $erroDestinos === null && count($destinosLidos) === 2);
afirmar('mensagem: canal desconhecido ou nenhum destino é recusado', lerDestinos([['id_responsavel' => 5, 'canal' => 'sms']])[1] !== null
    && lerDestinos([])[1] !== null);
afirmar('mensagem: chave do envio', chaveDeEnvioValida('9b2c1d5e-3f4a-4b6c-8d7e-1f2a3b4c5d6e') && !chaveDeEnvioValida('curta')
    && !chaveDeEnvioValida('abc def ghi') && !chaveDeEnvioValida(null));
$responsaveisTeste = [
    5 => ['nome_responsavel' => 'Maria da Silva', 'telefone_responsavel' => '(11) 98888-7777', 'email_responsavel' => 'maria@familia.test', 'parentesco' => 'Mãe'],
    6 => ['nome_responsavel' => 'João Souza', 'telefone_responsavel' => '123', 'email_responsavel' => null, 'parentesco' => 'Pai'],
];
$envios = montarEnvios(['nome' => 'Ana Souza', 'turma' => '1º A'], $responsaveisTeste,
    [['id_responsavel' => 5, 'canal' => 'whatsapp'], ['id_responsavel' => 5, 'canal' => 'email']],
    'Olá, {responsavel}: {aluno}.', 'E.E. Teste', '2026-11-06', ['whatsapp' => false, 'email' => true]);
afirmar('prévia: a mensagem montada para cada responsável e canal', $envios['erros'] === [] && count($envios['envios']) === 2
    && $envios['envios'][0]['mensagem'] === 'Olá, Maria: Ana Souza.' && $envios['envios'][0]['contato'] === '5511988887777');
afirmar('prévia: WhatsApp sem provedor vira link; e-mail com provedor sai sozinho, com assunto', $envios['envios'][0]['como'] === 'link'
    && $envios['envios'][1]['como'] === 'automatico' && $envios['envios'][1]['assunto'] === 'E.E. Teste: mensagem sobre Ana Souza');
$semContato = montarEnvios(['nome' => 'Ana Souza', 'turma' => '1º A'], $responsaveisTeste,
    [['id_responsavel' => 6, 'canal' => 'whatsapp'], ['id_responsavel' => 6, 'canal' => 'email'], ['id_responsavel' => 99, 'canal' => 'email']],
    'Oi', 'E.E. Teste', '2026-11-06', ['whatsapp' => true, 'email' => true]);
afirmar('prévia: sem telefone, sem e-mail e responsável de outro aluno viram erro', $semContato['envios'] === [] && count($semContato['erros']) === 3);
afirmar('mensagem do envio diz o que saiu, o que ficou no link e se repetiu', mensagemDoEnvio(['enviadas' => 2, 'pendentes' => 1, 'erros' => 0, 'repetidas' => 0])
    === 'Mensagem: 2 enviadas, 1 para mandar pelo link (sem envio automático).'
    && str_contains(mensagemDoEnvio(['enviadas' => 1, 'pendentes' => 0, 'erros' => 0, 'repetidas' => 1]), 'nada foi mandado de novo'));
$textoComSinais = 'Olá, Maria & cia: 50% + 1 = ok? #1';
afirmar('tentar de novo: a mensagem gravada como link wa.me volta a ser o texto', textoDaMensagemGravada(linkWhatsapp('5511988887777', $textoComSinais)) === $textoComSinais
    && textoDaMensagemGravada('https://wa.me/5511988887777?text=Ol%C3%A1+Jo%C3%A3o') === 'Olá João'
    && textoDaMensagemGravada('Texto comum, sem link') === 'Texto comum, sem link'
    && textoDaMensagemGravada('https://wa.me/5511988887777') === 'https://wa.me/5511988887777');

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
