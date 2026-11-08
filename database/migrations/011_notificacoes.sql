-- Migração 011: avisos aos responsáveis por e-mail e WhatsApp - automáticos
-- e enviados pela coordenação.
--
--   (a) tb_notificacao: quem enviou (a conta da coordenação, no envio
--       manual; vazio = aviso automático), quando a mensagem foi criada (a
--       data de envio só existe quando o provedor aceita), o assunto do
--       e-mail e a chave do envio manual. A mesma chave não manda de novo:
--       clique duplo ou a conexão que caiu e repetiu o pedido não duplicam
--       a mensagem para o mesmo responsável e canal;
--   (b) tb_configuracao_escola: quais avisos automáticos a escola usa, um
--       por tipo - entrada atrasada, saída antecipada, limite de entradas
--       atrasadas e limite de saídas antecipadas. Começam com o que a
--       escola já tinha (aviso_cada_registro e aviso_automatico, que
--       continuam no banco, para os programas antigos).
--
-- Aditiva e idempotente: nada é apagado nem renomeado, e rodar de novo não
-- muda nada.

BEGIN;

-- ---------------------------------------------------------------- (a)
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS id_coordenacao INT REFERENCES tb_coordenacao(id_coordenacao);
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP;
ALTER TABLE tb_notificacao ALTER COLUMN criado_em SET DEFAULT NOW();
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS assunto VARCHAR(150);
ALTER TABLE tb_notificacao ADD COLUMN IF NOT EXISTS chave_envio VARCHAR(64);
CREATE UNIQUE INDEX IF NOT EXISTS uq_notificacao_chave_envio
    ON tb_notificacao (chave_envio, id_responsavel, canal) WHERE chave_envio IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_notificacao_criado_em ON tb_notificacao (criado_em);

-- ---------------------------------------------------------------- (b)
ALTER TABLE tb_configuracao_escola ADD COLUMN IF NOT EXISTS aviso_entrada_atrasada BOOLEAN;
ALTER TABLE tb_configuracao_escola ADD COLUMN IF NOT EXISTS aviso_saida_antecipada BOOLEAN;
ALTER TABLE tb_configuracao_escola ADD COLUMN IF NOT EXISTS aviso_limite_atrasos   BOOLEAN;
ALTER TABLE tb_configuracao_escola ADD COLUMN IF NOT EXISTS aviso_limite_saidas    BOOLEAN;
UPDATE tb_configuracao_escola SET aviso_entrada_atrasada = aviso_cada_registro WHERE aviso_entrada_atrasada IS NULL;
UPDATE tb_configuracao_escola SET aviso_saida_antecipada = aviso_cada_registro WHERE aviso_saida_antecipada IS NULL;
UPDATE tb_configuracao_escola SET aviso_limite_atrasos   = aviso_automatico    WHERE aviso_limite_atrasos   IS NULL;
UPDATE tb_configuracao_escola SET aviso_limite_saidas    = aviso_automatico    WHERE aviso_limite_saidas    IS NULL;

COMMIT;
