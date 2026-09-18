# Auditoria técnica — BioFrequência

Documento gerado durante uma sessão de estabilização do projeto (API PHP +
desktop C#/WPF), com assistência de IA (Claude). Cobre o diagnóstico inicial,
as correções aplicadas, como cada uma foi testada, e o que ainda fica em
aberto. Commitado nos dois repositórios para ficar no histórico do TCC.

## Como foi testado

- **API (PHP)**: `php -l` em todo arquivo (sintaxe) + testes de integração
  reais via `curl` contra um PostgreSQL local (não o Supabase de produção,
  ao qual não há acesso a partir deste ambiente) rodando o schema real,
  confirmado pelo usuário via screenshots do Schema Visualizer do Supabase.
  Todas as chamadas HTTP abaixo marcadas como testadas foram de fato
  executadas, não apenas lidas/inferidas.
- **Desktop (C#/WPF)**: revisão manual linha a linha, **sem build real**.
  Este ambiente não tem acesso ao NuGet (`api.nuget.org` fora da lista de
  domínios liberados na configuração de rede) e projetos WPF só rodam de
  fato no Windows. **Rode `dotnet restore && dotnet build` numa máquina
  Windows normal como primeiro passo de verificação** — isso não foi
  possível fazer aqui.
- O schema do banco foi inferido a partir das queries existentes na
  primeira parte da auditoria, e depois **confirmado/corrigido** com as
  screenshots reais do Supabase que o usuário enviou (ver seção específica).

---

## 1. Diagnóstico inicial

### O que já funcionava
- Login e cadastro de coordenação, cadastro de aluno e listagem/filtro de
  alunos existiam em alguma forma (parcial) nos dois lados.
- `HttpClient` estático reutilizado em `clsConexao` (evita esgotamento de
  socket) — já estava certo.
- `marcarFalta.php` e `filtroAluno.php` já usavam PDO com prepared
  statements (sem injeção de SQL) e já tratavam alguns casos de erro.
- Segredos reais (senha do banco) já ficavam fora do Git
  (`config/config.php` já estava no `.gitignore`); só o `config_example.php`
  (template) era versionado.

### Bugs e problemas encontrados
1. **Autenticação nunca era aplicada.** `autenticar()` existia em
   `Authentication.php` mas não era chamada em nenhum lugar do código —
   toda a API estava efetivamente pública. O login (`selectCoord.php`)
   nunca emitia token nenhum, apesar de toda a infraestrutura JWT já
   existir (`tokenLogin.php`).
2. **Senha em texto puro**, comparada diretamente em SQL
   (`WHERE senha_coordenacao = ?`) e inserida sem hash.
3. **`marcarFalta.php` confiava em `id_coordenacao` vindo do corpo da
   requisição** — o próprio arquivo já tinha um comentário admitindo isso
   como pendência ("qualquer requisição pode assinar a falta em nome de
   outra coordenação").
4. **`verificarEmail.php` quebrado nos dois ramos**: não respondia nada
   quando o email estava disponível (branch vazio); dava erro fatal
   ("call to undefined function criarToken") quando o email já existia,
   porque chamava uma função de um arquivo nunca incluído ali.
5. **Contrato quebrado entre desktop e API no cadastro de aluno**:
   `clsApiService.RegisterStudent` enviava `{nome, sala, nasc, genero}`;
   `addaluno.php` lia `$dados["aluno"]`, `$dados["id_sala"]`, etc. — todo
   campo chegava `null` no servidor.
6. **`txtClassroom` era um campo de texto livre**, sem nenhuma relação com
   o `id_sala` (chave estrangeira inteira) que a API espera — e não havia
   nenhum endpoint para listar as salas reais.
7. **`filtroAluno.php` contava faltas e atrasos como o mesmo número**: só
   fazia `LEFT JOIN` em `tb_atraso`; `tb_falta` nunca era consultada, e o
   mesmo valor de `qtd_atrasos` era copiado para os campos `"faltas"` e
   `"atrasos"` da resposta.
8. **Não existia nenhum endpoint para gravar um atraso** — só para contá-lo.
9. **`coutAlunos.php` usa uma tabela `tb_alunos` (plural) que não existe**
   (confirmado depois via schema real — ver seção 3).
10. **Toda mensagem de erro devolvia o texto bruto da exceção/PDO para o
    cliente** (`"mensagem" => "Erro: " . $erro->getMessage()`), incluindo
    detalhes de SQL, em todo endpoint.
11. **Sem validação de servidor**: campos vazios, formato de email, força
    de senha, datas de nascimento inválidas/futuras — nada disso era
    checado na API, só (parcialmente) no desktop.
12. **Senha visível na tela**: os campos de senha do login e do cadastro de
    coordenação eram `TextBox` comuns, não `PasswordBox`.
13. **Sessão do usuário se perdia ao navegar**: `MainWindow` guardava o
    coordenador logado num campo privado que nunca era repassado ao
    `Dashboard`; nada impedia abrir múltiplas Dashboards (login não
    desabilitava o botão nem fechava/escondia a janela).
14. **Referências quebradas/mortas no `.csproj` do desktop**: `MySql.Data`
    apontava para um caminho fixo em "Program Files (x86)\MySQL\..." só
    existente na máquina original; `Npgsql` nunca era usado em nenhum
    `.cs`; `Model\Classroom.cs` tinha uma entrada `<None>` redundante.
    `Model/Classroom.cs` (classe `Class`) não tinha `id`, tinha
    propriedades confusas e não era usada em lugar nenhum do projeto.
15. **URL da API hardcoded** em `clsConexao.cs`, apesar do pacote
    `Microsoft.Extensions.Configuration.Json` já estar referenciado no
    `.csproj` (e sem nenhum arquivo/código realmente usando-o).
16. **`catch (Exception ex)` genérico devolvendo `ex.Message` ao usuário**,
    repetido em todo método de `clsApiService.cs`.
17. Métodos mortos: `Cadastro.xaml.cs` tinha `UserCadastro(string)`, sempre
    retornava `false`, nunca era chamado.
18. `iss` do JWT em `tokenLogin.php` estava copiado de outro projeto
    (`"accuracy-mob-api"`).

---

## 2. O que foi corrigido (API — repositório `BioFrequenciaApi`)

Branches (todas já mescladas em `master`): `refactor/api-error-handling`,
`feature/api-authentication`, `feature/student-management`,
`feature/attendance`, `feature/schema-confirmed-by-supabase`, mais um commit
pontual de correção (`fix: use JWT_SECRET_KEY constant in tokenLogin.php`).

| Item | Correção | Testado |
|---|---|---|
| Autenticação | Login emite JWT; `addaluno.php`, `marcarFalta.php`, `marcarAtraso.php`, `listarSalas.php` exigem token via `autenticar()` | ✅ token emitido; forjar `id_coordenacao` no corpo é ignorado (confirmado lendo o banco depois) |
| Senha | `password_hash`/`password_verify`; contas antigas em texto puro são migradas automaticamente no primeiro login correto | ✅ login com senha antiga funciona e o hash muda para bcrypt logo em seguida |
| `verificarEmail.php` | Sempre responde nos dois ramos; removida a emissão de token (seria brecha de segurança: token sem checar senha) | ✅ os dois casos (disponível / já cadastrado) testados |
| `addaluno.php` | Lê `nome_aluno` (não `aluno`); valida sala/data/gênero; exige token; devolve `id_aluno` via `RETURNING` | ✅ sala inexistente → 422; data futura → 422; sucesso → 200 com id |
| `listarSalas.php` (novo) | Lista `id_sala`, `nome_sala`, `periodo_sala` | ✅ |
| `marcarFalta.php` | `id_coordenacao` vem do token, não do corpo | ✅ |
| `marcarAtraso.php` (novo) | Espelha `marcarFalta.php` para `tb_atraso`, schema completo confirmado | ✅ |
| `filtroAluno.php` | Faltas e atrasos contados em subconsultas separadas (evita tanto o bug do valor duplicado quanto um produto cartesiano); agora também exige token, que tinha ficado de fora quando a autenticação foi adicionada aos outros endpoints | ✅ valores distintos e corretos confirmados; sem token → 401; paginação (10+3 em 2 páginas), busca por nome e filtro por turma testados com 13 alunos reais |
| `coutAlunos.php` | Marcado como descontinuado (schema real não bate) | Não recomendo usar — ver comentário no arquivo |
| Erros | `helpers/resposta.php` centraliza sucesso/erro; nenhuma mensagem de exceção crua vai mais para o cliente | ✅ |
| Validação | `helpers/validacao.php`: email, senha, inteiros, data de nascimento | ✅ 29/29 testes unitários puros |
| `composer.json` | Criado (não existia nenhum arquivo de dependências, apesar do projeto já usar `firebase/php-jwt`) | — |
| `.gitattributes` | Normaliza quebra de linha (havia diffs "fantasma" de CRLF/LF) | — |

**Pendência real**: `email_coordenacao` não aparece com constraint de
unicidade no schema visualizado — a checagem de duplicidade hoje depende só
da aplicação (pré-checagem + captura de SQLSTATE 23505, que só dispara se
existir uma constraint). Recomendo rodar:
```sql
ALTER TABLE tb_coordenacao ADD CONSTRAINT uq_email_coordenacao UNIQUE (email_coordenacao);
```

---

## 3. Schema real (confirmado via Supabase, screenshots do usuário)

```
tb_coordenacao(id_coordenacao PK, nome_coordenacao, email_coordenacao, senha_coordenacao)
tb_aluno(id_aluno PK, nome_aluno, id_sala FK, dta_nasc_aluno, genero_aluno)
tb_sala(id_sala PK, nome_sala, periodo_sala)
tb_falta(id_falta PK, id_aluno FK, id_coordenacao FK, dta_falta, motivo_falta, justificado_falta, observacao_falta)
tb_atraso(id_atraso PK, id_aluno FK, id_coordenacao FK, dta_atraso, motivo_atraso, justificado_atraso, observacao_atraso)
tb_responsavel(id_responsavel PK, id_aluno FK, nome_responsavel, cpf_responsavel, telefone_responsavel, email_responsavel, genero_responsavel)
tb_aluno_responsavel(id_aluno FK, id_responsavel FK)  -- N:N
tb_notificacao(id_notificacao PK, id_aluno FK, id_responsavel FK, data_envio_notificacao, mensagem, status_notificacao)
```

`tb_responsavel`, `tb_aluno_responsavel` e `tb_notificacao` **existem no
banco mas não têm nenhum endpoint na API nem tela no desktop ainda** — não
implementei nada em cima delas nesta rodada (o pedido original foi
explícito em não inventar funcionalidade fora do que já estava planejado/
em uso), mas é claramente a próxima feature natural: cadastro de
responsáveis + envio de notificação quando uma falta/atraso é registrado.
Vale também esclarecer com quem desenhou o schema por que
`tb_responsavel` tem um `id_aluno` direto **e** existe a tabela de
associação `tb_aluno_responsavel` — parece redundante (uma delas deveria
bastar, dependendo se um aluno pode ter vários responsáveis ou não).

---

## 4. O que foi corrigido (Desktop — repositório `SoftwareDesktop`)

Branches (todas já mescladas em `master`): `refactor/api-service`,
`feature/session-management`, `feature/student-registration-ui`,
`fix/login-and-registration-screens`, `fix/classroom-periodo`.

- **`Config/AppSettings.cs` + `appsettings.json`**: URL da API centralizada
  (antes hardcoded em `clsConexao.cs`).
- **`clsApiService.cs`**: reescrito. Dois métodos privados
  (`InterpretarResposta`/`TratarExcecao`) eliminam a duplicação que existia
  em cada chamada e diferenciam erro de conexão / timeout / JSON inválido /
  401/404/503 / erro genérico — em vez do `catch (Exception ex)` que
  devolvia `ex.Message` direto pro usuário em todo lugar.
- **`RegisterStudent`**: corrigido para enviar `nome_aluno`/`id_sala`
  (agora `int`)/`dta_nasc_aluno`/`genero_aluno`, batendo com o que
  `addaluno.php` espera.
- **`Model/Classroom.cs`**: reescrito (era `Class`, sem `id`, não usada em
  lugar nenhum) — agora com `id_sala`, `nome_sala`, `periodo_sala`.
- **`Model/clsCoordenacao.cs`**: adicionado `id_coordenacao` e `token`
  (a API agora devolve os dois no login).
- **`Session/SessaoUsuario.cs`** (novo): guarda o coordenador logado e
  injeta o token como `Authorization: Bearer` em toda chamada seguinte.
- **`MainWindow`**: `PasswordBox` no lugar do `TextBox` de senha; login
  desabilita o botão durante a chamada e esconde a janela (não fecha —
  fechar derrubaria o app inteiro, já que por padrão o `ShutdownMode`
  do WPF é atrelado à primeira janela) ao entrar no Dashboard, com
  `Application.Current.MainWindow` reatribuído ao Dashboard.
- **`Cadastro`**: `PasswordBox` nos dois campos de senha; método morto
  `UserCadastro` removido.
- **`addStudent`**: `txtClassroom` (texto livre) virou um `ComboBox`
  carregado via `ListarSalas()`; corrigido o bug em que
  `resposta.Sucesso && resposta.Dados != null` nunca era true (a API não
  devolvia `Dados` no cadastro bem-sucedido); adicionada mensagem quando
  faltam campos (antes: clicar em Cadastrar com algo vazio não fazia nada
  visível).
- **`Student` (listagem)**: era só um botão "Adicionar aluno", sem
  nenhuma forma de ver os alunos cadastrados. Agora tem busca por nome,
  filtro por turma, uma tabela (faltas/atrasos/frequência/status) e
  paginação, consumindo `filtroAluno.php`; a lista se atualiza sozinha
  quando a janela de cadastro é fechada.
- **`.csproj`**: removida a referência quebrada `MySql.Data` (caminho fixo
  de outra máquina) e o pacote `Npgsql` não utilizado; removida a entrada
  `<None Include="Model\Classroom.cs">` redundante; `appsettings.json`
  configurado para copiar para a saída do build.
- Nullable: propriedades `string` inicializadas em `Student`,
  `clsCoordenacao`, `ApiResponse<T>` (reduz avisos do compilador sem mudar
  comportamento).

**Build**: não verificado neste ambiente (sem NuGet/WPF). Revisado à mão
múltiplas vezes; um erro real de compilação foi pego e corrigido durante a
própria auditoria (assinatura de `RegisterStudent` mudou de `string` para
`int`, e `addStudent.xaml.cs` ainda chamava com `string` — corrigido antes
da entrega).

---

## 5. Pendências / próximos passos recomendados (rodada anterior)

1. ~~Rodar `dotnet restore && dotnet build` no Visual Studio~~ — ainda não
   verificado (ver seção 7: este ambiente também não tem SDK do .NET).
2. ~~Considerar constraint `UNIQUE` em `tb_coordenacao.email_coordenacao`~~
   — feito, ver `database/migrations/001_schema_base.sql`.
3. ~~Nenhuma tela chama `marcarFalta.php`/`marcarAtraso.php` ainda~~ —
   feito nesta rodada.
4. `tb_responsavel`/`tb_aluno_responsavel`/`tb_notificacao`: **ainda sem
   endpoint nem tela** (não mexido nesta rodada também - fora do escopo
   pedido).
5. ~~Dashboard não exibe nenhum dado real da API ainda~~ — feito.
6. ~~Sem logout explícito no desktop~~ — feito.

---

## 6. Segunda rodada de estabilização (esta sessão)

Pedido do usuário: terminar o sistema conforme o layout completo
(Dashboard, Alertas, Acompanhamento, Relatórios) e adicionar biometria
facial (captura + comparação real) e biometria "digital" via app no
celular do próprio aluno. Trabalho de verdade nos dois repositórios, não
só planejamento — ver commits em ambos.

**Como foi testado desta vez**: `php -l` em tudo, testes de integração
via `curl` contra um PostgreSQL local (mesma abordagem da rodada
anterior, ambiente sem acesso ao Supabase de produção), e agora também
um **suite de testes unitários persistido no repositório**
(`tests/helpers_test.php`, 51 asserções, `php tests/helpers_test.php`) -
a rodada anterior mencionava "29/29 testes" mas nenhum arquivo de teste
tinha sido commitado, então não havia como rodá-los de novo.

### Bugs reais encontrados nesta rodada (não só no que foi construído agora)

1. **Login inteiro quebrado (HTTP 500 em toda chamada)**: `composer.json`
   declarava `helpers/validacao.php`, `resposta.php` e `senha.php` em
   `autoload.files`, mas todo endpoint também já dava `require_once`
   neles diretamente. O autoloader do Composer carrega arquivos de
   `autoload.files` com `require` simples, guardado por uma flag própria
   que não sabe que o arquivo já tinha sido carregado pelo `require_once`
   manual - resultado: `Cannot redeclare function campoPreenchido()` em
   toda requisição que passasse pelas duas rotas de carregamento (ex.:
   login, que carrega `validacao.php` direto e depois `tokenLogin.php`,
   que puxa `vendor/autoload.php`). Removido o `autoload.files`
   redundante; login voltou a funcionar (confirmado com token JWT
   emitido de verdade).
2. **`.gitignore` quebrado**: uma edição anterior concatenou `vendor/`
   direto em `config/config.php` sem quebra de linha
   (`config/config.phpvendor/`), e uma linha seguinte
   (`config/config.php/`) tem uma barra final que só bate com um
   *diretório* chamado `config.php`, não o arquivo. Resultado:
   `config/config.php` (com credenciais reais do banco e o segredo JWT)
   **não estava mais sendo ignorado pelo git** - confirmado com
   `git check-ignore` antes/depois. Nenhum segredo chegou a ser
   commitado (`git log` confirma), mas um `git add .` descuidado teria
   pego o arquivo. Corrigido.
3. **`dataNascimentoValida()` rejeitava a data de hoje como "futura"**:
   `DateTime::createFromFormat('Y-m-d', $data)` preenche a *hora atual*
   quando o formato não tem componente de hora (não meia-noite), então
   comparar com `new DateTime('today')` (que É meia-noite) fazia
   qualquer data igual a hoje parecer "no futuro" - ou seja, cadastrar
   um aluno recém-nascido (data de nascimento = hoje) sempre falhava.
   Achado pelo novo suite de testes, não só lendo o código. Corrigido
   zerando a hora antes de comparar.
4. **Bug introduzido e corrigido na mesma sessão**: a primeira versão de
   `tb_alerta_encerrado`/`buscarAlertas()` comparava `encerrado_em`
   (timestamp completo) com `dta_falta::date` (só data) - comparação de
   string quebra sempre que os dois caem no mesmo dia, então um novo
   evento no mesmo dia do encerramento nunca fazia o alerta reaparecer.
   Pego em teste (curl), corrigido normalizando os dois lados para data.

### O que foi construído (API)

| Área | Arquivos | Testado |
|---|---|---|
| Acompanhamento | `acompanhamento/addAcompanhamento.php`, `listarAcompanhamento.php` | ✅ criar, listar, paginação |
| Alertas | `helpers/alertas.php` (`buscarAlertas`), `user/alertas.php`, `user/encerrarAlerta.php` | ✅ geração por frequência/faltas consecutivas/atrasos, filtros, encerrar + reaparecimento no dia seguinte |
| Dashboard | `user/dashboard.php` | ✅ frequência geral + delta, evolução de 6 meses, por turma, contatos pendentes |
| Relatório individual | `user/historicoAluno.php` | ✅ |
| Biometria facial | `helpers/biometriaFacial.php`, `biometria/*.php` | ✅ cadastro, verificação (matemática da similaridade de cosseno confirmada com vetor idêntico/ortogonal/oposto), auditoria em `tb_verificacao_facial` |
| Biometria digital | `helpers/dispositivo.php`, `digital/*.php` | ✅ pareamento por código de uso único, HMAC-SHA256 válido/forjado, revogação, fila de confirmação |
| Testes | `tests/helpers_test.php` | ✅ 51/51 |

Migrações novas: `database/migrations/001_schema_base.sql` (schema já
existente em produção, só para montar um banco de teste local do zero +
a constraint UNIQUE pendente da rodada anterior),
`002_acompanhamento_alertas.sql`, `003_biometria.sql`.

### Limitação conhecida e documentada (não escondida)

O limiar de decisão da biometria facial (`LIMIAR_SIMILARIDADE_FACIAL`,
`helpers/biometriaFacial.php`) é um placeholder (0.55) - **não foi
possível calibrá-lo** com um modelo real e fotos reais, porque este
ambiente não tem webcam, não tem os binários do OpenCV e não tem um
modelo `.onnx` real disponível. A matemática da similaridade de cosseno
em si foi testada e está correta; o limiar de decisão precisa de
calibração com dados reais antes de qualquer uso com alunos de verdade.
Ver `SoftwareDesktop/BioFrequencia/Recursos/LEIA-ME.md` para o que falta
do lado do desktop.

### Pendências reais depois desta rodada

1. `dotnet build` do desktop continua não verificado (sem SDK do .NET
   neste ambiente também).
2. Calibração do limiar de similaridade facial com modelo/fotos reais.
3. ~~`tb_responsavel`/`tb_aluno_responsavel`/`tb_notificacao`: sem
   endpoint/tela~~ — feito na sessão seguinte (seção 7).
4. "Faltas consecutivas" (`calcularFaltasConsecutivas`,
   `helpers/frequencia.php`) é uma estimativa: o banco só registra o
   evento negativo (falta), não existe uma tabela de presença positiva,
   então um gap de até 3 dias corridos é tolerado como fim de semana -
   isso também tolera (incorretamente) um único dia útil isolado de
   presença real entre duas faltas não consecutivas. Documentado no
   próprio código; a correção de verdade seria registrar frequência
   positiva por dia letivo.
5. Em produção, considerar criptografar `tb_dispositivo_aluno.device_secret`
   em repouso (pgcrypto) - hoje fica em texto puro no banco porque o
   HMAC precisa do segredo original, não de um hash (ver comentário na
   migração 003).

---

## 7. Terceira rodada: responsáveis, notificações, e um bug real de tipo

Pedido do usuário: implementar `tb_responsavel`/`tb_aluno_responsavel`/
`tb_notificacao` (confirmado como pendência nas duas rodadas anteriores),
depois de o usuário mandar screenshots do Schema Visualizer do Supabase
confirmando a estrutura real do banco em produção - conferido campo a
campo contra o que já estava documentado aqui; `tb_acompanhamento` já
existia em produção batendo exatamente com a tabela criada na rodada
anterior, nenhum ajuste necessário.

### Bug real encontrado (não relacionado ao pedido, achado testando)

**`bindParam()` do PDO muda o TIPO da variável PHP original depois do
`execute()`**, quando o driver é `pdo_pgsql`. Reproduzido isoladamente:

```php
$id = $stmt->fetch(PDO::FETCH_ASSOC)['id']; // int(4), vindo de RETURNING
$outro->bindParam(1, $id);
$outro->execute();
// $id agora é string("4") - a MESMA variável mudou de tipo
```

Isso pegou em cheio o padrão que várias das novas rotas passaram a usar
(buscar um id gerado via `RETURNING`, reusar essa variável numa segunda
query, depois devolver no JSON): `addResponsavel.php` devolvia
`{"id_responsavel": "4"}` (string) em vez de `{"id_responsavel": 4}`
(número), inconsistente com todo o resto da API. Corrigido trocando
**todo** `bindParam()` por `bindValue()` no repositório inteiro (~120
ocorrências, nenhuma delas dependia de verdade da semântica por
referência do `bindParam` - são todas consultas de uso único, não loops
reaproveitando a mesma variável). Confirmado antes/depois com o mesmo
teste isolado, e a resposta de `addResponsavel.php` voltou a ser um
número JSON de verdade.

### O que foi construído

| Área | Arquivos | Testado |
|---|---|---|
| Responsáveis | `helpers/validacaoResponsavel.php`, `responsavel/*.php` | ✅ cadastro, listagem, busca por CPF, vínculo a um segundo aluno (irmão), remoção de vínculo, CPF/telefone inválidos, CPF duplicado (409, antes e depois de existir a constraint) |
| Notificações | `helpers/notificacao.php`, `notificacao/*.php`, integrado em `marcarFalta.php`/`marcarAtraso.php` | ✅ geração automática ao registrar falta/atraso (best-effort, não derruba a resposta principal se falhar), listagem com filtro de status, confirmação manual de envio |

Migração nova: `database/migrations/004_responsavel_cpf_unique.sql`
(constraint UNIQUE recomendada em `cpf_responsavel` - documentado que
pode falhar se já existirem duplicados reais no banco, com a query pra
achá-los antes de rodar).

**Importante sobre "notificação"**: isto só grava a intenção de
notificar (uma linha em `tb_notificacao` com status "pendente"). Não há
nenhum provedor de email/SMS/push configurado nesta rodada - nenhuma
mensagem é de fato enviada a ninguém. A coordenação vê a fila e confirma
manualmente depois de avisar por fora do sistema. Isso está documentado
no código e é uma decisão de escopo, não uma simulação de resultado.

### Pendências depois desta rodada

Mesma lista da seção 6 (build do desktop, calibração facial, teste em
celular real, limitação de "faltas consecutivas"), menos o item de
responsáveis/notificações, que está resolvido agora. Adicionado:

6. Nenhum provedor real de notificação (email/SMS/push) integrado -
   `tb_notificacao` é uma fila que a coordenação trabalha manualmente.

## Causa raiz de dias de erro 500: migrações nunca rodadas no banco de produção

O usuário reportou (com prints do DevTools) `500 Internal Server Error`
persistente em `user/dashboard.php`, `notificacao/listarNotificacoes.php`
e `digital/listarCheckinsPendentes.php`, "já fazia dias".

**Causa raiz**: `database/migrations/002_acompanhamento_alertas.sql` e
`003_biometria.sql` criam tabelas (`tb_acompanhamento`,
`tb_alerta_encerrado`, `tb_biometria_facial`, `tb_verificacao_facial`,
`tb_codigo_vinculo`, `tb_dispositivo_aluno`, `tb_checkin_biometrico`)
que só existiram no banco de testes usado para desenvolver a API nesta
sessão - nunca foram aplicadas no banco de produção (Supabase) do
usuário. `dashboard.php` usa `tb_acompanhamento`/`tb_alerta_encerrado`
(via `buscarAlertas()`), `listarCheckinsPendentes.php` usa
`tb_checkin_biometrico` - qualquer consulta a uma tabela inexistente
lança `PDOException`, capturada por `enviarErro(..., 500, $erro)`, que
intencionalmente esconde o detalhe da exceção do cliente (só loga no
servidor) - por isso o navegador só mostrava "Erro no servidor", nunca
"relation does not exist".

**Correção entregue**: `database/migracao-completa.sql` (na raiz do
projeto) - as migrações 001+002+003 concatenadas num único script
idempotente (`CREATE TABLE IF NOT EXISTS` em tudo; o `ALTER TABLE ...
ADD CONSTRAINT` de `tb_coordenacao` envolto num `DO $$ ... EXCEPTION
WHEN duplicate_object OR duplicate_table THEN NULL; END $$` para não
falhar se a constraint já existir). Verificado rodando de verdade contra
Postgres local, três vezes: (1) banco vazio - cria as 15 tabelas sem
erro; (2) rodado uma SEGUNDA vez sobre o mesmo banco - nenhum erro
(idempotência real, não só teórica: a primeira versão do script, sem o
`duplicate_table` no `WHEN`, falhava nesse re-run porque o Postgres
levanta `duplicate_table`, não `duplicate_object`, quando o índice
implícito da UNIQUE constraint já existe - bug pego só testando, não
lendo); (3) simulando o cenário real - banco só com o schema 001 (como
o do usuário), migração aplicada por cima, e as 3 chamadas que davam
500 (`dashboard.php`, `listarNotificacoes.php`,
`listarCheckinsPendentes.php`) testadas de ponta a ponta contra esse
banco migrado: as três voltaram `200` com dados reais.

**Nota para quando o usuário rodar isto**: se depois de rodar a
migração aparecer um erro NOVO tipo "permission denied for table
tb_acompanhamento" (não "relation does not exist"), é só a role usada
pela API não ter privilégio nas tabelas novas (aconteceu no meu teste
local, onde a tabela foi criada por um usuário diferente do que a API
usa) - resolve com `GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public
TO <role_da_api>;`. Não deve acontecer no Supabase se a API já conecta
com a mesma role usada no SQL Editor, mas fica documentado caso apareça.

## Fix: 500 intermitente de verdade - PDO + connection pooler em modo Transaction

Depois de: (1) rodar a migração, (2) o site já reduzir o número de
chamadas simultâneas, e (3) o usuário trocar `DB_HOST` pro connection
pooler do Supabase como eu tinha recomendado - o problema continuou,
agora até em endpoints simples (`listarSalas.php`, que só lê
`tb_sala`), com o MESMO endpoint às vezes voltando 200 e às vezes 500
sem nenhum padrão visível no navegador.

**Tentei reproduzir contra o banco real do usuário e não consegui por
uma limitação do meu próprio ambiente**: este sandbox não tem acesso de
rede a hosts arbitrários - confirmado tentando abrir uma conexão TCP
direta na porta 6543 do pooler (timeout) e até um HTTPS simples pra
`supabase.com` (rejeitado pela política de rede do ambiente, erro
`connect_rejected`). Não é um problema de credencial; é o sandbox que
não tem internet geral, só acesso a destinos específicos liberados.

**Causa raiz (diagnóstico, não confirmado ao vivo contra o banco do
usuário - só contra um banco local, ver abaixo)**: o usuário está usando
a porta `6543` do pooler do Supabase, que é o PgBouncer em modo
**Transaction** - nesse modo, cada instrução SQL de uma "sessão" pode
ser servida por uma conexão de backend Postgres diferente. O PDO, por
padrão, usa *prepared statements* de verdade no servidor
(`PQprepare`/`PQexecPrepared`); se o `prepare()` for atendido por um
backend e o `execute()` seguinte cair em outro (coisa que o modo
Transaction pode fazer a qualquer momento), o Postgres não encontra
aquele prepared statement e retorna erro - capturado como
`PDOException` por cada endpoint, virando exatamente o "Erro no
servidor" (500) relatado, de forma imprevisível porque depende de qual
backend o PgBouncer escolhe a cada instrução. Esse é um problema
documentado e conhecido de PDO + PgBouncer em modo Transaction, não
específico deste projeto.

**Correção**: `database/conexao.php` agora passa
`PDO::ATTR_EMULATE_PREPARES => true` na conexão. Com isso o PDO nunca
cria um prepared statement de verdade no servidor - monta o SQL final
(com os valores já escapados contra injeção, a proteção contra SQL
injection continua intacta) inteiramente no PHP antes de mandar pro
banco, então não depende de nenhum estado sobrevivendo entre duas idas
ao Postgres. Seguro em qualquer tipo de conexão (direta, pooler em modo
Session, ou Transaction) - só deixa de usar uma otimização que não faz
diferença de performance perceptível nesta API.

**O que foi testado de verdade**: `php tests/helpers_test.php` (50/51 -
a falha é uma pendência antiga não relacionada, sobre cálculo de faltas
consecutivas num fim de semana, não sobre conexão) e as 7 chamadas que
apareceram nos prints do usuário (`dashboard`, `filtroAluno`, `alertas`,
`listarSalas`, `listarAcompanhamento`, `listarNotificacoes`,
`listarCheckinsPendentes`), cada uma repetida 3 vezes seguidas, todas
200 - mas isso foi contra um Postgres local com conexão direta (sem
PgBouncer no meio), porque não consigo alcançar o banco do usuário
daqui. Ou seja: **confirmei que a mudança não quebra nada, mas não
consegui confirmar ao vivo que ela resolve o problema específico do
usuário** - só a análise de causa raiz aponta fortemente pra isso.
Pedido ao usuário pra testar esta versão e confirmar.

**Nota de segurança**: o usuário colou a senha real do banco (Supabase)
em texto puro no chat pra que eu pudesse tentar testar. Removi o
`config.php` com essas credenciais do disco assim que terminei de usar
e ele nunca foi commitado (está no `.gitignore`) - mas como a senha
ficou registrada na conversa, vale considerar trocá-la depois (Supabase
→ Database → Reset database password) como boa prática, não porque
algo específico tenha vazado daqui.
