-- BioFrequência — migração consolidada (001 + 002 + 003)
--
-- Rode isto no SQL Editor do Supabase (ou no seu Postgres) contra o
-- banco de PRODUÇÃO. É seguro rodar mesmo que algumas tabelas já
-- existam: todo CREATE TABLE usa IF NOT EXISTS e os ADD CONSTRAINT
-- estão em blocos que ignoram "já existe" - nada aqui apaga ou altera
-- dado que já está gravado.
--
-- Por que rodar isto: dashboard, notificações e check-ins do site (e as
-- mesmas telas no desktop) dependem de tabelas que foram criadas só
-- durante este projeto (tb_acompanhamento, tb_alerta_encerrado,
-- tb_biometria_facial, tb_verificacao_facial, tb_codigo_vinculo,
-- tb_dispositivo_aluno, tb_checkin_biometrico) e nunca foram aplicadas
-- no seu banco real - só no banco de teste usado para desenvolver.
-- Sem elas, qualquer endpoint que precise dessas tabelas responde
-- "Erro no servidor" (500).
--
-- tb_coordenacao, tb_sala, tb_aluno, tb_falta, tb_atraso, tb_responsavel,
-- tb_aluno_responsavel e tb_notificacao já deveriam existir no seu banco
-- (schema original) - os CREATE TABLE delas aqui são só uma rede de
-- segurança (IF NOT EXISTS) caso alguma esteja faltando também.

CREATE TABLE IF NOT EXISTS tb_coordenacao (
    id_coordenacao SERIAL PRIMARY KEY,
    nome_coordenacao VARCHAR(150) NOT NULL,
    email_coordenacao VARCHAR(150) NOT NULL,
    senha_coordenacao VARCHAR(255) NOT NULL
);

DO $$ BEGIN
    ALTER TABLE tb_coordenacao ADD CONSTRAINT uq_email_coordenacao UNIQUE (email_coordenacao);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL;
END $$;

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

-- ---- A partir daqui: tabelas novas (Acompanhamento/Alertas/Biometria) ----

CREATE TABLE IF NOT EXISTS tb_acompanhamento (
    id_acompanhamento SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    canal VARCHAR(50) NOT NULL,
    observacao TEXT,
    data_registro TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tb_alerta_encerrado (
    id_aluno INT PRIMARY KEY REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    encerrado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tb_biometria_facial (
    id_biometria_facial SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL UNIQUE REFERENCES tb_aluno(id_aluno),
    embedding TEXT NOT NULL,
    modelo VARCHAR(100) NOT NULL,
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    data_cadastro TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tb_verificacao_facial (
    id_verificacao SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    similaridade NUMERIC(6,5) NOT NULL,
    correspondeu BOOLEAN NOT NULL,
    data_verificacao TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tb_codigo_vinculo (
    id_codigo SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    codigo VARCHAR(12) NOT NULL UNIQUE,
    usado BOOLEAN NOT NULL DEFAULT FALSE,
    expira_em TIMESTAMP NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tb_dispositivo_aluno (
    id_dispositivo SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    device_uuid VARCHAR(100) NOT NULL UNIQUE,
    device_secret VARCHAR(64) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    revogado_em TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tb_checkin_biometrico (
    id_checkin SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_dispositivo INT NOT NULL REFERENCES tb_dispositivo_aluno(id_dispositivo),
    tipo VARCHAR(20) NOT NULL,
    metodo VARCHAR(30) NOT NULL DEFAULT 'digital_dispositivo',
    data_checkin TIMESTAMP NOT NULL DEFAULT NOW(),
    confirmado_em TIMESTAMP,
    id_coordenacao_confirmacao INT REFERENCES tb_coordenacao(id_coordenacao)
);

-- ---- Opcional (004), rode depois de conferir que não há CPF duplicado ----
-- SELECT cpf_responsavel, COUNT(*), array_agg(id_responsavel)
-- FROM tb_responsavel WHERE cpf_responsavel IS NOT NULL
-- GROUP BY cpf_responsavel HAVING COUNT(*) > 1;
--
-- DO $$ BEGIN
--     ALTER TABLE tb_responsavel ADD CONSTRAINT uq_cpf_responsavel UNIQUE (cpf_responsavel);
-- EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL;
-- END $$;
