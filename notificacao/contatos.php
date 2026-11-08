<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../database/conexao.php';
require_once __DIR__ . '/../helpers/resposta.php';
require_once __DIR__ . '/../helpers/escola.php';
require_once __DIR__ . '/../helpers/mensagemManual.php';
require_once __DIR__ . '/../Authentication/Authentication.php';

/**
 * Os responsáveis de um aluno da escola e por onde dá para falar com cada
 * um:
 *
 *   { id_aluno } -> { aluno: {id_aluno, nome, turma},
 *     responsaveis: [{id_responsavel, nome, parentesco, telefone, email, canal_preferido,
 *                     whatsapp, email_disponivel, sugeridos: ["whatsapp"|"email"]}],
 *     provedores: {whatsapp, email} }
 */
$idCoordenacao = autenticar();
$idEscola = escolaDaCoordenacao($conexao, $idCoordenacao);
$dados = json_decode(file_get_contents("php://input"), true) ?? [];
if (!inteiroValido($dados['id_aluno'] ?? null)) {
    enviarErro("Escolha o aluno.", 422);
}

try {
    $aluno = alunoParaMensagem($conexao, (int) $dados['id_aluno'], $idEscola);
} catch (PDOException $erro) {
    enviarErro("Erro no servidor ao carregar os contatos.", 500, $erro);
}
if ($aluno === null) {
    enviarErro("Aluno não encontrado nesta escola.", 404);
}

$responsaveis = [];
foreach ($aluno['responsaveis'] as $id => $responsavel) {
    $responsaveis[] = [
        'id_responsavel' => $id,
        'nome' => $responsavel['nome_responsavel'],
        'parentesco' => $responsavel['parentesco'],
        'telefone' => $responsavel['telefone_responsavel'],
        'email' => $responsavel['email_responsavel'],
        'canal_preferido' => $responsavel['canal_preferido'],
        'whatsapp' => contatoDoCanal($responsavel, 'whatsapp') !== null,
        'email_disponivel' => contatoDoCanal($responsavel, 'email') !== null,
        'sugeridos' => canaisDoResponsavel($responsavel),
    ];
}
enviarSucesso(
    $responsaveis === [] ? "Este aluno ainda não tem responsável cadastrado." : "Contatos carregados.",
    [
        'aluno' => ['id_aluno' => $aluno['id'], 'nome' => $aluno['nome'], 'turma' => $aluno['turma']],
        'responsaveis' => $responsaveis,
        'provedores' => provedoresConfigurados(),
    ]
);
