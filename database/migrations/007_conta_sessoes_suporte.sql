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
