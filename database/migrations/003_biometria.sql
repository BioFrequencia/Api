-- Suporte a biometria facial (desktop, webcam) e biometria "digital"
-- (app no celular do próprio aluno - ver README/biometria.md para a
-- explicação de por que isso não é o template bruto da digital).

-- Um embedding (vetor numérico, formato JSON) por aluno, cadastrado pela
-- coordenação a partir de uma foto capturada no desktop.
CREATE TABLE IF NOT EXISTS tb_biometria_facial (
    id_biometria_facial SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL UNIQUE REFERENCES tb_aluno(id_aluno),
    embedding TEXT NOT NULL,       -- JSON: array de floats
    modelo VARCHAR(100) NOT NULL,  -- identifica o modelo/dimensão usado (compat futura)
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    data_cadastro TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Auditoria: toda tentativa de verificação facial (bateu ou não).
CREATE TABLE IF NOT EXISTS tb_verificacao_facial (
    id_verificacao SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    similaridade NUMERIC(6,5) NOT NULL,
    correspondeu BOOLEAN NOT NULL,
    data_verificacao TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Biometria digital: o app no celular do próprio aluno é vinculado a um
-- id_aluno através de um código de uso único gerado pela coordenação.
-- Depois do vínculo, o app confirma a digital/Face ID LOCALMENTE no
-- aparelho (a API nunca recebe o template biométrico bruto - isso não
-- sai do hardware seguro do celular) e assina o check-in com o segredo
-- do dispositivo (HMAC-SHA256).
CREATE TABLE IF NOT EXISTS tb_codigo_vinculo (
    id_codigo SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_coordenacao INT NOT NULL REFERENCES tb_coordenacao(id_coordenacao),
    codigo VARCHAR(12) NOT NULL UNIQUE,
    usado BOOLEAN NOT NULL DEFAULT FALSE,
    expira_em TIMESTAMP NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- device_secret fica em texto puro no banco (não é um hash de senha):
-- é um segredo simétrico, o servidor PRECISA do valor original para
-- recalcular o HMAC de cada check-in e comparar. Equivalente a uma
-- "API key" de dispositivo, não a uma senha de usuário. Pendência
-- documentada no relatório final: em produção, considerar criptografar
-- esta coluna em repouso (pgcrypto) já que um vazamento do banco
-- permitiria forjar check-ins.
CREATE TABLE IF NOT EXISTS tb_dispositivo_aluno (
    id_dispositivo SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    device_uuid VARCHAR(100) NOT NULL UNIQUE,
    device_secret VARCHAR(64) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    revogado_em TIMESTAMP
);

-- Cada check-in do app fica pendente até a coordenação confirmar (vira
-- um marcarAtraso.php normal só depois da confirmação humana) - decisão
-- deliberada de não gravar direto em tb_atraso: o celular do aluno não é
-- uma fonte confiável o bastante para escrever automaticamente no
-- histórico oficial (relógio do aparelho pode estar errado, o aparelho
-- pode ter sido perdido antes da revogação, etc.), mas já com a
-- confirmação biométrica local guardada como evidência.
CREATE TABLE IF NOT EXISTS tb_checkin_biometrico (
    id_checkin SERIAL PRIMARY KEY,
    id_aluno INT NOT NULL REFERENCES tb_aluno(id_aluno),
    id_dispositivo INT NOT NULL REFERENCES tb_dispositivo_aluno(id_dispositivo),
    tipo VARCHAR(20) NOT NULL,     -- 'atraso' | 'presenca'
    metodo VARCHAR(30) NOT NULL DEFAULT 'digital_dispositivo',
    data_checkin TIMESTAMP NOT NULL DEFAULT NOW(),
    confirmado_em TIMESTAMP,
    id_coordenacao_confirmacao INT REFERENCES tb_coordenacao(id_coordenacao)
);
