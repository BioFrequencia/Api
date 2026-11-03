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
   `id_coordenacao` em `sub` (30 dias com "Lembrar de mim"; 12 h no app).
   O site guarda o token no `localStorage` e manda `Authorization:
   Bearer` em toda chamada. Token vencido: o site descobre pelo `exp` ao
   abrir/voltar à aba ou pelo 401 da API e manda para
   `/login?motivo=expirada`.

   **Sessões** (migração 007): cada login vira uma linha de `tb_sessao`
   (navegador ou app, sem IP) e o id dela vai no token como `sid`.
   `autenticar()` recusa (401 "Sessão encerrada") o token cuja sessão foi
   encerrada - em Minha conta > Dispositivos conectados
   (`conta/sessoes.php`, `conta/encerrarSessao.php`), ao sair
   (`conta/sair.php`) ou ao trocar a senha (os outros aparelhos caem).
   Tokens emitidos antes da 007, sem `sid`, valem até vencer.
2. **Autorização por escola**: todo endpoint autenticado chama
   `escolaDaCoordenacao()` e confere, antes de ler ou gravar, que o
   registro recebido (aluno, responsável, notificação, check-in...) é da
   escola de quem chamou (`helpers/escola.php`). O aluno pertence à escola
   pela turma (`tb_aluno.id_sala -> tb_sala.id_escola`). Registro de outra
   escola responde 404.
3. **Escolas e cadastro de coordenação** (migração 008): `tb_escola` tem o
   catálogo das 5.795 escolas estaduais de São Paulo (dados abertos da
   SEDUC, `database/dados/escolas-sp.csv`), importado pelo
   `docker-entrypoint.sh` sempre que a versão do arquivo muda
   (`database/importarEscolas.php`, nunca apaga nada). O cadastro
   (`user/addCoord.php`) exige o código `CADASTRO_CODIGO_ACESSO` e a escola
   escolhida na busca do catálogo (`escola/buscar.php`, pública), ou cria
   uma escola fora dele. Cada conta é de uma escola: a primeira vira
   **gestora** (`tb_coordenacao.papel`); as próximas ficam **pendentes**
   (`situacao`) até a gestão aprovar em Escola > Equipe
   (`escola/atualizarMembro.php`). Conta pendente, recusada ou desativada
   não entra, e quem é desativado perde as sessões na hora. A escola
   nunca fica sem gestão ativa (`helpers/equipe.php`).
4. **Entradas atrasadas e saídas antecipadas** (`helpers/registroEvento.php`,
   `user/marcarAtraso.php`, `user/marcarSaida.php`): o objetivo do
   sistema. Só num dia de aula e dentro do horário da turma
   (`helpers/calendario.php`): fins de semana, feriados nacionais e de SP
   e os dias "sem aula" do calendário da escola são recusados (422, com o
   motivo), assim como uma entrada atrasada fora do período da turma (o
   aluno da manhã às 17h) ou dentro da tolerância. No máximo um registro
   de cada tipo por aluno por dia (trava `pg_advisory_xact_lock`). Faltas
   não são mais registradas: ficam na Sala do Futuro
   (`user/marcarFalta.php` responde 410; `tb_falta` guarda as antigas).
   A presença registrada pelo app depois da tolerância já vira a entrada
   atrasada (`origem = 'presenca'`), se a escola deixar a opção ligada.
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
6. **Ocorrência do celular do aluno** (fluxo anterior): fica pendente
   (`tb_ocorrencia_pendente`) até a coordenação decidir; aprovar lança a
   entrada atrasada no dia do envio, com as mesmas regras. Ocorrência de
   falta só pode ser rejeitada.
7. **Indicadores, alertas e avisos** (`helpers/indicadores.php`,
   `helpers/alertas.php`, `helpers/avisos.php`): toda porcentagem tem
   denominador real - os dias de aula que já passaram, de cada aluno, e
   as ocorrências contadas por dia em dia letivo. Pontualidade = dias de
   aula sem entrada atrasada; sem dia de aula no período, o valor é
   `null`, nunca 100%. O alerta é por aluno e tipo na janela da escola
   (padrão 30 dias): metade do limite = baixa, o limite (padrão 20%, com
   pelo menos 3 ocorrências) = média, o dobro = alta. Ao passar do
   limite, os responsáveis recebem o aviso pelo canal preferido de cada
   um (WhatsApp, e-mail ou os dois) - logo depois do registro e de novo
   ao abrir os alertas, no máximo um a cada 7 dias por aluno e tipo
   (`tb_aviso_limite`, trava tomada antes do envio). Sem provedor, a
   mensagem fica em Notificações com o link `wa.me`. O aviso a cada
   registro existe, desligado por padrão. Limite, mínimo, janela,
   intervalo, tolerância e as opções ficam em `tb_configuracao_escola`
   (Escola > Avisos, só a gestão).
8. **Minha conta** (migração 007): `conta/perfil.php` e
   `conta/atualizarPerfil.php` (nome e e-mail - trocar o e-mail, que é o
   login, pede a senha atual; o nome da escola só muda pela gestão e só
   fora do catálogo), `conta/alterarSenha.php`
   (confere a atual e desconecta os outros aparelhos) e as preferências
   da conta (`conta/preferencias.php`, `conta/salvarPreferencias.php`:
   tema e sons), guardadas no banco para valer em qualquer computador.
   Senha atual errada responde 403, não 401 - o site trata 401 como
   sessão vencida.
9. **Suporte** (migração 007): `suporte/abrirChamado.php` (até 10 por dia
   por conta) e `suporte/listarChamados.php`. A equipe do sistema responde
   na tela `/admin` do site (`admin/chamados.php`,
   `admin/responderChamado.php`), e a coordenação acompanha em Suporte.
10. **Administração** (migração 008): a equipe do sistema são as contas
   com o e-mail em `ADMIN_EMAILS`. `admin/*` (resumo, escolas, contas,
   chamados, reimportar o catálogo) responde 404 para qualquer outra
   conta. A gestão de cada escola tem a tela Escola: dados do catálogo,
   horário das turmas (`escola/salvarTurma.php`), calendário
   (`escola/adicionarCalendario.php`), avisos
   (`escola/salvarConfiguracao.php`) e equipe.

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
