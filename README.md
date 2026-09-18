# BioFrequência API

API PHP do sistema BioFrequência (TCC): autenticação de coordenação,
cadastro/listagem de alunos, registro de faltas/atrasos, alertas,
acompanhamento e biometria (facial + dispositivo do aluno).

Ver `AUDITORIA.md` para o histórico completo de diagnóstico, bugs
encontrados/corrigidos e o que foi testado em cada rodada.

## Dependências

- PHP >= 8.1 (com extensões `pdo` e `pdo_pgsql`)
- Composer
- PostgreSQL

## Configuração

```bash
composer install
cp config/config_example.php config/config.php
```

Edite `config/config.php` com os dados reais do banco e um
`JWT_SECRET_KEY` aleatório (ex.: `php -r "echo bin2hex(random_bytes(32));"`).
Esse arquivo é ignorado pelo git de propósito - nunca commite credenciais
reais.

## Banco de dados

**Passo obrigatório, fácil de esquecer**: `dashboard.php`,
`notificacao/listarNotificacoes.php` e `digital/*` usam tabelas
(`tb_acompanhamento`, `tb_alerta_encerrado`, `tb_biometria_facial`,
`tb_verificacao_facial`, `tb_codigo_vinculo`, `tb_dispositivo_aluno`,
`tb_checkin_biometrico`) que **não existem** no schema original do
Supabase - elas foram criadas só durante este projeto. Sem rodar a
migração abaixo no banco de produção, esses endpoints (e as telas do
desktop/site que dependem deles) respondem "Erro no servidor" (500). Ver
"Causa raiz de dias de erro 500" em `AUDITORIA.md` para o diagnóstico
completo (isto já aconteceu de verdade e ficou sem solução por dias).

Rode `database/migracao-completa.sql` inteiro no SQL Editor do Supabase
(ou via `psql`) contra o banco de produção - é seguro mesmo que algumas
tabelas já existam (todo `CREATE TABLE` usa `IF NOT EXISTS`, e o único
`ALTER TABLE` está protegido contra "já existe"; testado rodando duas
vezes seguidas sem erro):

```bash
psql -U seu_usuario -d seu_banco -f database/migracao-completa.sql
```

Alternativamente, os arquivos individuais em `database/migrations/`
(`001_schema_base.sql` é só documentação do schema original - não
precisa rodar; `002_acompanhamento_alertas.sql` e `003_biometria.sql`
são os que faltam; `004_responsavel_cpf_unique.sql` é opcional, ver o
comentário no próprio arquivo antes de rodar).

## Rodando localmente

```bash
php -S 127.0.0.1:8089
```

A API fica disponível em `http://127.0.0.1:8089/`. O desktop aponta pra
ela via `appsettings.json` (ver o repositório `SoftwareDesktop`).

## Testes

```bash
php tests/helpers_test.php
```

Testes unitários puros das funções de validação/cálculo (sem banco).
Para testar os endpoints de verdade, suba o servidor local acima e use
`curl` contra um banco de teste - não há suíte de integração automatizada
ainda (ver AUDITORIA.md, seção de pendências).

## Estrutura

```
Authentication/   autenticação (JWT)
biometria/        cadastro/verificação de biometria facial
digital/          pareamento e check-in do app do aluno (biometria "digital")
acompanhamento/   registro de contatos com responsáveis
user/             coordenação, alunos, salas, faltas/atrasos, alertas, dashboard
helpers/          validação, respostas padronizadas, cálculo de frequência/alertas
database/         conexão PDO + migrações SQL
config/           configuração (config.php é local, não versionado)
tests/            testes unitários
```

## Segurança

- Senhas com `password_hash`/`password_verify` (migração automática de
  contas antigas em texto puro).
- Autenticação via JWT (`Authorization: Bearer <token>`) obrigatória em
  todo endpoint que expõe dado de aluno/coordenação.
- Mensagens de erro para o cliente nunca incluem detalhes de exceção/SQL
  (`helpers/resposta.php`); o detalhe completo vai para o log do
  servidor.
- `device_secret` (biometria digital) fica em texto puro no banco de
  propósito - é um segredo simétrico necessário para verificar a
  assinatura HMAC dos check-ins, não uma senha de usuário. Considere
  criptografar essa coluna em repouso (pgcrypto) em produção.
