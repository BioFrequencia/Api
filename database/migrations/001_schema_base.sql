-- Schema base já existente em produção (Supabase/PostgreSQL), reproduzido
-- aqui só para referência e para permitir montar um banco de testes do
-- zero localmente. NÃO rode isso contra o banco de produção - ele já tem
-- essas tabelas. Confirmado via Schema Visualizer do Supabase.

CREATE TABLE IF NOT EXISTS tb_coordenacao (
    id_coordenacao SERIAL PRIMARY KEY,
    nome_coordenacao VARCHAR(150) NOT NULL,
    email_coordenacao VARCHAR(150) NOT NULL,
    senha_coordenacao VARCHAR(255) NOT NULL
);

-- Pendência apontada na rodada anterior de auditoria: sem esta constraint,
-- a unicidade de email depende só da aplicação.
ALTER TABLE tb_coordenacao ADD CONSTRAINT uq_email_coordenacao UNIQUE (email_coordenacao);

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
