<?php
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/alertas.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Exportação dos relatórios em XML.
 *
 * Por que no servidor e não no navegador: os relatórios em CSV são
 * montados no site (lib/format.ts), o que serve para CSV porque ele é
 * texto separado por ponto e vírgula. XML tem escape de caracteres,
 * declaração de codificação e estrutura - montar isso concatenando
 * string no cliente é como aquele arquivo ".xml" que na verdade saía
 * HTML. Aqui o XML é construído com DOMDocument, que escapa &, <, > e
 * aspas sozinho e garante documento válido.
 *
 * O token vai por POST no corpo (e não só no cabeçalho) porque o
 * download é disparado por um form/link do navegador, que não permite
 * cabeçalho customizado. O cabeçalho continua aceito quando houver.
 */

// Aceita o token no corpo do formulário quando não vier no cabeçalho -
// necessário para o download direto funcionar.
$corpoFormulario = $_POST ?: [];
if (!empty($corpoFormulario['token']) && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $corpoFormulario['token'];
}

$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);

$corpoJson = json_decode(file_get_contents("php://input"), true) ?? [];
$dados = $corpoFormulario ?: $corpoJson;
$relatorio = $dados['relatorio'] ?? 'alertas';

$relatoriosAceitos = ['alertas', 'alunos', 'registros', 'acompanhamentos', 'ocorrencias'];
if (!in_array($relatorio, $relatoriosAceitos, true)) {
    enviarErro("Relatório inválido. Use: " . implode(', ', $relatoriosAceitos) . ".", 422);
}

/** Cria um elemento com texto já escapado pelo DOMDocument. */
function elemento(DOMDocument $doc, string $nome, $valor): DOMElement {
    $el = $doc->createElement($nome);
    // createTextNode (e não o 2º argumento de createElement) porque o
    // 2º argumento NÃO escapa & nem <: um nome "Tião & Cia" ou uma
    // observação com "<" geraria XML inválido, que é exatamente o tipo
    // de arquivo corrompido que se quer evitar.
    $el->appendChild($doc->createTextNode($valor === null ? '' : (string) $valor));
    return $el;
}

try {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $raiz = $doc->createElement('relatorio');
    $raiz->setAttribute('tipo', $relatorio);
    $raiz->setAttribute('gerado_em', date('c'));
    $raiz->setAttribute('sistema', 'BioFrequencia');
    $doc->appendChild($raiz);

    $nomeEscola = $conexao->prepare("SELECT nome_escola FROM tb_escola WHERE id_escola = ?");
    $nomeEscola->bindValue(1, $idEscola);
    $nomeEscola->execute();
    $raiz->appendChild(elemento($doc, 'escola', $nomeEscola->fetchColumn()));

    $itens = $doc->createElement('itens');

    if ($relatorio === 'alertas') {
        foreach (buscarAlertas($conexao, $idEscola) as $a) {
            $item = $doc->createElement('alerta');
            $item->appendChild(elemento($doc, 'aluno', $a['nome_aluno']));
            $item->appendChild(elemento($doc, 'turma', $a['turma']));
            $item->appendChild(elemento($doc, 'tipo', $a['tipo']));
            $item->appendChild(elemento($doc, 'prioridade', $a['prioridade']));
            $item->appendChild(elemento($doc, 'ocorrencias', $a['ocorrencias']));
            $item->appendChild(elemento($doc, 'dias_de_aula', $a['dias_letivos']));
            $item->appendChild(elemento($doc, 'taxa_percentual', $a['taxa']));
            $item->appendChild(elemento($doc, 'motivo', $a['motivo']));
            $item->appendChild(elemento($doc, 'data_referencia', $a['dta_referencia']));
            $itens->appendChild($item);
        }
    } elseif ($relatorio === 'alunos') {
        // Mesmos números da lista de alunos (user/filtroAluno.php): a
        // janela de alerta da escola, contando dias de aula.
        $config = configuracaoDaEscola($conexao, $idEscola);
        $janela = indicadoresDaJanela($conexao, $idEscola, $config);
        $raiz->setAttribute('periodo', $janela['inicio'] . '/' . $janela['fim']);
        foreach ($janela['alunos'] as $linha) {
            $item = $doc->createElement('aluno');
            $item->setAttribute('id', (string) $linha['id_aluno']);
            $item->appendChild(elemento($doc, 'nome', $linha['nome_aluno']));
            $item->appendChild(elemento($doc, 'turma', $linha['nome_sala']));
            $item->appendChild(elemento($doc, 'dias_de_aula', $linha['dias_letivos']));
            $item->appendChild(elemento($doc, 'entradas_atrasadas', $linha['atrasos']));
            $item->appendChild(elemento($doc, 'saidas_antecipadas', $linha['saidas']));
            $item->appendChild(elemento($doc, 'taxa_atrasos', $linha['taxa_atrasos']));
            $item->appendChild(elemento($doc, 'taxa_saidas', $linha['taxa_saidas']));
            $item->appendChild(elemento($doc, 'situacao', situacaoDoAluno($linha['prioridade_atrasos'], $linha['prioridade_saidas'], $linha['dias_letivos'])));
            $itens->appendChild($item);
        }
    } elseif ($relatorio === 'registros') {
        // Entradas atrasadas e saídas antecipadas dos últimos 90 dias.
        $desde = localParaUtc((new DateTimeImmutable(hojeNaEscola()))->modify('-89 days')->format('Y-m-d'));
        $stmt = $conexao->prepare("
            SELECT 'entrada_atrasada' AS tipo, t.dta_atraso AS momento, a.nome_aluno, s.nome_sala, t.motivo_atraso AS motivo,
                   t.justificado_atraso AS justificado, t.origem, NULL AS retirado_por
            FROM tb_atraso t JOIN tb_aluno a ON a.id_aluno = t.id_aluno JOIN tb_sala s ON s.id_sala = a.id_sala
            WHERE s.id_escola = ? AND t.dta_atraso >= ?
            UNION ALL
            SELECT 'saida_antecipada', r.dta_saida, a.nome_aluno, s.nome_sala, r.motivo_saida, r.justificado_saida, NULL, r.retirado_por
            FROM tb_saida_antecipada r JOIN tb_aluno a ON a.id_aluno = r.id_aluno JOIN tb_sala s ON s.id_sala = a.id_sala
            WHERE s.id_escola = ? AND r.dta_saida >= ?
            ORDER BY momento DESC
        ");
        $stmt->execute([$idEscola, $desde, $idEscola, $desde]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $local = (new DateTimeImmutable($linha['momento'], new DateTimeZone('UTC')))->setTimezone(fusoEscola());
            $item = $doc->createElement('registro');
            $item->setAttribute('tipo', $linha['tipo']);
            $item->appendChild(elemento($doc, 'data_hora', $local->format('Y-m-d H:i')));
            $item->appendChild(elemento($doc, 'aluno', $linha['nome_aluno']));
            $item->appendChild(elemento($doc, 'turma', $linha['nome_sala']));
            $item->appendChild(elemento($doc, 'motivo', $linha['motivo']));
            $item->appendChild(elemento($doc, 'justificado', valorBooleano($linha['justificado']) ? 'sim' : 'nao'));
            $item->appendChild(elemento($doc, 'origem', $linha['origem']));
            $item->appendChild(elemento($doc, 'retirado_por', $linha['retirado_por']));
            $itens->appendChild($item);
        }
    } elseif ($relatorio === 'acompanhamentos') {
        $stmt = $conexao->prepare("
            SELECT ac.data_registro, a.nome_aluno, s.nome_sala, ac.canal, ac.observacao, c.nome_coordenacao
            FROM tb_acompanhamento ac
            JOIN tb_aluno a ON a.id_aluno = ac.id_aluno
            JOIN tb_sala  s ON s.id_sala  = a.id_sala
            JOIN tb_coordenacao c ON c.id_coordenacao = ac.id_coordenacao
            WHERE s.id_escola = ?
            ORDER BY ac.data_registro DESC
        ");
        $stmt->execute([$idEscola]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $item = $doc->createElement('acompanhamento');
            $item->appendChild(elemento($doc, 'data', $linha['data_registro']));
            $item->appendChild(elemento($doc, 'aluno', $linha['nome_aluno']));
            $item->appendChild(elemento($doc, 'turma', $linha['nome_sala']));
            $item->appendChild(elemento($doc, 'canal', $linha['canal']));
            $item->appendChild(elemento($doc, 'observacao', $linha['observacao']));
            $item->appendChild(elemento($doc, 'registrado_por', $linha['nome_coordenacao']));
            $itens->appendChild($item);
        }
    } else { // ocorrencias
        $stmt = $conexao->prepare("
            SELECT o.criado_em, o.tipo, o.motivo, o.observacao, o.responsavel_ciente,
                   o.status, o.revisado_em, a.nome_aluno, s.nome_sala
            FROM tb_ocorrencia_pendente o
            JOIN tb_aluno a ON a.id_aluno = o.id_aluno
            JOIN tb_sala  s ON s.id_sala  = a.id_sala
            WHERE s.id_escola = ?
            ORDER BY o.criado_em DESC
        ");
        $stmt->execute([$idEscola]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $item = $doc->createElement('ocorrencia');
            $item->appendChild(elemento($doc, 'enviada_em', $linha['criado_em']));
            $item->appendChild(elemento($doc, 'aluno', $linha['nome_aluno']));
            $item->appendChild(elemento($doc, 'turma', $linha['nome_sala']));
            $item->appendChild(elemento($doc, 'tipo', $linha['tipo']));
            $item->appendChild(elemento($doc, 'motivo', $linha['motivo']));
            $item->appendChild(elemento($doc, 'observacao', $linha['observacao']));
            $item->appendChild(elemento($doc, 'responsavel_ciente', $linha['responsavel_ciente'] ? 'sim' : 'nao'));
            $item->appendChild(elemento($doc, 'situacao', $linha['status']));
            $item->appendChild(elemento($doc, 'revisada_em', $linha['revisado_em']));
            $itens->appendChild($item);
        }
    }

    $itens->setAttribute('quantidade', (string) $itens->childNodes->length);
    $raiz->appendChild($itens);

    $nomeArquivo = sprintf('biofrequencia_%s_%s.xml', $relatorio, hojeNaEscola());

    // Content-Type e Content-Disposition corretos: sem eles o navegador
    // abre o XML na própria aba em vez de baixar, ou salva com nome
    // genérico.
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('X-Content-Type-Options: nosniff');
    echo $doc->saveXML();
} catch (PDOException $erro) {
    header('Content-Type: application/json; charset=utf-8');
    enviarErro("Erro no servidor ao gerar o relatório.", 500, $erro);
}
