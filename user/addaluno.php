<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/validacao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

// Só coordenadores autenticados podem cadastrar alunos.
$idCoordenacao = autenticar();

$dados = json_decode(file_get_contents("php://input"), true) ?? [];

// nome_aluno (não "aluno") para ficar consistente com a coluna no banco,
// com o Model.Student do desktop e com o padrão usado no resto da API.
$nomeAluno    = $dados["nome_aluno"] ?? null;
$idSala       = $dados["id_sala"] ?? null;
$dtaNascAluno = $dados["dta_nasc_aluno"] ?? null;
$generoAluno  = $dados["genero_aluno"] ?? null;

$generosAceitos = ["Feminino", "Masculino"];

if (!campoPreenchido($nomeAluno)) {
    enviarErro("Informe o nome do aluno.", 422);
}
if (!inteiroValido($idSala)) {
    enviarErro("Selecione uma sala/turma válida.", 422);
}
if (!dataNascimentoValida($dtaNascAluno)) {
    enviarErro("Informe uma data de nascimento válida (não pode ser futura nem implausível).", 422);
}
if (!campoPreenchido($generoAluno) || !in_array($generoAluno, $generosAceitos, true)) {
    enviarErro("Informe um gênero válido.", 422);
}

$nomeAluno = trim($nomeAluno);

try {
    // A turma precisa existir E pertencer à escola de quem está
    // cadastrando - sem a segunda parte, bastaria mandar o id de uma
    // turma de outra escola para inserir um aluno no cadastro alheio.
    $idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
    $verificaSala = $conexao->prepare("SELECT id_sala FROM tb_sala WHERE id_sala = ? AND id_escola = ?");
    $verificaSala->bindValue(1, $idSala);
    $verificaSala->bindValue(2, $idEscola);
    $verificaSala->execute();

    if ($verificaSala->rowCount() === 0) {
        enviarErro("A sala/turma informada não existe.", 422);
    }

    $insert = $conexao->prepare(
        "INSERT INTO tb_aluno(nome_aluno,id_sala,dta_nasc_aluno,genero_aluno)
         VALUES(?,?,?,?)
         RETURNING id_aluno"
    );
    $insert->bindValue(1, $nomeAluno);
    $insert->bindValue(2, $idSala);
    $insert->bindValue(3, $dtaNascAluno);
    $insert->bindValue(4, $generoAluno);
    $insert->execute();

    $novoAluno = $insert->fetch(PDO::FETCH_ASSOC);

    enviarSucesso("Aluno cadastrado com sucesso.", ["id_aluno" => $novoAluno["id_aluno"]]);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao cadastrar o aluno.", 500, $erro);
}
