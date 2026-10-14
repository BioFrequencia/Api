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

-- ===================================================================
-- Migração 005, incorporada aqui para uma instalação nova já nascer
-- completa (escopo por escola, fila de ocorrências e níveis de alerta).
-- ===================================================================
-- Migração 005
--   (a) escopo por escola, para contas de escolas diferentes não verem
--       os alunos umas das outras;
--   (b) fila de ocorrências enviadas pelo app e revisadas pela coordenação;
--   (c) nível de alerta persistido, para o escalonamento até o contato
--       com o responsável.
--
-- Toda a migração é aditiva e idempotente: nenhuma coluna existente é
-- removida ou renomeada, nenhum dado é apagado, e rodar duas vezes não
-- quebra. Os clientes antigos continuam funcionando enquanto não forem
-- atualizados.

BEGIN;

-- ---------------------------------------------------------------- (a)
CREATE TABLE IF NOT EXISTS tb_escola (
    id_escola   SERIAL PRIMARY KEY,
    nome_escola VARCHAR(150) NOT NULL,
    criado_em   TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Escola de destino dos dados que já existem. Sem isso, acrescentar a
-- coluna deixaria todo o cadastro atual órfão e invisível.
INSERT INTO tb_escola (id_escola, nome_escola)
SELECT 1, 'E.E. Maria Nilde Mascellani'
WHERE NOT EXISTS (SELECT 1 FROM tb_escola WHERE id_escola = 1);

-- Inserir com id explícito NÃO avança a sequência do SERIAL: sem este
-- setval, o próximo INSERT sem id tentaria usar 1 de novo e quebraria com
-- "duplicate key value violates unique constraint" na hora de cadastrar
-- a segunda escola.
SELECT setval(pg_get_serial_sequence('tb_escola', 'id_escola'),
              GREATEST((SELECT MAX(id_escola) FROM tb_escola), 1));

ALTER TABLE tb_coordenacao ADD COLUMN IF NOT EXISTS id_escola INT REFERENCES tb_escola(id_escola);
ALTER TABLE tb_sala        ADD COLUMN IF NOT EXISTS id_escola INT REFERENCES tb_escola(id_escola);

UPDATE tb_coordenacao SET id_escola = 1 WHERE id_escola IS NULL;
UPDATE tb_sala        SET id_escola = 1 WHERE id_escola IS NULL;

-- Só agora, com tudo preenchido, a coluna pode exigir valor.
ALTER TABLE tb_coordenacao ALTER COLUMN id_escola SET NOT NULL;
ALTER TABLE tb_sala        ALTER COLUMN id_escola SET NOT NULL;
ALTER TABLE tb_coordenacao ALTER COLUMN id_escola SET DEFAULT 1;
ALTER TABLE tb_sala        ALTER COLUMN id_escola SET DEFAULT 1;

CREATE INDEX IF NOT EXISTS ix_sala_escola        ON tb_sala (id_escola);
CREATE INDEX IF NOT EXISTS ix_coordenacao_escola ON tb_coordenacao (id_escola);

-- O aluno pertence à escola pela turma (tb_aluno.id_sala). Não existe
-- coluna de escola em tb_aluno de propósito: duas fontes para o mesmo
-- fato divergem com o tempo, e mover um aluno de turma passaria a exigir
-- atualizar dois lugares.

-- ---------------------------------------------------------------- (b)
CREATE TABLE IF NOT EXISTS tb_ocorrencia_pendente (
    id_ocorrencia          SERIAL PRIMARY KEY,
    id_aluno               INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_dispositivo         INT REFERENCES tb_dispositivo_aluno(id_dispositivo),
    tipo                   VARCHAR(20)  NOT NULL,
    motivo                 VARCHAR(150),
    observacao             TEXT,
    responsavel_ciente     BOOLEAN NOT NULL DEFAULT FALSE,
    metodo_identificacao   VARCHAR(30)  NOT NULL DEFAULT 'biometria_dispositivo',
    status                 VARCHAR(20)  NOT NULL DEFAULT 'pendente',
    criado_em              TIMESTAMP NOT NULL DEFAULT NOW(),
    revisado_em            TIMESTAMP,
    id_coordenacao_revisao INT REFERENCES tb_coordenacao(id_coordenacao),
    observacao_revisao     TEXT,
    id_falta_criada        INT REFERENCES tb_falta(id_falta),
    id_atraso_criado       INT REFERENCES tb_atraso(id_atraso)
);

ALTER TABLE tb_ocorrencia_pendente DROP CONSTRAINT IF EXISTS ck_ocorrencia_tipo;
ALTER TABLE tb_ocorrencia_pendente ADD  CONSTRAINT ck_ocorrencia_tipo
    CHECK (tipo IN ('falta', 'atraso'));
ALTER TABLE tb_ocorrencia_pendente DROP CONSTRAINT IF EXISTS ck_ocorrencia_status;
ALTER TABLE tb_ocorrencia_pendente ADD  CONSTRAINT ck_ocorrencia_status
    CHECK (status IN ('pendente', 'aprovado', 'rejeitado'));

-- Um envio pendente por aluno/tipo/dia. O índice é parcial: assim que a
-- coordenação decide (aprovado/rejeitado), o registro sai da restrição e
-- o aluno pode enviar de novo no mesmo dia se precisar. Impede o
-- duplicado real (tocar o botão duas vezes) sem travar a correção.
CREATE UNIQUE INDEX IF NOT EXISTS ux_ocorrencia_pendente_dia
    ON tb_ocorrencia_pendente (id_aluno, tipo, (criado_em::date))
    WHERE status = 'pendente';

CREATE INDEX IF NOT EXISTS ix_ocorrencia_status ON tb_ocorrencia_pendente (status, criado_em DESC);

-- ---------------------------------------------------------------- (c)
CREATE TABLE IF NOT EXISTS tb_alerta_nivel (
    id_aluno          INT PRIMARY KEY REFERENCES tb_aluno(id_aluno),
    nivel             SMALLINT NOT NULL DEFAULT 0,
    motivo_nivel      VARCHAR(200),
    atualizado_em     TIMESTAMP NOT NULL DEFAULT NOW(),
    ultimo_contato_em TIMESTAMP,
    ultimo_canal      VARCHAR(30)
);

ALTER TABLE tb_alerta_nivel DROP CONSTRAINT IF EXISTS ck_alerta_nivel_faixa;
ALTER TABLE tb_alerta_nivel ADD  CONSTRAINT ck_alerta_nivel_faixa
    CHECK (nivel BETWEEN 0 AND 4);

-- Canal usado para falar com o responsável. tb_notificacao já existia
-- como fila manual; a coluna diz por onde a mensagem deve sair, para o
-- nível máximo poder disparar e-mail e WhatsApp.
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS canal        VARCHAR(20);
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS nivel_alerta SMALLINT;
UPDATE tb_notificacao SET canal = 'manual' WHERE canal IS NULL;

COMMIT;
