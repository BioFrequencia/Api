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

**Se `DB_HOST` for do Supabase, use o *connection pooler* (PgBouncer),
não a conexão direta.** Cada requisição desta API abre sua própria
conexão PDO; o site chega a disparar 2-6 requisições ao mesmo tempo ao
carregar uma única tela, e a conexão direta do Supabase tem um limite
baixo de conexões simultâneas (mais apertado ainda no plano gratuito) -
isso já causou 500 intermitentes de verdade num teste real (ver
AUDITORIA.md). No painel do Supabase: Project Settings → Database →
Connection pooling → copie a *connection string* do modo "Transaction"
(porta `6543`) e use o host/porta/usuário de lá em `DB_HOST`/`DB_PORT`/
`DB_USER` (o usuário muda de formato, normalmente
`postgres.<referência-do-projeto>`).

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

O script já inclui a migração **005** (escopo por escola, fila de
ocorrências do app e níveis de alerta), da qual o código atual depende
em quase todo endpoint. Em 29/10/2026 o banco de produção **ainda não a
tinha**. Para saber se falta alguma migração, abra `/health.php`: ele
responde 503 com `"schema": "desatualizado"` e a lista em `"faltando"`.

Alternativamente, os arquivos individuais em `database/migrations/`
(`001_schema_base.sql` reproduz o schema original de produção, com as
mesmas restrições CHECK/UNIQUE - não precisa rodar lá; `002`, `003` e
`005` são os que faltavam; `004_responsavel_cpf_unique.sql` é opcional).
Todos podem ser rodados mais de uma vez sem erro.

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

Testes unitários puros (sem banco): validação, CPF, fuso da escola,
CORS, status de notificação, escalonamento dos alertas.

Testes de integração (requisições HTTP de verdade + conferência no
banco): `tests/integracao_test.php`. **Apaga todos os dados do banco
apontado** - só roda com `BANCO_DE_TESTE=1` e banco em localhost. O CI
(`.github/workflows/api.yml`) roda tudo contra um Postgres 17 com as
restrições de produção, com `php -S` e com a imagem Docker (Apache); veja
lá as variáveis usadas. Para rodar na sua máquina, com um Postgres de
teste e a API no ar:

```bash
php -S 127.0.0.1:8099 tests/mock_whatsapp.php &   # provedor de WhatsApp falso
BANCO_DE_TESTE=1 API_URL=http://127.0.0.1:8080 DB_NAME=biofrequencia_teste \
  JWT_SECRET_KEY=... CADASTRO_CODIGO_ACESSO=... CORS_ORIGENS=https://site-teste.example \
  php tests/integracao_test.php
```

Arquitetura e fluxos entre site, app, API e banco: `docs/ARQUITETURA.md`.

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
ocorrencia/       ocorrências enviadas pelo app e revisão pela coordenação
notificacao/      fila de mensagens aos responsáveis
responsavel/      cadastro e vínculo de responsáveis
relatorio/        exportação XML
tests/            testes unitários e de integração
docs/             arquitetura
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

## Variáveis de ambiente

| Variável | Obrigatória | Para quê |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | sim | Conexão com o PostgreSQL. No Docker, o entrypoint gera `config/config.php` a partir delas. |
| `JWT_SECRET_KEY` | sim | Assina os tokens de login. Use uma string longa e aleatória, diferente em cada ambiente. |
| `CORS_ORIGENS` | não | Origens liberadas, separadas por vírgula (ex.: `https://biofrequencia.vercel.app`). Barra final e maiúsculas não importam. Um `*` num trecho do host libera os previews da Vercel (`https://site-git-*-equipe.vercel.app`); `https://*.vercel.app` é recusado. Sem ela valem as origens locais de desenvolvimento. |
| `CADASTRO_CODIGO_ACESSO` | não | Código pedido no cadastro de uma nova conta de coordenação. Sem ele, o cadastro fica desligado. No XAMPP pode ser a constante de mesmo nome em `config/config.php`. |
| `PORT` | não | Porta do Apache no container (padrão 8080). O Render define sozinho. |
| `SMTP_REMETENTE` | não | Remetente dos e-mails de nível máximo de alerta. Sem ela, o aviso fica na fila em vez de ser enviado. |
| `WHATSAPP_API_URL`, `WHATSAPP_TOKEN` | não | Provedor de WhatsApp. Sem eles, a fila guarda um link `wa.me` pronto para a coordenação clicar. |

## Docker

Ambiente local completo (PostgreSQL 17 + migrações + a mesma imagem que
vai para o Render), com as variáveis em `.env` (copie de `.env.exemplo`;
o `.env` fica fora do Git e da imagem):

```bash
docker compose up -d --build     # API em http://localhost:8080 (banco na porta 5433)
docker compose logs -f api
docker compose down              # os dados ficam no volume "dados"
```

Testes de integração no Docker, iguais aos do CI (banco descartável e um
provedor de WhatsApp falso - nenhuma mensagem real sai):

```bash
docker compose -f docker-compose.teste.yml up -d --build --wait api whatsapp-falso
docker compose -f docker-compose.teste.yml run --rm testes
docker compose -f docker-compose.teste.yml down -v
```

O site aponta para essa API com `NEXT_PUBLIC_API_URL=http://localhost:8080/`
no `.env.local` dele.

Só a imagem, sem compose:

```bash
docker build -t biofrequencia-api .
docker run -p 8080:8080 --env-file .env -e DB_HOST=seu-host -e DB_PORT=5432 \
  -e DB_NAME=biofrequencia -e DB_USER=postgres biofrequencia-api
curl http://localhost:8080/health.php
```

O container recusa subir se faltar alguma variável obrigatória — é melhor
falhar na inicialização do que responder erro na primeira requisição.

### Variáveis no Render

No painel do serviço: Environment → "Add from .env". Use `.env.exemplo`
como modelo; o banco é o Supabase pelo **connection pooler** (o host
direto `db.<ref>.supabase.co` só tem IPv6, que o Render não acessa).

## Quando o navegador acusar erro de CORS

Confira primeiro se não é a URL da API que está errada. Um caminho
inexistente devolve 404, e um 404 sem cabeçalho de CORS aparece no
console como "blocked by CORS policy". Teste direto:

```bash
curl -i -X POST https://sua-api/health.php -H 'Origin: https://seu-site.com'
```

- Resposta com `Access-Control-Allow-Origin`: o CORS está certo.
- Resposta 404 com `{"sucesso":false,...}`: a URL base está errada.
- Sem `Access-Control-Allow-Origin`: a origem do site não está em `CORS_ORIGENS`.
- Site em HTTPS chamando API em HTTP: o navegador bloqueia por conteúdo
  misto e a mensagem no console parece CORS. Publique a API em HTTPS.
