-- Recomendado, não obrigatório para a API funcionar: addResponsavel.php já
-- checa duplicidade de CPF na aplicação antes de inserir, mas isso sozinho
-- tem uma janela de corrida (duas requisições simultâneas podem passar na
-- checagem antes de qualquer uma inserir). Uma constraint UNIQUE fecha essa
-- janela de vez - mesmo padrão já usado para email_coordenacao.
--
-- Se a tabela já tiver CPFs duplicados de cadastros anteriores a esta
-- rodada, este ALTER vai falhar - rode primeiro uma consulta para achar e
-- consolidar os duplicados manualmente:
--
--   SELECT cpf_responsavel, COUNT(*), array_agg(id_responsavel)
--   FROM tb_responsavel
--   WHERE cpf_responsavel IS NOT NULL
--   GROUP BY cpf_responsavel
--   HAVING COUNT(*) > 1;

ALTER TABLE tb_responsavel ADD CONSTRAINT uq_cpf_responsavel UNIQUE (cpf_responsavel);
