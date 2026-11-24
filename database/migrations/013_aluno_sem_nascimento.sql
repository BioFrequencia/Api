-- Migração 013: aluno sem data de nascimento.
--
-- A importação de Registros (planilha de entradas atrasadas e saídas
-- antecipadas) passa a cadastrar o aluno que a escola ainda não tem só
-- com o nome e a turma - o que as planilhas de atrasos das escolas
-- costumam trazer. A data de nascimento fica para depois (a importação de
-- Turmas e alunos completa); o gênero entra como "Indefinido", que o banco
-- já aceitava.
--
-- Só relaxa uma restrição: nenhum dado é apagado ou alterado, e rodar de
-- novo não muda nada. A versão anterior da API continua funcionando com o
-- banco já migrado (ela sempre grava a data).

BEGIN;

ALTER TABLE tb_aluno ALTER COLUMN dta_nasc_aluno DROP NOT NULL;

COMMIT;
