-- Schema base já existente em produção (Supabase/PostgreSQL), reproduzido
-- aqui só para referência e para permitir montar um banco de testes do
-- zero localmente. NÃO é preciso rodar isso contra o banco de produção -
-- ele já tem essas tabelas (rodar não quebra: tudo é idempotente).
--
-- Conferido contra o banco de produção em 29/10/2026 (information_schema
-- e pg_constraint), não só pelo Schema Visualizer: as restrições CHECK e
-- UNIQUE abaixo existem lá e NÃO existiam nesta reprodução. Por isso os
-- testes passavam e a produção quebrava - ex.: o código gravava o status
-- 'pendente' em tb_notificacao e o banco só aceita 'Pendente', 'Enviado'
-- ou 'Erro'. O banco de testes do CI precisa recusar o mesmo que a
-- produção recusa.

CREATE TABLE IF NOT EXISTS tb_coordenacao (
    id_coordenacao SERIAL PRIMARY KEY,
    nome_coordenacao VARCHAR(150) NOT NULL,
    email_coordenacao VARCHAR(150) NOT NULL,
    senha_coordenacao VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS tb_sala (
    id_sala SERIAL PRIMARY KEY,
    nome_sala VARCHAR(50) NOT NULL,
    periodo_sala VARCHAR(20)
);

CREATE TABLE IF NOT EXISTS tb_aluno (
    id_aluno SERIAL PRIMARY KEY,
    nome_aluno VARCHAR(150) NOT NULL,
    id_sala INT NOT NULL REFERENCES tb_sala(id_sala),
    dta_nasc_aluno DATE NOT NULL,
    genero_aluno VARCHAR(20) NOT NULL
);

CREATE TABLE IF NOT EXISTS tb_falta (
    id_falta SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    dta_falta TIMESTAMP NOT NULL DEFAULT NOW(),
    motivo_falta VARCHAR(150),
    justificado_falta BOOLEAN NOT NULL DEFAULT FALSE,
    observacao_falta TEXT
);

CREATE TABLE IF NOT EXISTS tb_atraso (
    id_atraso SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    dta_atraso TIMESTAMP NOT NULL DEFAULT NOW(),
    motivo_atraso VARCHAR(150),
    justificado_atraso BOOLEAN NOT NULL DEFAULT FALSE,
    observacao_atraso TEXT
);

CREATE TABLE IF NOT EXISTS tb_responsavel (
    id_responsavel SERIAL PRIMARY KEY,
    id_aluno INT REFERENCES tb_aluno(id_aluno),
    nome_responsavel VARCHAR(150),
    cpf_responsavel VARCHAR(20),
    telefone_responsavel VARCHAR(20),
    email_responsavel VARCHAR(150),
    genero_responsavel VARCHAR(20)
);

CREATE TABLE IF NOT EXISTS tb_aluno_responsavel (
    id_aluno INT REFERENCES tb_aluno(id_aluno),
    id_responsavel INT REFERENCES tb_responsavel(id_responsavel),
    PRIMARY KEY (id_aluno, id_responsavel)
);

CREATE TABLE IF NOT EXISTS tb_notificacao (
    id_notificacao SERIAL PRIMARY KEY,
    id_aluno INT REFERENCES tb_aluno(id_aluno),
    id_responsavel INT REFERENCES tb_responsavel(id_responsavel),
    data_envio_notificacao TIMESTAMP,
    mensagem TEXT,
    status_notificacao VARCHAR(30)
);

-- Restrições que existem em produção. Cada uma num bloco que ignora "já
-- existe", para o arquivo poder rodar de novo sem erro (o CI roda todas
-- as migrações duas vezes para garantir isso).
DO $$ BEGIN
    ALTER TABLE tb_coordenacao ADD CONSTRAINT uq_email_coordenacao UNIQUE (email_coordenacao);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;

DO $$ BEGIN
    ALTER TABLE tb_aluno ADD CONSTRAINT chk_genero_aluno
        CHECK (genero_aluno IN ('Masculino', 'Feminino', 'Indefinido'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    ALTER TABLE tb_sala ADD CONSTRAINT chk_periodo_sala
        CHECK (periodo_sala IN ('Matutino', 'Vespertino', 'Noturno', 'Indefinido'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    ALTER TABLE tb_responsavel ADD CONSTRAINT chk_genero_responsavel
        CHECK (genero_responsavel IN ('Masculino', 'Feminino', 'Indefinido'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
    ALTER TABLE tb_responsavel ADD CONSTRAINT tb_responsavel_cpf_responsavel_key UNIQUE (cpf_responsavel);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;

DO $$ BEGIN
    ALTER TABLE tb_responsavel ADD CONSTRAINT tb_responsavel_telefone_responsavel_key UNIQUE (telefone_responsavel);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;

DO $$ BEGIN
    ALTER TABLE tb_responsavel ADD CONSTRAINT tb_responsavel_email_responsavel_key UNIQUE (email_responsavel);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;

DO $$ BEGIN
    ALTER TABLE tb_notificacao ADD CONSTRAINT chk_status_notificacao
        CHECK (status_notificacao IN ('Enviado', 'Erro', 'Pendente'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
