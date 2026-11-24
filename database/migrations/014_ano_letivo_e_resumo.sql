-- Migração 014: encerrar o ano letivo e resumo periódico às famílias.
--
-- 1) Aluno que saiu da escola (concluiu ou foi transferido) deixa de
--    aparecer nas listas, no app de biometria e nos alertas, mas o
--    histórico dele fica guardado. tb_aluno ganha a situação e quando saiu;
--    quem já existe continua 'ativo'.
-- 2) Cada escola escolhe se as famílias recebem um resumo das entradas
--    atrasadas e saídas antecipadas: semanal (padrão), mensal ou nenhum.
--
-- Só acrescenta colunas com valor padrão: nenhum dado é apagado ou
-- alterado, e rodar de novo não muda nada. A versão anterior da API
-- continua funcionando com o banco já migrado (ela não lê as colunas
-- novas).

BEGIN;

ALTER TABLE tb_aluno ADD COLUMN IF NOT EXISTS situacao_aluno VARCHAR(12) NOT NULL DEFAULT 'ativo';
ALTER TABLE tb_aluno ADD COLUMN IF NOT EXISTS saiu_em TIMESTAMP;
ALTER TABLE tb_aluno DROP CONSTRAINT IF EXISTS ck_aluno_situacao;
ALTER TABLE tb_aluno ADD CONSTRAINT ck_aluno_situacao
    CHECK (situacao_aluno IN ('ativo', 'concluiu', 'transferido'));
CREATE INDEX IF NOT EXISTS ix_aluno_sala_ativo ON tb_aluno (id_sala) WHERE situacao_aluno = 'ativo';

ALTER TABLE tb_configuracao_escola ADD COLUMN IF NOT EXISTS resumo_periodico VARCHAR(10) NOT NULL DEFAULT 'semanal';
ALTER TABLE tb_configuracao_escola DROP CONSTRAINT IF EXISTS ck_config_resumo;
ALTER TABLE tb_configuracao_escola ADD CONSTRAINT ck_config_resumo
    CHECK (resumo_periodico IN ('desligado', 'semanal', 'mensal'));

COMMIT;
