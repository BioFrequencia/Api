-- Migração 012: registros confiáveis, contato de cada escola e
-- identificação facial com confirmação.
--
--   (a) Cancelamento (exclusão lógica) de entradas atrasadas, saídas
--       antecipadas e presenças. O registro errado não some: ganha quem
--       cancelou, quando e por quê, e sai das contas, dos alertas e dos
--       relatórios. "Uma presença por aluno por dia" passa a valer só para
--       as não canceladas - senão a presença cancelada (ex.: o
--       reconhecimento facial apontou o aluno errado) impediria a presença
--       certa do mesmo aluno no mesmo dia. criado_em em tb_atraso e
--       tb_saida_antecipada: a hora em que o registro foi feito (a data do
--       registro pode ser de antes); os antigos ficam sem.
--   (b) tb_auditoria_registro: cada cancelamento e restauração, com o
--       registro como estava antes e como ficou, quem fez, quando e o
--       motivo. Só aceita inclusões: UPDATE e DELETE são recusados.
--   (c) Contato próprio de cada escola (e-mail e telefone), usado nas
--       mensagens aos responsáveis em vez de um contato único.
--   (d) tb_notificacao: o motivo da falha no envio (sem o contato nem o
--       texto da mensagem) e quem confirmou um aviso feito por fora do
--       sistema - para "enviada" não significar duas coisas.
--   (e) tb_identificacao_biometrica: a situação de cada tentativa, o aluno
--       candidato e a segunda menor distância. Identificação incerta não
--       grava presença; fica esperando a confirmação de quem está no tablet.
--
-- Aditiva e idempotente: nenhum dado é apagado ou alterado, e rodar de
-- novo não muda nada.
--
-- Atenção na implantação: (a) troca a restrição única uq_presenca_aluno_dia
-- por um índice parcial (só entre as presenças não canceladas). A versão
-- anterior da API grava presença com ON CONFLICT (id_aluno, dia_letivo),
-- que para de funcionar sem a restrição: aplique esta migração fora do
-- horário de aula e publique a API nova logo em seguida.

BEGIN;

-- ---------------------------------------------------------------- (a)
ALTER TABLE tb_atraso ADD COLUMN IF NOT EXISTS cancelado_em TIMESTAMP;
ALTER TABLE tb_atraso ADD COLUMN IF NOT EXISTS cancelado_por INT REFERENCES tb_coordenacao(id_coordenacao);
ALTER TABLE tb_atraso ADD COLUMN IF NOT EXISTS motivo_cancelamento VARCHAR(200);
ALTER TABLE tb_atraso ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP;
ALTER TABLE tb_atraso ALTER COLUMN criado_em SET DEFAULT NOW();

ALTER TABLE tb_saida_antecipada ADD COLUMN IF NOT EXISTS cancelado_em TIMESTAMP;
ALTER TABLE tb_saida_antecipada ADD COLUMN IF NOT EXISTS cancelado_por INT REFERENCES tb_coordenacao(id_coordenacao);
ALTER TABLE tb_saida_antecipada ADD COLUMN IF NOT EXISTS motivo_cancelamento VARCHAR(200);
ALTER TABLE tb_saida_antecipada ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP;
ALTER TABLE tb_saida_antecipada ALTER COLUMN criado_em SET DEFAULT NOW();

ALTER TABLE tb_presenca ADD COLUMN IF NOT EXISTS cancelado_em TIMESTAMP;
ALTER TABLE tb_presenca ADD COLUMN IF NOT EXISTS cancelado_por INT REFERENCES tb_coordenacao(id_coordenacao);
ALTER TABLE tb_presenca ADD COLUMN IF NOT EXISTS motivo_cancelamento VARCHAR(200);

-- A unicidade continua garantida pelo banco, agora só entre as ativas.
ALTER TABLE tb_presenca DROP CONSTRAINT IF EXISTS uq_presenca_aluno_dia;
CREATE UNIQUE INDEX IF NOT EXISTS uq_presenca_aluno_dia_ativa
    ON tb_presenca (id_aluno, dia_letivo) WHERE cancelado_em IS NULL;

-- ---------------------------------------------------------------- (b)
CREATE TABLE IF NOT EXISTS tb_auditoria_registro (
    id_auditoria   SERIAL PRIMARY KEY,
    id_escola      INT NOT NULL REFERENCES tb_escola(id_escola),
    tipo           VARCHAR(10) NOT NULL,
    id_registro    INT NOT NULL,
    id_aluno       INT NOT NULL REFERENCES tb_aluno(id_aluno),
    acao           VARCHAR(12) NOT NULL,
    antes          JSONB NOT NULL,
    depois         JSONB NOT NULL,
    motivo         VARCHAR(200),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    criado_em      TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT ck_auditoria_tipo CHECK (tipo IN ('atraso', 'saida', 'presenca')),
    CONSTRAINT ck_auditoria_acao CHECK (acao IN ('cancelar', 'restaurar'))
);
CREATE INDEX IF NOT EXISTS ix_auditoria_escola_data ON tb_auditoria_registro (id_escola, criado_em DESC);
CREATE INDEX IF NOT EXISTS ix_auditoria_registro ON tb_auditoria_registro (tipo, id_registro);

CREATE OR REPLACE FUNCTION fn_auditoria_somente_inclusao() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'tb_auditoria_registro só aceita inclusões';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_auditoria_somente_inclusao ON tb_auditoria_registro;
CREATE TRIGGER tg_auditoria_somente_inclusao
    BEFORE UPDATE OR DELETE ON tb_auditoria_registro
    FOR EACH ROW EXECUTE FUNCTION fn_auditoria_somente_inclusao();

-- Como as demais tabelas do Supabase: RLS ligado e nenhuma política, para
-- só a API (dona das tabelas) acessar.
ALTER TABLE tb_auditoria_registro ENABLE ROW LEVEL SECURITY;

-- ---------------------------------------------------------------- (c)
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS email_contato VARCHAR(150);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS telefone_contato VARCHAR(11);
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS contato_atualizado_em TIMESTAMP;
-- Sem chave estrangeira de propósito: tb_coordenacao já aponta para
-- tb_escola, e a volta criaria uma referência circular (apagar uma conta
-- ou esvaziar as tabelas num banco de teste passaria a mexer nas escolas).
ALTER TABLE tb_escola ADD COLUMN IF NOT EXISTS contato_atualizado_por INT;

-- ---------------------------------------------------------------- (d)
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS erro_envio VARCHAR(300);
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS confirmado_por INT REFERENCES tb_coordenacao(id_coordenacao);
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS confirmado_em TIMESTAMP;

-- ---------------------------------------------------------------- (e)
ALTER TABLE tb_identificacao_biometrica ADD COLUMN IF NOT EXISTS situacao VARCHAR(20);
ALTER TABLE tb_identificacao_biometrica ADD COLUMN IF NOT EXISTS id_candidato INT REFERENCES tb_aluno(id_aluno);
ALTER TABLE tb_identificacao_biometrica ADD COLUMN IF NOT EXISTS segunda_distancia NUMERIC(6,5);
ALTER TABLE tb_identificacao_biometrica ADD COLUMN IF NOT EXISTS amostras SMALLINT;
ALTER TABLE tb_identificacao_biometrica ADD COLUMN IF NOT EXISTS id_presenca INT REFERENCES tb_presenca(id_presenca);
ALTER TABLE tb_identificacao_biometrica ADD COLUMN IF NOT EXISTS resolvido_em TIMESTAMP;
ALTER TABLE tb_identificacao_biometrica DROP CONSTRAINT IF EXISTS ck_identificacao_situacao;
ALTER TABLE tb_identificacao_biometrica ADD CONSTRAINT ck_identificacao_situacao
    CHECK (situacao IS NULL OR situacao IN ('reconhecido', 'incerto', 'nao_reconhecido', 'confirmado', 'recusado'));

COMMIT;
