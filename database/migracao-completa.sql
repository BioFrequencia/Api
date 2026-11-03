-- BioFrequência — migração consolidada (001 + 002 + 003 + 005 + 006 + 007 + 008 + 009)
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

-- Como as tabelas que já existem no Supabase: RLS ligado e nenhuma
-- política, para só a API (dona das tabelas) acessar. Sem isto, as
-- tabelas novas ficariam legíveis pela API REST pública do Supabase com a
-- chave "anon" (o Supabase dá SELECT ao papel anon em tabela nova).
ALTER TABLE tb_escola              ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_ocorrencia_pendente ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_alerta_nivel        ENABLE ROW LEVEL SECURITY;

COMMIT;

-- ===================================================================
-- Migração 006, incorporada aqui pelo mesmo motivo (presença e biometria
-- do app da coordenação).
-- ===================================================================
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

-- ===================================================================
-- Migração 007, incorporada aqui pelo mesmo motivo (sessões e
-- dispositivos conectados, preferências da conta e chamados de suporte).
-- ===================================================================
-- Migração 007: a conta da coordenação no site.
--
--   (a) sessões de login: cada login (site ou app) vira uma linha - um
--       "dispositivo conectado" que a coordenação vê em "Minha conta" e
--       pode desconectar. O id da sessão vai dentro do token, e a API
--       recusa token de sessão encerrada. "Lembrar de mim" no site deixa
--       a sessão valer 30 dias em vez de 2 horas;
--   (b) preferências da conta (tema e sons), guardadas no banco para
--       valerem em qualquer computador;
--   (c) chamados de suporte abertos pela coordenação.
--
-- Nenhum dado pessoal novo além do necessário: a sessão guarda só uma
-- descrição do aparelho ("Chrome no Windows"), sem IP.
--
-- Aditiva e idempotente: nada é apagado nem renomeado, e rodar duas vezes
-- não quebra.

BEGIN;

-- ---------------------------------------------------------------- (a)
CREATE TABLE IF NOT EXISTS tb_sessao (
    -- 128 bits aleatórios em hexadecimal, gerados pela API (vai no token
    -- como "sid"; sozinho não serve para entrar - o token é assinado).
    id_sessao       VARCHAR(32) PRIMARY KEY,
    id_coordenacao  INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    cliente         VARCHAR(10) NOT NULL CHECK (cliente IN ('site', 'app')),
    dispositivo     VARCHAR(120) NOT NULL,
    lembrar         BOOLEAN NOT NULL DEFAULT FALSE,
    criada_em       TIMESTAMP NOT NULL DEFAULT NOW(),
    ultimo_acesso   TIMESTAMP NOT NULL DEFAULT NOW(),
    expira_em       TIMESTAMP NOT NULL,
    encerrada_em    TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_sessao_coordenacao
    ON tb_sessao (id_coordenacao, ultimo_acesso DESC);

-- ---------------------------------------------------------------- (b)
CREATE TABLE IF NOT EXISTS tb_preferencia_coordenacao (
    id_coordenacao   INT PRIMARY KEY REFERENCES tb_coordenacao(id_coordenacao),
    tema             VARCHAR(10) NOT NULL DEFAULT 'sistema' CHECK (tema IN ('claro', 'escuro', 'sistema')),
    sons_ativos      BOOLEAN NOT NULL DEFAULT TRUE,
    volume_sons      SMALLINT NOT NULL DEFAULT 70 CHECK (volume_sons BETWEEN 0 AND 100),
    som_confirmacao  BOOLEAN NOT NULL DEFAULT TRUE,
    som_notificacao  BOOLEAN NOT NULL DEFAULT TRUE,
    som_erro         BOOLEAN NOT NULL DEFAULT TRUE,
    atualizado_em    TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------- (c)
-- Respondido pela equipe do sistema (por enquanto direto no banco:
-- status e resposta), e a coordenação acompanha pelo site.
CREATE TABLE IF NOT EXISTS tb_chamado_suporte (
    id_chamado      SERIAL PRIMARY KEY,
    id_coordenacao  INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    categoria       VARCHAR(20) NOT NULL CHECK (categoria IN ('duvida', 'problema', 'biometria', 'conta', 'sugestao')),
    assunto         VARCHAR(120) NOT NULL,
    mensagem        TEXT NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'em_andamento', 'respondido', 'fechado')),
    resposta        TEXT,
    respondido_em   TIMESTAMP,
    criado_em       TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em   TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_chamado_coordenacao
    ON tb_chamado_suporte (id_coordenacao, criado_em DESC);

-- Como as demais tabelas do Supabase: RLS ligado e nenhuma política, para
-- só a API (dona das tabelas) acessar - e não a API REST pública do
-- Supabase com a chave "anon".
ALTER TABLE tb_sessao                  ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_preferencia_coordenacao ENABLE ROW LEVEL SECURITY;
ALTER TABLE tb_chamado_suporte         ENABLE ROW LEVEL SECURITY;

COMMIT;

-- ===================================================================
-- Migração 008, incorporada aqui pelo mesmo motivo (catálogo de escolas,
-- entradas atrasadas e saídas antecipadas, configuração da escola).
-- ===================================================================
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

-- ===================================================================
-- Migração 009, incorporada aqui pelo mesmo motivo (confirmação do
-- e-mail e aprovação das contas novas, no lugar do código de acesso).
-- ===================================================================
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
