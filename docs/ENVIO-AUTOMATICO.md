# Envio automático por WhatsApp e e-mail

Este guia diz o que contratar, o que configurar e como conferir o envio
automático de mensagens aos responsáveis. O código já está pronto. O que
falta são as contas nos provedores e as variáveis no Render.

Enquanto não houver provedor, **nada é simulado**:

- **WhatsApp:** a mensagem fica em Notificações com um link `wa.me` pronto.
  A coordenação clica e envia pelo WhatsApp da escola.
- **E-mail:** a mensagem fica na fila, com a situação "pendente".

O sistema só marca **enviada** quando o provedor aceita o envio. Se o
provedor recusa, a mensagem fica com **erro** e o motivo que ele deu.

## 1. Eventos que disparam mensagem

Os disparos são decididos em `helpers/avisos.php`, sempre **depois** que o
registro foi gravado: um erro no envio nunca desfaz o registro. Cada tipo de
aviso é ligado ou desligado pela gestão da escola, em Configurações.

| Evento do sistema | Quando dispara | Padrão | Chave da configuração |
| --- | --- | --- | --- |
| Entrada atrasada registrada (site, desktop, planilha ou presença do app depois da tolerância) | A cada registro | Ligado | `aviso_entrada_atrasada` |
| Saída antecipada registrada | A cada registro | Ligado | `aviso_saida_antecipada` |
| Aluno passou do limite de entradas atrasadas | Logo depois do registro que fez passar do limite, e de novo ao abrir Alertas | Ligado | `aviso_limite_atrasos` |
| Aluno passou do limite de saídas antecipadas | Idem | Ligado | `aviso_limite_saidas` |
| Resumo às famílias (quantas entradas atrasadas e saídas antecipadas no período; só para quem teve alguma) | Toda segunda-feira (semana anterior) ou todo dia 1º (mês anterior) | Semanal | `resumo_periodico` (migração 014) |
| Mensagem escrita pela coordenação | Ao clicar em Enviar, em Enviar mensagem | Sempre | — |
| Conta: confirmação de e-mail e "Esqueci minha senha" | Ao se cadastrar ou pedir a senha nova (só e-mail) | Sempre | — |

O limite é o da escola: por padrão, 20% dos dias de aula dos últimos 30 dias,
com pelo menos 3 ocorrências. O aviso de limite sai **no máximo uma vez a cada
7 dias** por aluno e por tipo. A trava é gravada antes do envio, para que dois
registros ao mesmo tempo não mandem a mensagem em dobro.

### 1.1 Resumo às famílias (agendador)

Quem dispara o resumo é o workflow `.github/workflows/resumo-periodico.yml`.
Ele chama `notificacao/resumoPeriodico.php` todo dia às 7h de Brasília, e a
API decide quais escolas estão no dia. Cada resumo entra em `tb_notificacao`
com uma chave do período antes de sair: rodar duas vezes não manda duas vezes.

Para ligar:

1. Gere uma chave aleatória de 16 caracteres ou mais.
2. No Render (Environment), crie `AGENDADOR_CHAVE` com essa chave.
3. No GitHub, em Settings > Secrets and variables > Actions, crie:
   - `AGENDADOR_CHAVE`, com o mesmo valor;
   - `API_URL`, com o endereço da API e a barra no fim.

Sem esses valores, o workflow termina com um aviso e a API responde 503, sem
mandar nada.

O canal de cada responsável é o escolhido no cadastro dele (WhatsApp, e-mail
ou os dois). Se faltar o contato daquele canal, a mensagem vai pelo outro.
Cada mensagem leva no fim o contato da escola do aluno, e o e-mail usa esse
endereço como "responder para".

## 2. E-mail

Há dois provedores prontos. O sistema escolhe pelo endereço em
`EMAIL_API_URL`:

- **Resend**: precisa de um domínio próprio verificado (SPF e DKIM). Sem
  ele, só entrega no e-mail do dono da conta.
- **Brevo**: entrega a qualquer pessoa com um remetente verificado, sem
  domínio próprio. É o caminho quando o projeto não tem domínio.

> **Erro comum: HTTP 307.** `https://resend.com/emails` é a página do
> **painel** que lista os e-mails enviados, não a API. Ela redireciona para
> o login, e o envio falhava com "o provedor recusou (HTTP 307)". O endereço
> da API é `https://api.resend.com/emails`. O sistema já troca o endereço
> do painel pelo da API sozinho, e o diagnóstico avisa para acertar na
> Render.

### 2.1 Resend (com domínio próprio)

1. Crie a conta em [resend.com](https://resend.com). O plano gratuito
   permite 3.000 e-mails por mês e 100 por dia.
2. Em **Domains**, cadastre o domínio da escola ou do projeto e crie no DNS
   os registros SPF e DKIM que o Resend mostrar. Espere o domínio ficar
   "Verified".
   - Sem um domínio verificado, o Resend só entrega no e-mail do dono da
     conta. Para os responsáveis, falha com HTTP 403.
3. Em **API Keys**, crie uma chave com permissão **Sending access**.
4. No Render, em **Environment** do serviço da API:

   | Variável | Valor |
   | --- | --- |
   | `EMAIL_API_URL` | `https://api.resend.com/emails` |
   | `EMAIL_API_TOKEN` | a chave criada no passo 3 (`re_...`) |
   | `EMAIL_REMETENTE` | `BioFrequência <nao-responda@SEU-DOMINIO>` |

### 2.2 Brevo (sem domínio próprio)

1. Crie a conta em [brevo.com](https://www.brevo.com). O plano gratuito
   permite 300 e-mails por dia. Uma conta nova pode precisar completar o
   perfil antes de liberar o envio.
2. Em **Senders, Domains & Dedicated IPs › Senders**, cadastre o e-mail que
   vai aparecer como remetente (por exemplo, o e-mail da coordenação) e
   confirme o código que a Brevo manda para ele.
3. Em **SMTP & API › API Keys**, gere uma chave de API (`xkeysib-...`).
4. No Render, em **Environment** do serviço da API:

   | Variável | Valor |
   | --- | --- |
   | `EMAIL_API_URL` | `https://api.brevo.com/v3/smtp/email` |
   | `EMAIL_API_TOKEN` | a chave criada no passo 3 (`xkeysib-...`) |
   | `EMAIL_REMETENTE` | `BioFrequência <o-e-mail-verificado-no-passo-2>` |

Com um remetente de e-mail gratuito (Gmail, Outlook), os provedores de
quem recebe podem mandar a mensagem para o spam. Peça para marcar "não é
spam" na primeira vez; com um domínio próprio verificado isso não acontece.

### 2.3 Outros

Outro provedor só funciona se tiver uma API HTTP no mesmo formato do Resend
(`from`, `to`, `subject`, `text`, `reply_to`, com autorização `Bearer`). A
alternativa sem HTTP é `SMTP_REMETENTE`, que usa a função `mail()` do PHP. Ela
não entrega no Render, porque lá não há servidor de e-mail.

## 3. WhatsApp: API oficial da Meta (Cloud API)

O sistema fala o formato da **WhatsApp Cloud API**. Esse formato é usado pela
Meta e pelos provedores oficiais (BSPs) que expõem a mesma API.

Não recomendamos APIs não oficiais (que conectam um WhatsApp comum por QR
code):

- violam os termos do WhatsApp e podem ter o número bloqueado;
- não têm modelos aprovados;
- não dão garantia nenhuma sobre os dados dos alunos (LGPD).

1. Crie um **portfólio empresarial** no [Meta Business](https://business.facebook.com)
   e verifique a empresa.
   - Sem a verificação, o limite é de 250 conversas iniciadas pela escola
     a cada 24 h.
2. Em [developers.facebook.com](https://developers.facebook.com), crie um app
   do tipo **Business** e adicione o produto **WhatsApp**.
3. Adicione um **número de telefone** que ainda não esteja no aplicativo
   WhatsApp: um chip novo ou um fixo que receba código por ligação.
4. Gere um **token permanente**:
   1. Em Configurações do negócio › Usuários do sistema, crie um usuário
      administrador.
   2. Gere um token com as permissões `whatsapp_business_messaging` e
      `whatsapp_business_management`.

   O token temporário do painel vence em 24 h.
5. Crie e envie para aprovação um **modelo de mensagem**:
   - **Categoria:** Utilidade.
   - **Idioma:** Português (BR).
   - **Nome:** por exemplo, `aviso_escola`.

   O corpo tem uma variável só, que recebe o texto do aviso, e não pode
   começar nem terminar com ela. Exemplo:

   > Olá! Mensagem da escola sobre a frequência do(a) estudante: {{1}}
   > Esta é uma mensagem automática do BioFrequência.

   A Meta só entrega texto livre a quem escreveu para o número da escola nas
   últimas 24 h. Para avisos iniciados pela escola, o modelo é obrigatório.
6. No Render:

   | Variável | Valor |
   | --- | --- |
   | `WHATSAPP_API_URL` | `https://graph.facebook.com/v23.0/<ID do número de telefone>/messages` (o ID fica em WhatsApp › Configuração da API; não é o número) |
   | `WHATSAPP_TOKEN` | o token permanente do passo 4 |
   | `WHATSAPP_TEMPLATE` | o nome do modelo aprovado (ex.: `aviso_escola`) |
   | `WHATSAPP_TEMPLATE_IDIOMA` | opcional; o padrão é `pt_BR` |

   Um BSP com a mesma API usa o endereço e o token dele, nas mesmas
   variáveis.

**Custos (Meta, 2025):** a Meta cobra cada mensagem de modelo entregue. Uma
mensagem de Utilidade para o Brasil custa centavos de dólar. Respostas dentro
da janela de 24 h são gratuitas. Confira a tabela atual da Meta antes de
contratar.

## 4. Conferir depois de configurar

1. Entre no site com uma conta da equipe do sistema (`ADMIN_EMAILS`) e abra
   **Administração › Visão geral**. O diagnóstico do envio mostra:
   - o que está configurado;
   - os erros de configuração mais comuns (endereço do painel do Resend no
     lugar do da API, domínio de teste do Resend, endereço da Meta fora do
     formato, WhatsApp sem modelo);
   - o último erro de cada canal.

   Nenhum segredo aparece no diagnóstico.
2. Em **Mensagem de teste**, escolha o canal e digite o **seu** e-mail ou
   celular. A mensagem sai pelo mesmo caminho dos avisos reais (mesmo
   formato, remetente e modelo). A resposta diz se o provedor aceitou ou o
   motivo da recusa. Ela não entra em Notificações.
   - "Aceitou" ainda não garante a entrega. Confira se a mensagem chegou.
3. Com tudo certo, mande uma mensagem pela tela **Enviar mensagem** para um
   aluno de teste com o seu contato como responsável.

## 5. O que ainda não existe (próximas etapas)

- **Confirmação de entrega e leitura.** A Meta e o Resend avisam por
  *webhook* quando a mensagem foi entregue, lida ou devolvida. Para usar
  isso, falta:
  - guardar o identificador que o provedor devolve em cada envio (coluna
    nova, numa migração);
  - criar os endpoints de webhook, com conferência da assinatura
    (`X-Hub-Signature-256` da Meta, Svix do Resend);
  - cadastrar esses endereços nos painéis dos provedores.

  Hoje, "enviada" quer dizer que o provedor aceitou.
- **Nova tentativa automática.** Uma mensagem com erro volta a ser enviada
  só quando alguém clica em "Tentar de novo". Isso é de propósito: repetir
  um WhatsApp sem a confirmação de entrega pode mandá-lo duas vezes.
