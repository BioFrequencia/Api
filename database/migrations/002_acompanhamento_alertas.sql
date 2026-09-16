-- Suporte às telas Acompanhamento e Alertas do desktop.

-- Registro de contatos/encaminhamentos com o responsável do aluno.
CREATE TABLE IF NOT EXISTS tb_acompanhamento (
    id_acompanhamento SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    canal VARCHAR(50) NOT NULL,
    observacao TEXT,
    data_registro TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Encerramento manual de um alerta (ver helpers/alertas.php para a
-- semântica completa: suprime pelo resto do dia corrente, reaparece
-- sozinho a partir do dia seguinte se houver novo evento).
CREATE TABLE IF NOT EXISTS tb_alerta_encerrado (
    id_aluno INT PRIMARY KEY REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    encerrado_em TIMESTAMP NOT NULL DEFAULT NOW()
);
