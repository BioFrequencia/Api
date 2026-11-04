-- Migração 010: senhas mais seguras e cargo na equipe da escola.
--
--   (a) tb_tentativa_login: os logins errados recentes, para bloquear por
--       um tempo quem tenta adivinhar senhas (helpers/tentativas.php). Só
--       hashes: nem o e-mail nem o IP ficam em texto;
--   (b) "Esqueci minha senha": o link de senha nova usa a mesma tabela dos
--       links de e-mail (tb_verificacao_email), com a finalidade
--       'redefinir_senha';
--   (c) tb_coordenacao.cargo: a função na escola (direção, vice-direção,
--       coordenação, secretaria, outro). É só descritivo - a permissão
--       continua em papel (gestor/coordenador), e quem aprova escolhe. As
--       contas que já existem ficam sem cargo até alguém informar.
--
-- Aditiva e idempotente: nada é apagado, e rodar de novo não muda nada.

BEGIN;

-- ---------------------------------------------------------------- (a)
CREATE TABLE IF NOT EXISTS tb_tentativa_login (
    id_tentativa BIGSERIAL PRIMARY KEY,
    chave_email  CHAR(64) NOT NULL,
    chave_rede   CHAR(64) NOT NULL,
    criado_em    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_tentativa_login_email ON tb_tentativa_login (chave_email, criado_em);
CREATE INDEX IF NOT EXISTS idx_tentativa_login_rede ON tb_tentativa_login (chave_rede, criado_em);

-- ---------------------------------------------------------------- (b)
ALTER TABLE tb_verificacao_email DROP CONSTRAINT IF EXISTS ck_verificacao_email_finalidade;
ALTER TABLE tb_verificacao_email ADD CONSTRAINT ck_verificacao_email_finalidade
    CHECK (finalidade IN ('cadastro', 'troca_email', 'redefinir_senha'));

-- ---------------------------------------------------------------- (c)
ALTER TABLE tb_coordenacao ADD COLUMN IF NOT EXISTS cargo VARCHAR(20);
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_coordenacao_cargo') THEN
        ALTER TABLE tb_coordenacao ADD CONSTRAINT ck_coordenacao_cargo
            CHECK (cargo IS NULL OR cargo IN ('direcao', 'vice_direcao', 'coordenacao', 'secretaria', 'outro'));
    END IF;
END $$;

-- Como as demais tabelas do Supabase: RLS ligado e nenhuma política, para
-- só a API (dona das tabelas) acessar.
ALTER TABLE tb_tentativa_login ENABLE ROW LEVEL SECURITY;

COMMIT;
