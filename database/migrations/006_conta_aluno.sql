-- Migração 006: conta do aluno no app (e-mail e senha), no lugar do código
-- de vínculo.
--
-- Antes, a coordenação gerava no site um código de 8 caracteres que o
-- aluno digitava no app para vincular o celular. Agora o aluno cria a
-- própria conta no app - com o nome e a data de nascimento do cadastro
-- feito pela escola, mais e-mail e senha - e o celular é registrado a cada
-- login. O check-in continua igual: a biometria é confirmada no aparelho e
-- o envio vai assinado (HMAC) com o segredo do dispositivo.
--
-- Aditiva e idempotente: tb_codigo_vinculo continua no banco (só deixa de
-- ser usada) e nenhum dado é apagado.

BEGIN;

CREATE TABLE IF NOT EXISTS tb_conta_aluno (
    id_conta        SERIAL PRIMARY KEY,
    -- Uma conta por aluno: um segundo cadastro para o mesmo aluno recebe
    -- 409, e a coordenação pode remover a conta antiga pelo site.
    id_aluno        INT NOT NULL UNIQUE REFERENCES tb_aluno(id_aluno),
    email_conta     VARCHAR(150) NOT NULL,
    senha_conta     VARCHAR(255) NOT NULL,   -- password_hash(), nunca texto puro
    criado_em       TIMESTAMP NOT NULL DEFAULT NOW(),
    ultimo_login_em TIMESTAMP
);

-- E-mail único sem diferenciar maiúsculas: o teclado do celular coloca a
-- primeira letra em maiúscula.
CREATE UNIQUE INDEX IF NOT EXISTS ux_conta_aluno_email ON tb_conta_aluno (lower(email_conta));

-- Todas as tabelas do Supabase têm RLS ligado e nenhuma política: só a
-- API (dona das tabelas) lê e grava. Sem isto, a tabela nova ficaria
-- legível pela API REST pública do Supabase com a chave "anon" - e-mails
-- e hashes de senha dos alunos.
ALTER TABLE tb_conta_aluno ENABLE ROW LEVEL SECURITY;

COMMIT;
