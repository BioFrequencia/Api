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
| Entrada atrasada registrada (site, desktop, planilha ou presença do app depois da tolerância) | A cada registro | Desligado | `aviso_entrada_atrasada` |
| Saída antecipada registrada | A cada registro | Desligado | `aviso_saida_antecipada` |
| Aluno passou do limite de entradas atrasadas | Logo depois do registro que fez passar do limite, e de novo ao abrir Alertas | Ligado | `aviso_limite_atrasos` |
| Aluno passou do limite de saídas antecipadas | Idem | Ligado | `aviso_limite_saidas` |
| Mensagem escrita pela coordenação | Ao clicar em Enviar, em Enviar mensagem | Sempre | — |
| Conta: confirmação de e-mail e "Esqueci minha senha" | Ao se cadastrar ou pedir a senha nova (só e-mail) | Sempre | — |

O limite é o da escola: por padrão, 20% dos dias de aula dos últimos 30 dias,
com pelo menos 3 ocorrências. O aviso de limite sai **no máximo uma vez a cada
7 dias** por aluno e por tipo. A trava é gravada antes do envio, para que dois
registros ao mesmo tempo não mandem a mensagem em dobro.

O canal de cada responsável é o escolhido no cadastro dele (WhatsApp, e-mail
ou os dois). Se faltar o contato daquele canal, a mensagem vai pelo outro.
Cada mensagem leva no fim o contato da escola do aluno, e o e-mail usa esse
endereço como "responder para".

## 2. E-mail: Resend

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
   - os erros de configuração mais comuns (domínio de teste do Resend,
     endereço da Meta fora do formato, WhatsApp sem modelo);
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
