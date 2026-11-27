-- Migração 015: canais de envio de cada escola.
--
-- Cada escola pode cadastrar o PRÓPRIO e-mail (Brevo ou Resend) e o PRÓPRIO
-- número de WhatsApp (Cloud API da Meta) para os avisos automáticos aos
-- responsáveis dela. Um e-mail remetente, um número e um ID de número da
-- Meta só podem pertencer a uma escola (índices únicos).
--
-- A chave/token do provedor fica cifrada (AES-256-GCM, chave derivada da
-- BIOMETRIA_CHAVE, que não está no banco) - helpers/canaisEnvio.php.
--
-- tb_notificacao.remetente_envio guarda por onde cada mensagem saiu (o
-- e-mail ou número da escola, ou o remetente do sistema), para o histórico
-- e o diagnóstico.
--
-- Aplicada pela API ao subir (database/migrar.php), numa transação. Só
-- acrescenta: nada existente muda. Para desfazer:
--   DROP TABLE tb_canal_envio;
--   ALTER TABLE tb_notificacao DROP COLUMN remetente_envio;

CREATE TABLE IF NOT EXISTS tb_canal_envio (
    id_canal           SERIAL PRIMARY KEY,
    id_escola          INT NOT NULL REFERENCES tb_escola(id_escola),
    canal              VARCHAR(10) NOT NULL,
    provedor           VARCHAR(10) NOT NULL,
    -- E-mail em minúsculas, ou o número do WhatsApp só com dígitos (com 55).
    remetente          VARCHAR(150) NOT NULL,
    nome_remetente     VARCHAR(100),
    -- WhatsApp: o ID do número na Meta (não é o telefone).
    id_numero          VARCHAR(40),
    credencial_cifrada TEXT NOT NULL,
    -- Últimos caracteres da chave, só para a tela reconhecer qual está salva.
    credencial_final   VARCHAR(4),
    modelo             VARCHAR(100),
    idioma_modelo      VARCHAR(10),
    verificado_em      TIMESTAMP,
    ultimo_teste_em    TIMESTAMP,
    ultimo_erro        VARCHAR(300),
    atualizado_em      TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_por     INT REFERENCES tb_coordenacao(id_coordenacao),
    CONSTRAINT uq_canal_envio_escola UNIQUE (id_escola, canal),
    CONSTRAINT ck_canal_envio_tipo CHECK (
        (canal = 'email' AND provedor IN ('brevo', 'resend'))
        OR (canal = 'whatsapp' AND provedor = 'meta' AND id_numero IS NOT NULL AND modelo IS NOT NULL)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_canal_envio_remetente ON tb_canal_envio (canal, lower(remetente));
CREATE UNIQUE INDEX IF NOT EXISTS uq_canal_envio_numero_meta ON tb_canal_envio (id_numero) WHERE id_numero IS NOT NULL;

-- Como as outras tabelas: RLS ligado e sem política (só a API acessa).
ALTER TABLE tb_canal_envio ENABLE ROW LEVEL SECURITY;

ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS remetente_envio VARCHAR(150);
