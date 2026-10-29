-- Recomendado, não obrigatório para a API funcionar: addResponsavel.php já
-- checa duplicidade de CPF na aplicação antes de inserir, mas isso sozinho
-- tem uma janela de corrida (duas requisições simultâneas podem passar na
-- checagem antes de qualquer uma inserir). Uma constraint UNIQUE fecha essa
-- janela de vez - mesmo padrão já usado para email_coordenacao.
--
-- O banco de produção JÁ tem uma constraint equivalente
-- (tb_responsavel_cpf_responsavel_key, ver 001_schema_base.sql); rodar
-- isto lá cria uma segunda, redundante mas inofensiva.
--
-- Se a tabela já tiver CPFs duplicados de cadastros anteriores, este
-- ALTER vai falhar - rode primeiro uma consulta para achar e consolidar
-- os duplicados manualmente:
--
--   SELECT cpf_responsavel, COUNT(*), array_agg(id_responsavel)
--   FROM tb_responsavel
--   WHERE cpf_responsavel IS NOT NULL
--   GROUP BY cpf_responsavel
--   HAVING COUNT(*) > 1;

DO $$ BEGIN
    ALTER TABLE tb_responsavel ADD CONSTRAINT uq_cpf_responsavel UNIQUE (cpf_responsavel);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;
