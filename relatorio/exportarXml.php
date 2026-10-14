<?php
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/frequencia.php';
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

$relatoriosAceitos = ['alertas', 'alunos', 'acompanhamentos', 'ocorrencias'];
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
            $item->appendChild(elemento($doc, 'frequencia', $a['frequencia']));
            $item->appendChild(elemento($doc, 'motivo', $a['motivo']));
            $item->appendChild(elemento($doc, 'data_referencia', $a['dta_referencia']));
            $itens->appendChild($item);
        }
    } elseif ($relatorio === 'alunos') {
        $stmt = $conexao->prepare("
            SELECT a.id_aluno, a.nome_aluno, s.nome_sala,
                   COALESCE(f.qtd, 0) AS faltas, COALESCE(t.qtd, 0) AS atrasos
            FROM tb_aluno a
            JOIN tb_sala s ON s.id_sala = a.id_sala
            LEFT JOIN (SELECT id_aluno, COUNT(*) qtd FROM tb_falta  WHERE dta_falta  >= ? GROUP BY id_aluno) f ON f.id_aluno = a.id_aluno
            LEFT JOIN (SELECT id_aluno, COUNT(*) qtd FROM tb_atraso WHERE dta_atraso >= ? GROUP BY id_aluno) t ON t.id_aluno = a.id_aluno
            WHERE s.id_escola = ?
            ORDER BY a.nome_aluno
        ");
        $stmt->execute([date('Y-m-01'), date('Y-m-01'), $idEscola]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $frequencia = calcularFrequencia((int) $linha['faltas']);
            $item = $doc->createElement('aluno');
            $item->setAttribute('id', $linha['id_aluno']);
            $item->appendChild(elemento($doc, 'nome', $linha['nome_aluno']));
            $item->appendChild(elemento($doc, 'turma', $linha['nome_sala']));
            $item->appendChild(elemento($doc, 'faltas_mes', $linha['faltas']));
            $item->appendChild(elemento($doc, 'atrasos_mes', $linha['atrasos']));
            $item->appendChild(elemento($doc, 'frequencia', $frequencia));
            $item->appendChild(elemento($doc, 'situacao', calcularStatusFrequencia($frequencia)));
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

    $nomeArquivo = sprintf('biofrequencia_%s_%s.xml', $relatorio, date('Y-m-d'));

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
