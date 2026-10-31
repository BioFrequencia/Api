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
3. **Cadastro de coordenação**: exige o código `CADASTRO_CODIGO_ACESSO`.
   Cada coordenação é uma escola independente: a conta nova cria a
   própria escola (`tb_escola`), e as turmas (`user/addSala.php`) e os
   alunos dela não aparecem para nenhuma outra conta. As contas que já
   existiam antes da migração 005 ficam juntas na escola 1.
4. **Faltas e atrasos** (`helpers/registroEvento.php`): no máximo um por
   aluno por dia (fuso da escola), com trava `pg_advisory_xact_lock`.
   Usado pelo lançamento manual e pela aprovação de ocorrências do app.
5. **App de biometria da coordenação** (migração 006): roda nos tablets e
   celulares da escola, com a mesma conta da coordenação usada no site
   (`user/selectCoord.php` com `cliente: "app"` dá uma sessão de 12 h) e
   só enxerga os alunos dessa escola (`biometria/alunos.php`).
   - **Consentimento** (`biometria/consentimento.php`): sem o nome do
     responsável que autorizou, a API recusa cadastrar biometria (LGPD,
     arts. 11 e 14). Revogar apaga a biometria do aluno.
   - **Facial**: o app tira as fotos e roda o modelo (face-api.js,
     ResNet-34) **no aparelho**; a foto nunca sai dele, só o vetor de 128
     números. O cadastro (`biometria/cadastrarBiometriaFacial.php`) guarda
     até 5 amostras por aluno, **cifradas** com AES-256-GCM
     (`helpers/criptografia.php`, chave `BIOMETRIA_CHAVE` fora do banco,
     id do aluno como dado autenticado), e recusa (409) um rosto que já é
     de outro aluno da escola. A identificação
     (`biometria/identificarFacial.php`) é 1:N no servidor, só entre os
     alunos da escola: reconhece quando a menor distância fica abaixo de
     0,5 e se destaca do segundo aluno mais parecido.
   - **Presença** (`tb_presenca`): reconhecido, a presença do dia é
     gravada na hora; a restrição UNIQUE (aluno, dia letivo) impede
     duplicar mesmo com dois tablets ao mesmo tempo. Sem reconhecimento,
     a coordenação registra à mão (`presenca/registrar.php`). O site e o
     app leem em `presenca/listar.php`.
   - **Digital**: o sensor do próprio celular não serve (o Android só
     confirma o dono do aparelho e não entrega a digital de ninguém). Está
     preparada para um leitor USB: templates cifrados
     (`biometria/cadastrarBiometriaDigital.php`), entregues ao SDK do
     leitor para a comparação no aparelho
     (`biometria/templatesDigitais.php`), e presença com `metodo:
     "digital"`.
   - Toda tentativa de identificação fica em `tb_identificacao_biometrica`
     (sem dado biométrico); `biometria/excluirBiometria.php` apaga a
     biometria a pedido.

   O fluxo anterior - o aluno confirmando a chegada no próprio celular
   (`digital/checkinBiometrico.php`, assinatura HMAC) - continua aceito
   para os aparelhos que já estavam conectados, mas nenhum aparelho novo
   é registrado.
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
