<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/catalogoEscolas.php';

/**
 * Busca no catálogo de escolas, para o cadastro de conta - sem login,
 * porque quem está se cadastrando ainda não tem conta. Só o catálogo
 * oficial (dados públicos da SEDUC): escola criada à mão por outra conta
 * não aparece.
 *
 *   { busca: "maria nilde" | "são bernardo centro" | "11808" }
 *
 * Cada palavra precisa aparecer no nome, no município ou no bairro (sem
 * acento, sem diferenciar maiúsculas); um número também procura o código
 * CIE e o INEP. No máximo 20 escolas, primeiro as que têm o texto todo
 * no nome.
 *
 * "tem_gestao" diz se a escola já usa o sistema: a conta nova vai
 * precisar da aprovação da gestão, e o site avisa antes do cadastro.
 */
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
$busca = normalizarTextoBusca(is_string($dados['busca'] ?? null) ? $dados['busca'] : '');

if (mb_strlen($busca) < 3) {
    enviarErro("Digite pelo menos 3 letras do nome da escola ou do município.", 422);
}

$condicoes = [];
$parametros = [];
foreach (array_slice(explode(' ', $busca), 0, 6) as $termo) {
    $parecido = "%$termo%";
    if (ctype_digit($termo)) {
        $condicoes[] = "(e.codigo_cie::text = ? OR e.codigo_inep = ? OR e.nome_oficial LIKE ?)";
        array_push($parametros, $termo, $termo, $parecido);
    } else {
        $condicoes[] = "(e.nome_oficial LIKE ? OR e.municipio LIKE ? OR e.bairro LIKE ?)";
        array_push($parametros, $parecido, $parecido, $parecido);
    }
}

try {
    $stmt = $conexao->prepare("
        SELECT e.id_escola, e.nome_escola, e.codigo_cie, e.municipio, e.bairro, e.endereco, e.numero,
               e.diretoria_ensino,
               EXISTS (
                   SELECT 1 FROM tb_coordenacao c
                   WHERE c.id_escola = e.id_escola AND c.papel = 'gestor' AND c.situacao = 'ativo'
               ) AS tem_gestao
        FROM tb_escola e
        WHERE e.codigo_cie IS NOT NULL AND " . implode(' AND ', $condicoes) . "
        ORDER BY (e.nome_oficial LIKE ?) DESC, e.nome_oficial, e.municipio
        LIMIT 20
    ");
    $stmt->execute([...$parametros, "%$busca%"]);
    $escolas = array_map(function ($escola) {
        $escola['id_escola'] = (int) $escola['id_escola'];
        $escola['codigo_cie'] = (int) $escola['codigo_cie'];
        $escola['tem_gestao'] = valorBooleano($escola['tem_gestao']);
        return $escola;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    enviarSucesso("Escolas encontradas.", ["escolas" => $escolas]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao buscar escolas.", 500, $erro);
}
