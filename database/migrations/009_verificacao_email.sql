-- Migração 009: cadastro sem código de acesso.
--
-- O código de acesso (CADASTRO_CODIGO_ACESSO) saiu: qualquer pessoa pode
-- pedir uma conta, e quem libera o acesso é a equipe do sistema (ou a
-- gestão da escola, quando ela já usa o BioFrequência). Antes da
-- aprovação, a pessoa confirma o e-mail por um link:
--
--   (a) tb_coordenacao.email_verificado_em - quando o e-mail foi
--       confirmado. As contas que já existiam entraram pelo código de
--       acesso e contam como confirmadas;
--   (b) os links de confirmação (cadastro e troca do e-mail de login). Só
--       o hash do código fica no banco: o código vai apenas no e-mail.
--
-- Aditiva e idempotente: nada é apagado, e rodar de novo não confirma o
-- e-mail de quem se cadastrou depois.

BEGIN;

-- ---------------------------------------------------------------- (a)
DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'tb_coordenacao' AND column_name = 'email_verificado_em'
    ) THEN
        ALTER TABLE tb_coordenacao ADD COLUMN email_verificado_em TIMESTAMP;
        UPDATE tb_coordenacao SET email_verificado_em = COALESCE(criado_em, NOW());
    END IF;
END $$;

-- ---------------------------------------------------------------- (b)
CREATE TABLE IF NOT EXISTS tb_verificacao_email (
    id_verificacao  SERIAL PRIMARY KEY,
    id_coordenacao  INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    -- O endereço que o link confirma: o do cadastro ou o novo, na troca.
    email           VARCHAR(150) NOT NULL,
    finalidade      VARCHAR(20) NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    criado_em       TIMESTAMP NOT NULL DEFAULT NOW(),
    expira_em       TIMESTAMP NOT NULL,
    usado_em        TIMESTAMP,
    CONSTRAINT uq_verificacao_email_token UNIQUE (token_hash),
    CONSTRAINT ck_verificacao_email_finalidade CHECK (finalidade IN ('cadastro', 'troca_email'))
);
CREATE INDEX IF NOT EXISTS idx_verificacao_email_conta ON tb_verificacao_email (id_coordenacao, criado_em DESC);

-- Como as demais tabelas do Supabase: RLS ligado e nenhuma política, para
-- só a API (dona das tabelas) acessar.
ALTER TABLE tb_verificacao_email ENABLE ROW LEVEL SECURITY;

COMMIT;
