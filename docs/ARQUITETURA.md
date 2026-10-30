# Arquitetura do BioFrequência

Documento de referência dos três repositórios, escrito a partir do código
(outubro/2026). Ver `AUDITORIA.md` para o histórico de correções.

## Componentes

| Repositório | Tecnologia | Hospedagem | Papel |
|---|---|---|---|
| `BioFrequencia/Api` | PHP 8.3 sem framework (um arquivo `.php` por endpoint), PDO + PostgreSQL, JWT (`firebase/php-jwt`) | Render (Docker: Apache + mod_php) | Toda regra de negócio e acesso ao banco |
| `BioFrequencia/Site` | Next.js 16 (App Router), React 19, TypeScript | Vercel | Painel da coordenação no navegador |
| `BioFrequencia/App` | Expo SDK 57, React Native 0.86, TypeScript | APK (GitHub Actions / EAS) | App do aluno: check-in e ocorrências com biometria do celular |
| Banco | PostgreSQL 17 no Supabase (projeto `Bd_BioFrequencia`, sa-east-1) | Supabase | Dados. RLS ligado em todas as tabelas, sem políticas: a Data API (anon/authenticated) não lê nada; só a API PHP (role `postgres`, via pooler) acessa |

O desktop (C#/WPF) consome a mesma API, mas não está nestes repositórios.

## Fluxos entre as camadas

```
Site (navegador)  --HTTPS + Bearer JWT-->  API (Render)  --pooler 6543-->  Supabase
App (celular)     --HTTPS + HMAC-------->  API (Render)
```

1. **Login da coordenação**: `user/selectCoord.php` confere email (sem
   diferenciar maiúsculas) e senha (`password_verify`; contas antigas em
   texto puro migram no primeiro login) e emite um JWT HS256 de 2 h com o
   `id_coordenacao` em `sub`. O site guarda o token no `localStorage` e
   manda `Authorization: Bearer` em toda chamada. Token vencido: o site
   descobre pelo `exp` ao abrir/voltar à aba ou pelo 401 da API e manda
   para `/login?motivo=expirada`.
2. **Autorização por escola**: todo endpoint autenticado chama
   `escolaDaCoordenacao()` e confere, antes de ler ou gravar, que o
   registro recebido (aluno, responsável, notificação, check-in...) é da
   escola de quem chamou (`helpers/escola.php`). O aluno pertence à escola
   pela turma (`tb_aluno.id_sala -> tb_sala.id_escola`). Registro de outra
   escola responde 404.
3. **Cadastro de coordenação**: exige o código `CADASTRO_CODIGO_ACESSO`;
   a conta nova cai na escola padrão (`tb_coordenacao.id_escola DEFAULT 1`).
4. **Faltas e atrasos** (`helpers/registroEvento.php`): no máximo um por
   aluno por dia (fuso da escola), com trava `pg_advisory_xact_lock`.
   Usado pelo lançamento manual e pela aprovação de ocorrências do app.
5. **Aluno no app** (`tb_conta_aluno`, migração 006): o aluno cria a conta
   no próprio app (`aluno/criarConta.php`) com o nome e a data de
   nascimento do cadastro feito pela escola, e-mail e senha. A conta só é
   criada para um aluno que já existe (vale o nome completo ou o primeiro
   e o último nome, mais a data de nascimento); uma por aluno; e-mail
   único entre alunos e coordenação. O login (`aluno/entrar.php`) manda
   e-mail, senha e um UUID do aparelho e recebe um segredo de 64 hex,
   guardado no Keystore/Keychain - um celular conectado por aluno (entrar
   em outro desconecta o anterior). Cada envio (check-in, ocorrência)
   confirma a identidade **no aparelho** (biometria ou PIN do celular -
   nada biométrico vai para a API) e é assinado com
   HMAC-SHA256(`uuid|tipo|timestamp`). A API aceita a assinatura numa
   janela de 5 minutos. A coordenação vê a conta na página do aluno e
   pode desconectar o celular (`digital/revogarDispositivo.php`) ou
   remover a conta (`aluno/removerConta.php`). Conta de aluno no login do
   site, ou de coordenação no app, recebe 403 explicando onde ela entra.
   Isto substituiu o código de vínculo de 8 caracteres que a coordenação
   gerava no site.
6. **Ocorrência do app**: fica pendente (`tb_ocorrencia_pendente`) até a
   coordenação aprovar no site; aprovar lança a falta/atraso no dia do
   envio.
7. **Alertas e mensagens**: `user/alertas.php` calcula os alertas
   (frequência do mês, faltas consecutivas, atrasos da semana), grava o
   nível de cada aluno (0-4, `tb_alerta_nivel`) e, no nível 4, contata os
   responsáveis (`helpers/contatoResponsavel.php`): WhatsApp/e-mail se o
   provedor estiver configurado, senão fica na fila com um link `wa.me`.
   No máximo um contato por aluno a cada 24 h (trava atômica). Toda falta
   lançada também cria uma notificação "Pendente" por responsável, que a
   coordenação marca como enviada depois de avisar.

## Tempo e fuso

O banco grava `TIMESTAMP` sem fuso em **UTC** (`NOW()` do Supabase). A
API fixa o PHP em UTC (`helpers/tempo.php`) e calcula "hoje", "mês" e o
dia de cada registro no fuso **America/Sao_Paulo**. As respostas trazem
os horários em UTC sem sufixo; o site lê assim e mostra no fuso da
escola (`lib/format.ts`). Datas puras (`AAAA-MM-DD`) são dias de
calendário da escola.

## Configuração por ambiente

| Onde | Variável | Uso |
|---|---|---|
| Render (API) | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Supabase pelo connection pooler (porta 6543) |
| Render | `JWT_SECRET_KEY` | Gerada pelo Render (render.yaml) |
| Render | `CORS_ORIGENS` | Origens do site, separadas por vírgula; aceita `*` num trecho do host para os previews da Vercel |
| Render | `CADASTRO_CODIGO_ACESSO` | Código para criar contas de coordenação |
| Render | `SMTP_REMETENTE`, `WHATSAPP_API_URL`, `WHATSAPP_TOKEN` | Opcionais, envio automático no nível máximo de alerta |
| Vercel (site) | `NEXT_PUBLIC_API_URL` | URL pública da API. Sem ela, o login mostra erro de configuração (nunca usa localhost) |
| Vercel | `NEXT_PUBLIC_APK_URL` | Opcional: link do APK na página de downloads |
| GitHub (App) | variável `API_URL` | Endereço embutido no APK do CI (padrão: `https://biofrequencia-api.onrender.com/`) |
| EAS | variável `API_URL` | O mesmo, para builds no EAS |

## Verificações automáticas (CI)

- **Api** (`.github/workflows/api.yml`): sintaxe de todos os PHP, testes
  unitários (`tests/helpers_test.php`), migrações aplicadas duas vezes num
  Postgres 17 com as restrições de produção, testes de integração HTTP
  (`tests/integracao_test.php`) contra `php -S` e contra a imagem Docker
  real, health check recusando banco sem migrações.
- **Site** (`.github/workflows/site.yml`): `npm test`, `tsc`, ESLint e
  build de produção com a URL da API embutida.
- **App** (`.github/workflows/android.yml`): `tsc`, `npm test`,
  `expo install --check`, configuração de distribuição apontando para
  HTTPS público, APK via Gradle (e EAS, se houver `EXPO_TOKEN`).

## Saúde em produção

`GET /health.php` responde 200 só com banco acessível **e** schema com
as migrações que o código usa; senão 503 dizendo quais faltam. O Render
usa esse endereço como health check: um deploy de código mais novo que o
banco não entra no ar, e só publica depois do CI passar
(`autoDeployTrigger: checksPass`).
