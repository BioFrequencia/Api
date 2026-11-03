-- Migração 008: o TCC registra ENTRADAS ATRASADAS e SAÍDAS ANTECIPADAS
-- (as faltas já ficam na Sala do Futuro), em escolas de verdade.
--
--   (a) catálogo das escolas estaduais de São Paulo (dados abertos da
--       SEDUC, importado por database/importarEscolas.php) e o vínculo da
--       coordenação com a escola: papel (gestor/coordenador) e situação
--       da conta (a gestão aprova quem entra na escola);
--   (b) saída antecipada, ao lado da entrada atrasada (tb_atraso), e de
--       onde veio cada entrada atrasada (lançada ou detectada pela
--       presença do app);
--   (c) horário de cada turma e o período Integral;
--   (d) configuração da escola (tolerância e avisos aos responsáveis) e
--       calendário próprio (dias sem aula e dias letivos extras);
--   (e) responsáveis: canal preferido e parentesco com cada aluno;
--   (f) data de cadastro do aluno, para as porcentagens contarem só os
--       dias de aula desde que ele entrou;
--   (g) controle dos avisos automáticos por limite (no máximo um por
--       período configurado).
--
-- Aditiva e idempotente: nada é apagado nem renomeado (tb_falta continua
-- com os registros antigos), e rodar duas vezes não quebra.

BEGIN;

-- ---------------------------------------------------------------- (a)
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS codigo_cie         INT;
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS codigo_inep        VARCHAR(8);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS nome_oficial       VARCHAR(120);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS rede               VARCHAR(30);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS diretoria_ensino   VARCHAR(60);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS municipio          VARCHAR(60);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS codigo_ibge        INT;
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS distrito           VARCHAR(60);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS tipo_escola        SMALLINT;
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS endereco           VARCHAR(120);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS numero             VARCHAR(12);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS complemento        VARCHAR(60);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS bairro             VARCHAR(60);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS cep                VARCHAR(8);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS zona               SMALLINT;
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS latitude           NUMERIC(9,6);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS longitude          NUMERIC(9,6);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS codigo_vinculadora INT;
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS atualizado_em      TIMESTAMP;

-- Escola cadastrada à mão (antes do catálogo) fica com o código vazio:
-- o índice único aceita vários NULL.
CREATE UNIQUE INDEX IF NOT EXISTS ux_escola_codigo_cie ON tb_escola (codigo_cie);
CREATE INDEX IF NOT EXISTS ix_escola_municipio ON tb_escola (municipio);

-- Qual arquivo da SEDUC já foi importado: o importador só roda de novo
-- quando a versão muda (ex.: a planilha do semestre seguinte).
CREATE TABLE IF NOT EXISTS tb_catalogo_escolas (
    versao       VARCHAR(30) PRIMARY KEY,
    total        INT NOT NULL,
    importado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Papel na escola. As contas que já existiam viram gestoras (todas são da
-- equipe que testou o sistema até aqui): ninguém perde o acesso às
-- configurações da própria escola. O bloco só roda quando a coluna ainda
-- não existe - repetir a migração não promove quem entrou depois.
DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'tb_coordenacao' AND column_name = 'papel'
    ) THEN
        ALTER TABLE tb_coordenacao ADD COLUMN papel VARCHAR(20) NOT NULL DEFAULT 'coordenador';
        UPDATE tb_coordenacao SET papel = 'gestor';
    END IF;
END $$;

ALTER TABLE tb_coordenacao ADD COLUMN IF NOT EXISTS situacao     VARCHAR(20) NOT NULL DEFAULT 'ativo';
ALTER TABLE tb_coordenacao ADD COLUMN IF NOT EXISTS criado_em    TIMESTAMP DEFAULT NOW();
ALTER TABLE tb_coordenacao ADD COLUMN IF NOT EXISTS aprovado_em  TIMESTAMP;
ALTER TABLE tb_coordenacao ADD COLUMN IF NOT EXISTS aprovado_por INT REFERENCES tb_coordenacao(id_coordenacao);

ALTER TABLE tb_coordenacao DROP CONSTRAINT IF EXISTS ck_coordenacao_papel;
ALTER TABLE tb_coordenacao ADD  CONSTRAINT ck_coordenacao_papel
    CHECK (papel IN ('gestor', 'coordenador'));
ALTER TABLE tb_coordenacao DROP CONSTRAINT IF EXISTS ck_coordenacao_situacao;
ALTER TABLE tb_coordenacao ADD  CONSTRAINT ck_coordenacao_situacao
    CHECK (situacao IN ('pendente', 'ativo', 'recusado', 'desativado'));

-- ---------------------------------------------------------------- (b)
CREATE TABLE IF NOT EXISTS tb_saida_antecipada (
    id_saida          SERIAL PRIMARY KEY,
    id_aluno          INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao    INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    dta_saida         TIMESTAMP NOT NULL DEFAULT NOW(),
    motivo_saida      VARCHAR(150),
    justificado_saida BOOLEAN NOT NULL DEFAULT FALSE,
    observacao_saida  TEXT,
    -- Quem buscou o aluno (a escola só libera menor com um adulto).
    retirado_por      VARCHAR(150)
);

CREATE INDEX IF NOT EXISTS ix_saida_aluno_data  ON tb_saida_antecipada (id_aluno, dta_saida);
CREATE INDEX IF NOT EXISTS ix_atraso_aluno_data ON tb_atraso (id_aluno, dta_atraso);

-- 'manual': lançada pela coordenação; 'presenca': o app registrou a
-- presença depois da tolerância; 'ocorrencia': envio do celular do aluno
-- aprovado (fluxo anterior).
ALTER TABLE tb_atraso ADD COLUMN IF NOT EXISTS origem VARCHAR(20) NOT NULL DEFAULT 'manual';
ALTER TABLE tb_atraso DROP CONSTRAINT IF EXISTS ck_atraso_origem;
ALTER TABLE tb_atraso ADD  CONSTRAINT ck_atraso_origem
    CHECK (origem IN ('manual', 'presenca', 'ocorrencia'));

-- ---------------------------------------------------------------- (c)
-- Sem horário gravado vale o padrão do período (helpers/calendario.php).
ALTER TABLE tb_sala ADD COLUMN IF NOT EXISTS horario_inicio TIME;
ALTER TABLE tb_sala ADD COLUMN IF NOT EXISTS horario_fim    TIME;

-- Mesma restrição de produção (001), agora com o Integral (PEI).
ALTER TABLE tb_sala DROP CONSTRAINT IF EXISTS chk_periodo_sala;
ALTER TABLE tb_sala ADD  CONSTRAINT chk_periodo_sala
    CHECK (periodo_sala IN ('Matutino', 'Vespertino', 'Noturno', 'Integral', 'Indefinido'));
ALTER TABLE tb_sala DROP CONSTRAINT IF EXISTS ck_sala_horario;
ALTER TABLE tb_sala ADD  CONSTRAINT ck_sala_horario
    CHECK (horario_inicio IS NULL OR horario_fim IS NULL OR horario_fim > horario_inicio);

-- ---------------------------------------------------------------- (d)
-- Sem linha aqui, a escola usa os valores padrão abaixo.
CREATE TABLE IF NOT EXISTS tb_configuracao_escola (
    id_escola                 INT PRIMARY KEY REFERENCES tb_escola(id_escola),
    tolerancia_minutos        SMALLINT NOT NULL DEFAULT 10,
    limite_alerta_percentual  SMALLINT NOT NULL DEFAULT 20,
    minimo_ocorrencias_alerta SMALLINT NOT NULL DEFAULT 3,
    janela_alerta_dias        SMALLINT NOT NULL DEFAULT 30,
    intervalo_aviso_dias      SMALLINT NOT NULL DEFAULT 7,
    aviso_automatico          BOOLEAN  NOT NULL DEFAULT TRUE,
    aviso_cada_registro       BOOLEAN  NOT NULL DEFAULT FALSE,
    atraso_pela_presenca      BOOLEAN  NOT NULL DEFAULT TRUE,
    atualizado_em             TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_por            INT REFERENCES tb_coordenacao(id_coordenacao),
    CONSTRAINT ck_config_tolerancia CHECK (tolerancia_minutos BETWEEN 0 AND 60),
    CONSTRAINT ck_config_limite     CHECK (limite_alerta_percentual BETWEEN 1 AND 100),
    CONSTRAINT ck_config_minimo     CHECK (minimo_ocorrencias_alerta BETWEEN 1 AND 30),
    CONSTRAINT ck_config_janela     CHECK (janela_alerta_dias BETWEEN 7 AND 120),
    CONSTRAINT ck_config_intervalo  CHECK (intervalo_aviso_dias BETWEEN 1 AND 60)
);

-- Feriados nacionais e o de São Paulo são calculados no código. Aqui
-- ficam os dias próprios da escola: recesso, férias, feriado municipal
-- ('sem_aula') e sábados letivos ou reposições ('aula_extra').
CREATE TABLE IF NOT EXISTS tb_calendario_escola (
    id_calendario  SERIAL PRIMARY KEY,
    id_escola      INT NOT NULL REFERENCES tb_escola(id_escola),
    data_inicio    DATE NOT NULL,
    data_fim       DATE NOT NULL,
    tipo           VARCHAR(20) NOT NULL,
    descricao      VARCHAR(100) NOT NULL,
    id_coordenacao INT REFERENCES tb_coordenacao(id_coordenacao),
    criado_em      TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT ck_calendario_tipo    CHECK (tipo IN ('sem_aula', 'aula_extra')),
    CONSTRAINT ck_calendario_periodo CHECK (data_fim >= data_inicio)
);

CREATE INDEX IF NOT EXISTS ix_calendario_escola ON tb_calendario_escola (id_escola, data_inicio);

-- ---------------------------------------------------------------- (e)
ALTER TABLE tb_responsavel ADD COLUMN IF NOT EXISTS canal_preferido VARCHAR(20) NOT NULL DEFAULT 'whatsapp';
ALTER TABLE tb_responsavel DROP CONSTRAINT IF EXISTS ck_responsavel_canal;
ALTER TABLE tb_responsavel ADD  CONSTRAINT ck_responsavel_canal
    CHECK (canal_preferido IN ('whatsapp', 'email', 'ambos'));

-- O parentesco é do vínculo, não da pessoa: a mesma avó é "avó" de um
-- neto e "responsável legal" de outro.
ALTER TABLE tb_aluno_responsavel ADD COLUMN IF NOT EXISTS parentesco VARCHAR(30);

-- ---------------------------------------------------------------- (f)
-- Alunos que já existiam ficam sem data (contam desde sempre).
ALTER TABLE tb_aluno ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP;
ALTER TABLE tb_aluno ALTER COLUMN criado_em SET DEFAULT NOW();

-- ---------------------------------------------------------------- (g)
-- A trava do aviso por limite: um registro por aluno e tipo, com a data
-- do último aviso. O envio só sai se o anterior for mais antigo que o
-- intervalo da escola.
CREATE TABLE IF NOT EXISTS tb_aviso_limite (
    id_aluno    INT NOT NULL REFERENCES tb_aluno(id_aluno),
    tipo        VARCHAR(20) NOT NULL,
    enviado_em  TIMESTAMP NOT NULL DEFAULT NOW(),
    taxa        NUMERIC(5,1),
    ocorrencias SMALLINT,
    PRIMARY KEY (id_aluno, tipo),
    CONSTRAINT ck_aviso_limite_tipo CHECK (tipo IN ('atrasos', 'saidas'))
);

-- Por que cada mensagem foi criada: aviso de um registro, de limite
-- atingido, ou do nível máximo (fluxo anterior).
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS tipo_aviso VARCHAR(30);

-- Como as demais tabelas do Supabase: RLS ligado e nenhuma política, para
-- só a API (dona das tabelas) acessar.
ALTER TABLE tb_catalogo_escolas    ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_saida_antecipada    ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_configuracao_escola ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_calendario_escola   ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_aviso_limite        ENABLE ROW LEVEL SECURITY;

COMMIT;
