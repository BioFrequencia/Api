-- Migração 006: app de biometria da coordenação.
--
--   (a) presença registrada pelo app (reconhecimento facial, digital ou
--       manual), no máximo uma por aluno por dia letivo;
--   (b) consentimento do responsável para o uso da biometria - dado
--       biométrico é dado pessoal sensível, e de criança ou adolescente
--       (LGPD, arts. 11 e 14);
--   (c) biometria facial com várias amostras por aluno, gravadas
--       cifradas pela API (AES-256-GCM, chave fora do banco);
--   (d) biometria digital, preparada para um leitor externo;
--   (e) registro de cada tentativa de identificação (auditoria).
--
-- "Cada coordenação é uma escola independente" não pede estrutura nova:
-- tb_escola (005) já separa os dados, e a API passa a criar uma escola
-- própria para cada conta de coordenação nova.
--
-- Aditiva e idempotente: nada é apagado nem renomeado, e rodar duas vezes
-- não quebra.

BEGIN;

-- ---------------------------------------------------------------- (a)
CREATE TABLE IF NOT EXISTS tb_presenca (
    id_presenca    SERIAL PRIMARY KEY,
    id_aluno       INT NOT NULL REFERENCES tb_aluno(id_aluno),
    -- Dia no calendário da escola (America/Sao_Paulo), calculado pela API:
    -- uma presença às 22h de Brasília já é o dia seguinte em UTC.
    dia_letivo     DATE NOT NULL,
    registrada_em  TIMESTAMP NOT NULL DEFAULT NOW(),
    metodo         VARCHAR(20) NOT NULL,
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    -- Distância do reconhecimento facial (quanto menor, mais parecido).
    distancia      NUMERIC(6,5),
    -- O que impede presença duplicada é o banco, não só a API: dois
    -- tablets reconhecendo o mesmo aluno ao mesmo tempo gravam uma linha.
    CONSTRAINT uq_presenca_aluno_dia UNIQUE (id_aluno, dia_letivo)
);

ALTER TABLE tb_presenca DROP CONSTRAINT IF EXISTS ck_presenca_metodo;
ALTER TABLE tb_presenca ADD  CONSTRAINT ck_presenca_metodo
    CHECK (metodo IN ('facial', 'digital', 'manual'));

CREATE INDEX IF NOT EXISTS ix_presenca_dia ON tb_presenca (dia_letivo);

-- ---------------------------------------------------------------- (b)
-- Sem consentimento registrado, a API não aceita cadastrar biometria.
-- Revogar apaga a biometria do aluno.
CREATE TABLE IF NOT EXISTS tb_consentimento_biometria (
    id_aluno         INT PRIMARY KEY REFERENCES tb_aluno(id_aluno),
    nome_responsavel VARCHAR(150) NOT NULL,
    id_coordenacao   INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    registrado_em    TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------- (c)
-- tb_biometria_facial (003) continua com um registro por aluno; a coluna
-- "embedding" passa a guardar todas as amostras, cifradas.
ALTER TABLE tb_biometria_facial ADD COLUMN IF NOT EXISTS amostras SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE tb_biometria_facial ADD COLUMN IF NOT EXISTS cifrado  BOOLEAN  NOT NULL DEFAULT FALSE;

-- ---------------------------------------------------------------- (d)
CREATE TABLE IF NOT EXISTS tb_biometria_digital (
    id_biometria_digital SERIAL PRIMARY KEY,
    id_aluno       INT NOT NULL REFERENCES tb_aluno(id_aluno),
    dedo           VARCHAR(30) NOT NULL,
    template       TEXT NOT NULL,         -- cifrado pela API
    formato        VARCHAR(60) NOT NULL,  -- formato do template (ISO 19794-2, ANSI 378, do SDK do leitor)
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    data_cadastro  TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_digital_aluno_dedo UNIQUE (id_aluno, dedo)
);

-- ---------------------------------------------------------------- (e)
-- Sem nenhum dado biométrico: só quem tentou, quando, o resultado e a
-- distância - para a coordenação conferir recusas e falsos positivos.
CREATE TABLE IF NOT EXISTS tb_identificacao_biometrica (
    id_identificacao SERIAL PRIMARY KEY,
    id_coordenacao   INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    metodo           VARCHAR(20) NOT NULL,
    reconhecido      BOOLEAN NOT NULL,
    id_aluno         INT REFERENCES tb_aluno(id_aluno),
    distancia        NUMERIC(6,5),
    criado_em        TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_identificacao_coordenacao
    ON tb_identificacao_biometrica (id_coordenacao, criado_em DESC);

-- Como as demais tabelas do Supabase: RLS ligado e nenhuma política, para
-- só a API (dona das tabelas) acessar - e não a API REST pública do
-- Supabase com a chave "anon".
ALTER TABLE tb_presenca                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_consentimento_biometria  ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_biometria_digital        ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_identificacao_biometrica ENABLE ROW LEVEL SECURITY;

COMMIT;
