<?php
/**
 * Importação de turmas e alunos: o que vem do banco e o que vai para ele.
 * A prévia (montarPreviaDaImportacao, helpers/importacao.php) precisa
 * saber o que a escola já tem; a confirmação grava as linhas novas numa
 * transação só - ou entra tudo, ou nada.
 */

require_once __DIR__ . '/importacao.php';

/** "?, ?, ?" para uma lista de valores (a lista nunca é vazia aqui). */
function marcadores(array $valores): string {
    return implode(', ', array_fill(0, count($valores), '?'));
}

/**
 * O que a escola já tem e o que o banco já usa, para a prévia: as turmas
 * e os alunos da escola, e os responsáveis, telefones e e-mails dos CPFs
 * e contatos que aparecem no arquivo (telefone e e-mail são únicos no
 * banco inteiro, por isso a busca não se limita à escola).
 */
function dadosDaEscolaParaImportacao(PDO $conexao, int $idEscola, array $abas): array {
    $escola = ['turmas' => [], 'alunos' => [], 'responsaveis' => [], 'telefones' => [], 'emails' => []];

    $turmas = $conexao->prepare("SELECT id_sala, nome_sala FROM tb_sala WHERE id_escola = ?");
    $turmas->execute([$idEscola]);
    foreach ($turmas->fetchAll(PDO::FETCH_ASSOC) as $turma) {
        $escola['turmas'][chaveDeNome($turma['nome_sala'])] = ['id_sala' => (int) $turma['id_sala'], 'nome_sala' => $turma['nome_sala']];
    }

    $alunos = $conexao->prepare("
        SELECT a.nome_aluno, a.dta_nasc_aluno FROM tb_aluno a JOIN tb_sala s ON s.id_sala = a.id_sala WHERE s.id_escola = ?
    ");
    $alunos->execute([$idEscola]);
    foreach ($alunos->fetchAll(PDO::FETCH_ASSOC) as $aluno) {
        $escola['alunos'][chaveDeNome($aluno['nome_aluno']) . '|' . $aluno['dta_nasc_aluno']] = true;
    }

    // CPFs, telefones e e-mails do arquivo, já arrumados como na conferência.
    $cpfs = [];
    $telefones = [];
    $emails = [];
    foreach ($abas['alunos'] ?? [] as [, $valores]) {
        $responsavel = conferirLinhaDeAluno($valores)['responsavel'];
        if ($responsavel === null) continue;
        if (cpfValido($responsavel['cpf'])) $cpfs[$responsavel['cpf']] = true;
        if (telefoneValido($responsavel['telefone'])) $telefones[$responsavel['telefone']] = true;
        if ($responsavel['email'] !== null) $emails[$responsavel['email']] = true;
    }

    if ($cpfs !== []) {
        $lista = array_keys($cpfs);
        $busca = $conexao->prepare("
            SELECT r.id_responsavel, r.nome_responsavel, r.cpf_responsavel, r.telefone_responsavel, r.email_responsavel,
                   (SELECT COUNT(*) FROM tb_aluno_responsavel ar WHERE ar.id_responsavel = r.id_responsavel) AS vinculos,
                   EXISTS (
                       SELECT 1 FROM tb_aluno_responsavel ar
                       JOIN tb_aluno a ON a.id_aluno = ar.id_aluno
                       JOIN tb_sala s ON s.id_sala = a.id_sala
                       WHERE ar.id_responsavel = r.id_responsavel AND s.id_escola = ?
                   ) AS na_escola
            FROM tb_responsavel r WHERE r.cpf_responsavel IN (" . marcadores($lista) . ")
        ");
        $busca->execute(array_merge([$idEscola], $lista));
        foreach ($busca->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $escola['responsaveis'][$r['cpf_responsavel']] = [
                'id_responsavel' => (int) $r['id_responsavel'],
                'nome' => $r['nome_responsavel'],
                'telefone' => $r['telefone_responsavel'],
                'email' => $r['email_responsavel'],
                'vinculos' => (int) $r['vinculos'],
                'na_escola' => in_array($r['na_escola'], [true, 't', 'true', 1, '1'], true),
            ];
        }
    }
    if ($telefones !== []) {
        $lista = array_keys($telefones);
        $busca = $conexao->prepare("SELECT telefone_responsavel, cpf_responsavel FROM tb_responsavel WHERE telefone_responsavel IN (" . marcadores($lista) . ")");
        $busca->execute($lista);
        foreach ($busca->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $escola['telefones'][$r['telefone_responsavel']] = $r['cpf_responsavel'];
        }
    }
    if ($emails !== []) {
        $lista = array_keys($emails);
        $busca = $conexao->prepare("SELECT lower(email_responsavel) AS email, cpf_responsavel FROM tb_responsavel WHERE lower(email_responsavel) IN (" . marcadores($lista) . ")");
        $busca->execute($lista);
        foreach ($busca->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $escola['emails'][$r['email']] = $r['cpf_responsavel'];
        }
    }
    return $escola;
}

/**
 * Grava as linhas novas da prévia: as turmas, depois os alunos (na turma
 * da escola ou na turma nova do arquivo) e os responsáveis de cada um.
 * Quem chama abre a transação (e trava a escola) antes de montar a
 * prévia; aqui só se grava. Devolve o que foi criado.
 */
function gravarImportacao(PDO $conexao, int $idEscola, array $previa, array $turmasDaEscola): array {
    $resultado = ['turmas_criadas' => 0, 'alunos_criados' => 0, 'responsaveis_criados' => 0, 'vinculos_criados' => 0];
    $idDaTurma = [];
    foreach ($turmasDaEscola as $chave => $turma) {
        $idDaTurma[$chave] = $turma['id_sala'];
    }

    $novaTurma = $conexao->prepare("
        INSERT INTO tb_sala (nome_sala, periodo_sala, id_escola, horario_inicio, horario_fim) VALUES (?, ?, ?, ?, ?) RETURNING id_sala
    ");
    foreach ($previa['turmas'] as $turma) {
        if ($turma['situacao'] !== 'nova') continue;
        $novaTurma->execute([$turma['nome'], $turma['periodo'], $idEscola, $turma['inicio'], $turma['fim']]);
        $idDaTurma[chaveDeNome($turma['nome'])] = (int) $novaTurma->fetchColumn();
        $resultado['turmas_criadas']++;
    }

    $novoAluno = $conexao->prepare("
        INSERT INTO tb_aluno (nome_aluno, id_sala, dta_nasc_aluno, genero_aluno) VALUES (?, ?, ?, ?) RETURNING id_aluno
    ");
    $novoResponsavel = $conexao->prepare("
        INSERT INTO tb_responsavel (id_aluno, nome_responsavel, cpf_responsavel, telefone_responsavel, email_responsavel, genero_responsavel, canal_preferido)
        VALUES (?, ?, ?, ?, ?, 'Indefinido', ?) RETURNING id_responsavel
    ");
    // CPF que ficou sem nenhum vínculo: reaproveitado com os dados novos (como no cadastro manual).
    $reaproveitaResponsavel = $conexao->prepare("
        UPDATE tb_responsavel SET id_aluno = ?, nome_responsavel = ?, telefone_responsavel = ?, email_responsavel = ?, canal_preferido = ?
        WHERE id_responsavel = ?
    ");
    $vincula = $conexao->prepare("INSERT INTO tb_aluno_responsavel (id_aluno, id_responsavel, parentesco) VALUES (?, ?, ?)");
    $responsavelDoCpf = [];
    foreach ($previa['alunos'] as $aluno) {
        if ($aluno['situacao'] !== 'novo') continue;
        $novoAluno->execute([$aluno['nome'], $idDaTurma[chaveDeNome($aluno['turma'])], $aluno['nascimento'], $aluno['genero']]);
        $idAluno = (int) $novoAluno->fetchColumn();
        $resultado['alunos_criados']++;

        $responsavel = $aluno['responsavel'];
        if ($responsavel === null) continue;
        $cpf = $responsavel['cpf'];
        switch ($responsavel['situacao']) {
            case 'novo':
                $novoResponsavel->bindValue(1, $idAluno);
                $novoResponsavel->bindValue(2, $responsavel['nome']);
                $novoResponsavel->bindValue(3, $cpf);
                $novoResponsavel->bindValue(4, $responsavel['telefone']);
                $novoResponsavel->bindValue(5, $responsavel['email'], $responsavel['email'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $novoResponsavel->bindValue(6, $responsavel['canal']);
                $novoResponsavel->execute();
                $responsavelDoCpf[$cpf] = (int) $novoResponsavel->fetchColumn();
                $resultado['responsaveis_criados']++;
                break;
            case 'reaproveitar':
                $reaproveitaResponsavel->execute([
                    $idAluno, $responsavel['nome'], $responsavel['telefone'], $responsavel['email'], $responsavel['canal'], $responsavel['id_responsavel'],
                ]);
                $responsavelDoCpf[$cpf] = $responsavel['id_responsavel'];
                $resultado['responsaveis_criados']++;
                break;
            case 'vincular':
                $responsavelDoCpf[$cpf] = $responsavel['id_responsavel'];
                break;
            case 'mesmo':
                // O da primeira linha com este CPF (criado ou vinculado acima).
                break;
        }
        $vincula->execute([$idAluno, $responsavelDoCpf[$cpf], $responsavel['parentesco']]);
        $resultado['vinculos_criados']++;
    }
    return $resultado;
}
