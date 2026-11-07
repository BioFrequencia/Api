# BioFrequência API

API PHP do sistema BioFrequência (TCC): registra as **entradas
atrasadas** e as **saídas antecipadas** dos alunos (as faltas ficam na
Sala do Futuro), avisa os responsáveis quando um aluno passa do limite
da escola e cuida da biometria do app da coordenação. Também: escolas
do catálogo da SEDUC-SP, contas por escola com aprovação da gestão,
calendário e horários das turmas, alertas, acompanhamento e a
administração do sistema.

Ver `AUDITORIA.md` para o histórico completo de diagnóstico, bugs
encontrados/corrigidos e o que foi testado em cada rodada.

## Dependências

- PHP >= 8.1 (com extensões `pdo` e `pdo_pgsql`; `zip` e `gd` para a importação por planilha)
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

O script já inclui as migrações **005** (escopo por escola, fila de
ocorrências do app e níveis de alerta), da qual o código atual depende
em quase todo endpoint, **006** (presença, consentimento e biometria cifrada do app da
coordenação), **007** (sessões e dispositivos conectados, preferências
da conta e chamados de suporte) e **008** (catálogo de escolas, papel e
aprovação das contas, saída antecipada, horário das turmas, calendário e
configuração da escola, avisos por limite). Em 30/10/2026 o banco de produção **ainda não
tinha nenhuma das duas**. Para saber se falta alguma migração, abra
`/health.php`: ele responde 503 com `"schema": "desatualizado"` e a
lista em `"faltando"`.

Alternativamente, os arquivos individuais em `database/migrations/`
(`001_schema_base.sql` reproduz o schema original de produção, com as
mesmas restrições CHECK/UNIQUE - não precisa rodar lá; `002`, `003`,
`005` a `008` são os que faltavam; `004_responsavel_cpf_unique.sql` é
opcional). Todos podem ser rodados mais de uma vez sem erro. As tabelas
novas já saem com RLS ligado e sem política, como as que existem no
Supabase: só a API (dona das tabelas) as acessa, e não a API REST
pública do Supabase.

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
CORS, status de notificação, calendário escolar (Páscoa, feriados,
horário e tolerância), indicadores e alertas, avisos, catálogo de
escolas, equipe e escalonamento.

Testes de integração (requisições HTTP de verdade + conferência no
banco): `tests/integracao_test.php`. **Apaga todos os dados do banco
apontado** - só roda com `BANCO_DE_TESTE=1` e banco em localhost. O CI
(`.github/workflows/api.yml`) roda tudo contra um Postgres 17 com as
restrições de produção, com `php -S` e com a imagem Docker (Apache); veja
lá as variáveis usadas. Para rodar na sua máquina, com um Postgres de
teste e a API no ar:

```bash
php -S 127.0.0.1:8099 tests/mock_provedores.php &   # WhatsApp e e-mail falsos
BANCO_DE_TESTE=1 API_URL=http://127.0.0.1:8080 DB_NAME=biofrequencia_teste \
  JWT_SECRET_KEY=... SITE_URL=https://site-teste.example CORS_ORIGENS=https://site-teste.example \
  EMAIL_API_TOKEN=... php tests/integracao_test.php
```

Arquitetura e fluxos entre site, app, API e banco: `docs/ARQUITETURA.md`.

## Estrutura

```
Authentication/   autenticação (JWT)
biometria/        cadastro/verificação de biometria facial
biometria/        app da coordenação: consentimento, cadastro e identificação facial, digital
presenca/         presenças registradas pelo app (facial, digital ou manual)
digital/          check-in pelo celular do próprio aluno (fluxo anterior)
acompanhamento/   registro de contatos com responsáveis
user/             coordenação, alunos, turmas, entradas atrasadas e saídas antecipadas, alertas, dashboard
registro/         registros do dia (entradas atrasadas e saídas), remoção pela gestão e importação
                  do que foi anotado sem internet (modelo .xlsx, prévia e confirmação)
escola/           busca no catálogo de escolas, configuração, horários, calendário e equipe da escola
admin/            painel da equipe do sistema (ADMIN_EMAILS): escolas, contas, chamados, catálogo
conta/            minha conta: perfil, senha, preferências, dispositivos conectados,
                  confirmação do e-mail (link do cadastro e da troca de e-mail)
suporte/          chamados de suporte abertos pela coordenação
helpers/          validação, respostas, calendário escolar, indicadores, alertas e avisos
database/         conexão PDO, migrações SQL e o catálogo de escolas (dados/escolas-sp.csv)
config/           configuração (config.php é local, não versionado)
ocorrencia/       ocorrências enviadas pelo app e revisão pela coordenação
notificacao/      fila de mensagens aos responsáveis
responsavel/      cadastro e vínculo de responsáveis
relatorio/        exportação XML
importacao/       importação de turmas e alunos por planilha: modelo .xlsx, prévia e confirmação
tests/            testes unitários e de integração
docs/             arquitetura
```

## Importação de turmas e alunos por planilha

A coordenação cadastra turmas e alunos em lote a partir de uma planilha
(Excel `.xlsx`/`.xls`, LibreOffice `.ods` ou `.csv`, até 2 MB), lida com o
[PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet):

1. `importacao/modelo.php` devolve o modelo `.xlsx` da escola: abas
   Instruções, Turmas (turma, período, início e fim da aula) e Alunos
   (nome completo, turma, data de nascimento, gênero e, opcionalmente, o
   responsável: nome, CPF, telefone, e-mail, parentesco e "avisar por"),
   com listas suspensas e as turmas que a escola já tem.
2. `importacao/previa.php` recebe o arquivo (`multipart/form-data`, campo
   `arquivo`) e devolve cada linha conferida - `nova`/`novo`, `existente`
   (a escola já tem: fica como está) ou `erro` (com o motivo) - sem gravar
   nada, e o `hash` do arquivo.
3. `importacao/confirmar.php` recebe o mesmo arquivo e o `hash` da prévia,
   refaz a conferência com o banco de agora e grava as linhas novas numa
   transação só, com a escola travada (`pg_advisory_xact_lock`): ou tudo
   entra, ou nada. Importar a mesma planilha de novo não duplica nada.

Os campos são os que o banco já tem (não há matrícula/RA): o aluno
repetido é reconhecido pelo nome + data de nascimento na escola, e o
responsável pelo CPF (o que já está na escola só é vinculado; irmãos com
o mesmo CPF dividem o mesmo responsável). Tudo fica na escola de quem
importa.

## Importação de entradas e saídas registradas sem internet

Sem internet, a coordenação anota as entradas atrasadas e as saídas
antecipadas no Excel; quando a conexão volta, importa a planilha (no site
ou no programa do computador). É independente da importação de turmas e
alunos e usa o mesmo leitor seguro de arquivos (`helpers/planilha.php`).

1. `registro/modeloImportacao.php` devolve o modelo `.xlsx`: abas
   Instruções, Ocorrências (código do aluno, nome, turma, data, horário,
   tipo, motivo/justificativa, justificada?, observação e quem buscou) e
   Alunos (código, nome, turma e período de todos os alunos da escola). Na
   aba Ocorrências, digitar o código traz o nome e a turma (PROCV).
2. `registro/previaImportacao.php` confere cada linha com as regras do
   lançamento manual (`helpers/importacaoOcorrencias.php`): aluno da escola
   (pelo código ou, sem ele, pelo nome e a turma), dia de aula, dentro do
   horário da turma, entrada atrasada só depois da tolerância, nada no
   futuro. Devolve `nova`, `existente` (já registrada: fica como está) ou
   `erro` (com o motivo), sem gravar nada.
3. `registro/confirmarImportacao.php` recebe o mesmo arquivo e o `hash` da
   prévia e registra as linhas novas numa transação só, pela mesma função
   do lançamento manual (uma entrada atrasada e uma saída antecipada por
   aluno por dia). Importar de novo não duplica nada.

Não há matrícula no banco: o identificador é o código interno do aluno,
que o modelo traz. Os responsáveis não recebem um aviso por registro
importado (chegaria horas ou dias depois, vários de uma vez); o aviso de
limite continua, no máximo um por período, como no lançamento manual.

## Segurança

- Senhas com Argon2id (`helpers/senha.php`, parâmetros mínimos do
  OWASP); hashes antigos (bcrypt, ou texto puro de antes da primeira
  correção) viram Argon2id no próximo login certo.
- Senha forte obrigatória (cadastro, troca e senha nova): 8 a 128
  caracteres, com maiúscula, minúscula, número e caractere especial. A
  API responde 422 com a lista do que falta em `dados.requisitos_senha`.
- Limite de tentativas de login (`helpers/tentativas.php`, migração 010):
  5 erros com o mesmo e-mail ou 20 da mesma rede em 15 minutos fazem
  esperar (429 com `Retry-After`). E-mail e IP ficam só como hash, e a
  resposta leva o mesmo tempo com ou sem conta para o e-mail.
- "Esqueci minha senha" (`conta/esqueciSenha.php` →
  `conta/redefinirSenha.php`): link por e-mail que vale 1 hora e uma vez,
  com resposta igual para e-mail com ou sem conta; a senha nova derruba
  todos os aparelhos conectados.
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
| `JWT_SECRET_KEY` | sim | Assina os tokens de login. Use uma string aleatória com **pelo menos 32 caracteres** (ex.: `php -r "echo bin2hex(random_bytes(32));"`), diferente em cada ambiente: a biblioteca de JWT recusa chave menor e, no Docker, a API nem sobe. Trocar a chave encerra as sessões abertas. |
| `CORS_ORIGENS` | não | Origens liberadas, separadas por vírgula (ex.: `https://biofrequencia.vercel.app`). Barra final e maiúsculas não importam. Um `*` num trecho do host libera os previews da Vercel (`https://site-git-*-equipe.vercel.app`); `https://*.vercel.app` é recusado. Sem ela valem as origens locais de desenvolvimento. |
| `SITE_URL` | não | Endereço do site nos links dos e-mails (confirmar o e-mail, aprovar contas, entrar). Sem ela, vale a primeira origem fixa de `CORS_ORIGENS`. |
| `BIOMETRIA_CHAVE` | para a biometria | Chave que cifra os vetores faciais e as digitais no banco: 64 caracteres hexadecimais (ou o base64 do `generateValue` do Render). Sem ela, os endpoints de biometria respondem 503. **Não troque depois de cadastrar alunos**: a biometria gravada com a chave antiga deixa de abrir e precisa ser refeita. |
| `PORT` | não | Porta do Apache no container (padrão 8080). O Render define sozinho. |
| `ADMIN_EMAILS` | não | E-mails da equipe do sistema (tela `/admin` do site: escolas, contas, chamados de suporte), separados por vírgula. É ela quem aprova as contas novas de escolas que ainda não usam o sistema. Sem ela, ninguém vê a área de administração. |
| `WHATSAPP_API_URL`, `WHATSAPP_TOKEN` | não | Provedor de WhatsApp dos avisos aos responsáveis (formato da Meta Cloud API). Sem eles, a fila de Notificações guarda um link `wa.me` pronto para a coordenação clicar. |
| `WHATSAPP_TEMPLATE`, `WHATSAPP_TEMPLATE_IDIOMA` | não | Modelo aprovado pela Meta, com uma variável (`{{1}}`) que recebe a mensagem, e o idioma (padrão `pt_BR`). Fora da janela de 24 h a Meta só entrega modelos. |
| `EMAIL_API_URL`, `EMAIL_API_TOKEN`, `EMAIL_REMETENTE` | para o cadastro | E-mail por HTTP, no formato da API do Resend (`{from, to[], subject, text, html}` com token Bearer): o link de confirmação de quem se cadastra, os avisos de aprovação e os avisos por e-mail aos responsáveis. O Render não tem servidor de e-mail, então `mail()` não entrega nada lá. Sem provedor, a equipe do sistema confirma o e-mail das contas novas à mão em `/admin`. |
| `SMTP_REMETENTE` | não | Alternativa ao e-mail por HTTP: remetente para o `mail()` do PHP, num servidor com sendmail. |

## Docker

Ambiente local completo (PostgreSQL 17 + migrações + a mesma imagem que
vai para o Render), com as variáveis em `.env` (copie de `.env.exemplo`;
o `.env` fica fora do Git e da imagem):

```bash
docker compose up -d --build     # API em http://localhost:8080 (banco na porta 5433)
docker compose logs -f api
docker compose down              # os dados ficam no volume "dados"
```

Testes de integração no Docker, iguais aos do CI (banco descartável e
provedores falsos de WhatsApp e e-mail - nenhuma mensagem real sai):

```bash
docker compose -f docker-compose.teste.yml up -d --build --wait api provedores-falsos
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
