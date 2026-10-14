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
