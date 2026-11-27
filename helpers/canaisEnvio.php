<?php
require_once __DIR__ . '/criptografia.php';
require_once __DIR__ . '/provedores.php';
require_once __DIR__ . '/contatoResponsavel.php';
require_once __DIR__ . '/validacao.php';

/**
 * Canais de envio de cada escola (migração 015): o e-mail e o número de
 * WhatsApp que a PRÓPRIA escola cadastra para os avisos aos responsáveis.
 *
 *   e-mail    Brevo (só um remetente verificado; serve um Gmail da escola)
 *             ou Resend (domínio próprio verificado). Nada de SMTP: o plano
 *             gratuito do Render bloqueia as portas 25, 465 e 587.
 *   WhatsApp  Cloud API da Meta: o ID do número, um token permanente e um
 *             modelo aprovado (a escola sempre começa a conversa).
 *
 * Regras:
 *  - um canal de cada tipo por escola; um e-mail remetente, um número e um
 *    ID de número da Meta só podem pertencer a uma escola (índices únicos
 *    no banco e conferência antes de gravar, com mensagem clara);
 *  - a chave/token é cifrada (chaveDosCanaisDeEnvio), com a escola e o canal
 *    como dado autenticado: copiada para outra escola, deixa de abrir. Nunca
 *    volta para a tela - só o final dela;
 *  - ao salvar, o provedor confere a configuração sem mandar mensagem
 *    (Brevo: o remetente está ativo na conta; Resend: o domínio está
 *    verificado; Meta: o ID é desse número) e a tela tem um envio de teste;
 *  - sem canal da escola, vale o do sistema (EMAIL_*, WHATSAPP_*) e, sem
 *    ele, a mensagem fica pendente (no WhatsApp, com o link wa.me).
 *
 * PROVEDORES_FALSOS_URL troca o endereço dos provedores pelo servidor falso
 * dos testes (tests/mock_provedores.php). Só nos testes.
 */

const CANAIS_DE_ENVIO = ['email', 'whatsapp'];
const PROVEDORES_DO_CANAL = ['email' => ['brevo', 'resend'], 'whatsapp' => ['meta']];
const VERSAO_API_META = 'v23.0';
const IDIOMA_MODELO_PADRAO = 'pt_BR';
/** Intervalo mínimo entre dois envios de teste da mesma escola e canal. */
const ESPERA_ENTRE_TESTES_SEGUNDOS = 15;

/** E-mails gratuitos: o Resend não verifica domínio de terceiros; a Brevo aceita. */
const DOMINIOS_DE_EMAIL_GRATUITO = [
    'gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com',
    'yahoo.com', 'yahoo.com.br', 'icloud.com', 'bol.com.br', 'uol.com.br', 'terra.com.br',
];

/**
 * A chave/token aberto de um canal. Embrulhado para nunca sair numa
 * resposta ou num log por engano: json_encode, var_dump e print_r mostram
 * só "••••"; o valor só sai por valor().
 */
final class Credencial implements JsonSerializable {
    public function __construct(private readonly string $segredo) {}

    public function valor(): string {
        return $this->segredo;
    }

    public function jsonSerialize(): string {
        return '••••';
    }

    public function __debugInfo(): array {
        return ['valor' => '••••'];
    }
}

/** Endereço de um provedor ('brevo', 'resend' ou 'meta') + caminho. */
function urlDoProvedor(string $provedor, string $caminho): string {
    $falso = trim((string) getenv('PROVEDORES_FALSOS_URL'));
    if ($falso !== '') {
        return rtrim($falso, '/') . '/' . $provedor . $caminho;
    }
    return match ($provedor) {
        'brevo'  => 'https://api.brevo.com' . $caminho,
        'resend' => 'https://api.resend.com' . $caminho,
        'meta'   => 'https://graph.facebook.com' . $caminho,
    };
}

function contextoDaCredencial(int $idEscola, string $canal): string {
    return "canal-envio:$idEscola:$canal";
}

/** Texto sem quebras de linha nem < > " (vai no cabeçalho From do e-mail). Função pura. */
function nomeDeRemetenteLimpo(?string $nome): ?string {
    $limpo = trim((string) preg_replace('/[\x00-\x1F\x7F<>"]+/u', ' ', (string) $nome));
    $limpo = (string) preg_replace('/\s+/u', ' ', $limpo);
    return $limpo === '' ? null : mb_substr($limpo, 0, 100);
}

/** '"E.E. Maria" <sec@escola.br>' (o nome entre aspas quando tem pontuação). Função pura. */
function remetenteFormatado(?string $nome, string $email): string {
    $nome = nomeDeRemetenteLimpo($nome);
    if ($nome === null) {
        return $email;
    }
    return (preg_match('/[()\[\]:;@\\\\,.]/u', $nome) ? "\"$nome\"" : $nome) . " <$email>";
}

/**
 * Confere a chave colada na tela e explica o engano mais comum de cada
 * provedor. null quando serve. Função pura.
 */
function problemaNaCredencial(string $provedor, string $credencial): ?string {
    if ($credencial === '' || preg_match('/\s/u', $credencial)) {
        return 'A chave não pode ter espaços nem quebras de linha. Copie de novo do painel do provedor.';
    }
    if ($provedor === 'brevo') {
        if (str_starts_with($credencial, 'xsmtpsib-')) {
            return 'Essa é a chave SMTP da Brevo. Use a chave de API (começa com xkeysib-), em SMTP & API › API Keys.';
        }
        if (!str_starts_with($credencial, 'xkeysib-')) {
            return 'A chave de API da Brevo começa com xkeysib- (SMTP & API › API Keys).';
        }
    }
    if ($provedor === 'resend' && !str_starts_with($credencial, 're_')) {
        return 'A chave de API do Resend começa com re_ (API Keys, no painel do Resend).';
    }
    if (mb_strlen($credencial) < 20 || mb_strlen($credencial) > 1000) {
        return 'A chave parece incompleta. Copie de novo do painel do provedor.';
    }
    return null;
}

/**
 * Lê e confere o canal enviado pela tela (função pura).
 *
 *   e-mail:   { provedor: brevo|resend, remetente, nome_remetente?, credencial? }
 *   WhatsApp: { numero, id_numero, modelo, idioma_modelo?, credencial? }
 *
 * $atual: o canal já gravado (sem a credencial) ou null. A credencial em
 * branco mantém a gravada - só se o provedor for o mesmo.
 *
 * @return array{0: ?array, 1: ?string} [canal pronto para gravar ('credencial' null = manter a atual), erro]
 */
function lerCanalDeEnvio(array $dados, string $canal, ?array $atual): array {
    if (!in_array($canal, CANAIS_DE_ENVIO, true)) {
        return [null, 'Canal inválido: use email ou whatsapp.'];
    }
    $texto = fn(string $campo) => is_string($dados[$campo] ?? null) ? trim($dados[$campo]) : '';
    $provedor = $canal === 'whatsapp' ? 'meta' : mb_strtolower($texto('provedor'));
    if (!in_array($provedor, PROVEDORES_DO_CANAL[$canal], true)) {
        return [null, 'Escolha o provedor do e-mail: Brevo ou Resend.'];
    }
    $credencial = $texto('credencial');
    $mesmoProvedor = $atual !== null && ($atual['provedor'] ?? null) === $provedor;
    if ($credencial === '') {
        if (!$mesmoProvedor) {
            return [null, $canal === 'email' ? 'Informe a chave de API do provedor de e-mail.' : 'Informe o token de acesso do WhatsApp (Meta).'];
        }
        $credencial = null;
    } elseif (($problema = problemaNaCredencial($provedor, $credencial)) !== null) {
        return [null, $problema];
    }

    if ($canal === 'email') {
        $remetente = mb_strtolower($texto('remetente'));
        if (!emailValido($remetente) || mb_strlen($remetente) > 150) {
            return [null, 'Informe o e-mail que vai enviar os avisos (ex.: secretaria@escola.sp.gov.br).'];
        }
        $dominio = substr((string) strrchr($remetente, '@'), 1);
        if ($provedor === 'resend' && in_array($dominio, DOMINIOS_DE_EMAIL_GRATUITO, true)) {
            return [null, "O Resend só envia de um domínio próprio verificado. Para um e-mail $dominio, use a Brevo."];
        }
        return [[
            'canal' => 'email', 'provedor' => $provedor, 'remetente' => $remetente,
            'nome_remetente' => nomeDeRemetenteLimpo($texto('nome_remetente')),
            'id_numero' => null, 'modelo' => null, 'idioma_modelo' => null, 'credencial' => $credencial,
        ], null];
    }

    $digitos = preg_replace('/\D/', '', $texto('numero')) ?? '';
    $numero = strlen($digitos) >= 10 && strlen($digitos) <= 11 ? '55' . $digitos : $digitos;
    if (!preg_match('/^55[1-9]{2}\d{8,9}$/', $numero)) {
        return [null, 'Informe o número do WhatsApp da escola com DDD, ex.: (11) 99999-1111.'];
    }
    $idNumero = preg_replace('/\D/', '', $texto('id_numero')) ?? '';
    if (strlen($idNumero) < 10 || strlen($idNumero) > 20) {
        return [null, 'Informe o ID do número de telefone (Meta › WhatsApp › Configuração da API). Não é o telefone.'];
    }
    $modelo = $texto('modelo');
    if (!preg_match('/^[a-z0-9_]{1,100}$/', $modelo)) {
        return [null, 'Informe o nome do modelo aprovado na Meta, como aparece lá (letras minúsculas, números e _).'];
    }
    $idioma = $texto('idioma_modelo') === '' ? IDIOMA_MODELO_PADRAO : $texto('idioma_modelo');
    if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $idioma)) {
        return [null, 'Idioma do modelo inválido (ex.: pt_BR).'];
    }
    return [[
        'canal' => 'whatsapp', 'provedor' => 'meta', 'remetente' => $numero, 'nome_remetente' => null,
        'id_numero' => $idNumero, 'modelo' => $modelo, 'idioma_modelo' => $idioma, 'credencial' => $credencial,
    ], null];
}

/** "••••1234": o final da chave, para a tela reconhecer qual está salva. Função pura. */
function finalDaCredencial(string $credencial): string {
    return mb_substr($credencial, -4);
}

// ---------- conferência com o provedor (sem mandar mensagem) ----------

/**
 * Brevo, GET /v3/senders. Função pura.
 * @return array{situacao: string, texto: string} situacao: ok | recusado | inconclusivo
 */
function interpretarRemetentesBrevo(int $status, ?string $resposta, string $remetente): array {
    if ($resposta === null || $status === 0 || $status >= 500) {
        return ['situacao' => 'inconclusivo', 'texto' => 'A Brevo não respondeu agora. A configuração foi guardada; use o envio de teste daqui a pouco.'];
    }
    if ($status === 401 || $status === 403) {
        return ['situacao' => 'recusado', 'texto' => 'A Brevo recusou a chave de API: confira se copiou a chave inteira (xkeysib-...) e se ela está ativa.'];
    }
    if ($status < 200 || $status >= 300) {
        return ['situacao' => 'inconclusivo', 'texto' => "A Brevo recusou a consulta (HTTP $status). Use o envio de teste."];
    }
    foreach ((array) (json_decode($resposta, true)['senders'] ?? []) as $item) {
        if (mb_strtolower((string) ($item['email'] ?? '')) === $remetente) {
            return ($item['active'] ?? false) === true
                ? ['situacao' => 'ok', 'texto' => "A Brevo confirmou: $remetente está verificado e pode enviar."]
                : ['situacao' => 'recusado', 'texto' => "$remetente está na Brevo, mas ainda não foi confirmado. Abra o e-mail que a Brevo mandou para esse endereço e confirme."];
        }
    }
    return ['situacao' => 'recusado', 'texto' => "$remetente não está entre os remetentes da sua conta da Brevo. Cadastre em Senders, Domains & Dedicated IPs › Senders e confirme pelo e-mail que chega nele."];
}

/** Resend, GET /domains. Função pura. */
function interpretarDominiosResend(int $status, ?string $resposta, string $remetente): array {
    $dominio = substr((string) strrchr($remetente, '@'), 1);
    if ($resposta === null || $status === 0 || $status >= 500) {
        return ['situacao' => 'inconclusivo', 'texto' => 'O Resend não respondeu agora. A configuração foi guardada; use o envio de teste daqui a pouco.'];
    }
    if ($status === 401 || $status === 403) {
        $corpo = json_decode($resposta, true);
        if (is_array($corpo) && ($corpo['name'] ?? '') === 'restricted_api_key') {
            return ['situacao' => 'inconclusivo', 'texto' => "A chave do Resend é só de envio e não deixa conferir o domínio $dominio. A configuração foi guardada: confira com o envio de teste."];
        }
        return ['situacao' => 'recusado', 'texto' => 'O Resend recusou a chave de API: confira se copiou a chave inteira (re_...).'];
    }
    if ($status < 200 || $status >= 300) {
        return ['situacao' => 'inconclusivo', 'texto' => "O Resend recusou a consulta (HTTP $status). Use o envio de teste."];
    }
    foreach ((array) (json_decode($resposta, true)['data'] ?? []) as $item) {
        if (mb_strtolower((string) ($item['name'] ?? '')) === $dominio) {
            return ($item['status'] ?? '') === 'verified'
                ? ['situacao' => 'ok', 'texto' => "O Resend confirmou: o domínio $dominio está verificado."]
                : ['situacao' => 'recusado', 'texto' => "O domínio $dominio está no Resend, mas ainda não foi verificado (crie os registros SPF e DKIM no DNS)."];
        }
    }
    return ['situacao' => 'recusado', 'texto' => "O domínio $dominio não está cadastrado no Resend. Cadastre em Domains e verifique antes de usar."];
}

/** Meta, GET /{id do número}?fields=display_phone_number. Função pura. */
function interpretarNumeroMeta(int $status, ?string $resposta, string $numero): array {
    if ($resposta === null || $status === 0 || $status >= 500) {
        return ['situacao' => 'inconclusivo', 'texto' => 'A Meta não respondeu agora. A configuração foi guardada; use o envio de teste daqui a pouco.'];
    }
    $corpo = json_decode($resposta, true);
    if ($status < 200 || $status >= 300) {
        $codigo = (int) ($corpo['error']['code'] ?? 0);
        return ['situacao' => 'recusado', 'texto' => match (true) {
            $codigo === 190 => 'A Meta recusou o token: ele é inválido ou venceu. Gere um token permanente (usuário do sistema) e cole de novo.',
            $codigo === 100 || $status === 404 => 'A Meta não achou esse ID de número com este token. Confira o ID em WhatsApp › Configuração da API.',
            default => "A Meta recusou a consulta (HTTP $status).",
        }];
    }
    $mostrado = preg_replace('/\D/', '', (string) ($corpo['display_phone_number'] ?? '')) ?? '';
    if ($mostrado === '') {
        return ['situacao' => 'inconclusivo', 'texto' => 'A Meta respondeu sem o número. Confira com o envio de teste.'];
    }
    if ($mostrado !== $numero) {
        return ['situacao' => 'recusado', 'texto' => 'Esse ID é de outro número na Meta (termina em ' . substr($mostrado, -4) . '). Confira o número ou o ID.'];
    }
    $nome = trim((string) ($corpo['verified_name'] ?? ''));
    return ['situacao' => 'ok', 'texto' => 'A Meta confirmou o número' . ($nome !== '' ? " ($nome)" : '') . '.'];
}

/** GET com timeout curto; [status, corpo ou null]. */
function consultarProvedor(string $url, array $cabecalhos): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $cabecalhos),
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $resposta === false ? null : (string) $resposta];
}

/** Confere o canal com o provedor (só leitura). $credencial: a chave em texto. */
function conferirCanalNoProvedor(array $canal, string $credencial): array {
    if ($canal['provedor'] === 'brevo') {
        [$status, $resposta] = consultarProvedor(urlDoProvedor('brevo', '/v3/senders'), ['api-key: ' . $credencial]);
        return interpretarRemetentesBrevo($status, $resposta, $canal['remetente']);
    }
    if ($canal['provedor'] === 'resend') {
        [$status, $resposta] = consultarProvedor(urlDoProvedor('resend', '/domains'), ['Authorization: Bearer ' . $credencial]);
        return interpretarDominiosResend($status, $resposta, $canal['remetente']);
    }
    $url = urlDoProvedor('meta', '/' . VERSAO_API_META . '/' . rawurlencode($canal['id_numero']) . '?fields=display_phone_number,verified_name');
    [$status, $resposta] = consultarProvedor($url, ['Authorization: Bearer ' . $credencial]);
    return interpretarNumeroMeta($status, $resposta, $canal['remetente']);
}

// ---------- banco ----------

/** O canal gravado, sem abrir a credencial (para a tela e para conferir o que muda). */
function canalGravado(PDO $conexao, int $idEscola, string $canal): ?array {
    $stmt = $conexao->prepare("
        SELECT c.canal, c.provedor, c.remetente, c.nome_remetente, c.id_numero, c.modelo, c.idioma_modelo,
               c.credencial_final, c.verificado_em, c.ultimo_teste_em, c.ultimo_erro, c.atualizado_em,
               p.nome_coordenacao AS atualizado_por
        FROM tb_canal_envio c
        LEFT JOIN tb_coordenacao p ON p.id_coordenacao = c.atualizado_por
        WHERE c.id_escola = ? AND c.canal = ?
    ");
    $stmt->execute([$idEscola, $canal]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    return $linha === false ? null : $linha;
}

/** Outra escola já usa este remetente (ou este ID de número)? */
function canalDeOutraEscola(PDO $conexao, int $idEscola, array $canal): bool {
    $stmt = $conexao->prepare("
        SELECT 1 FROM tb_canal_envio
        WHERE id_escola <> ? AND ((canal = ? AND lower(remetente) = lower(?)) OR (?::text IS NOT NULL AND id_numero = ?))
        LIMIT 1
    ");
    $stmt->execute([$idEscola, $canal['canal'], $canal['remetente'], $canal['id_numero'], $canal['id_numero']]);
    return $stmt->fetchColumn() !== false;
}

/** A credencial em texto, ou null se ela não abre (BIOMETRIA_CHAVE ausente ou trocada). */
function credencialDoCanal(PDO $conexao, int $idEscola, string $canal): ?string {
    $chave = chaveDosCanaisDeEnvio();
    if ($chave === null) {
        return null;
    }
    $stmt = $conexao->prepare("SELECT credencial_cifrada FROM tb_canal_envio WHERE id_escola = ? AND canal = ?");
    $stmt->execute([$idEscola, $canal]);
    $guardada = $stmt->fetchColumn();
    return $guardada === false ? null : decifrarDado((string) $guardada, $chave, contextoDaCredencial($idEscola, $canal));
}

/**
 * Grava o canal (cria ou troca). $credencial null mantém a gravada.
 * $conferencia: o que o provedor disse (ok marca como verificado).
 */
function gravarCanalDeEnvio(PDO $conexao, int $idEscola, array $canal, ?string $credencial, array $conferencia, int $idCoordenacao): void {
    $verificado = $conferencia['situacao'] === 'ok';
    $erro = $verificado ? null : mb_substr($conferencia['texto'], 0, 300);
    if ($credencial === null) {
        $conexao->prepare("
            UPDATE tb_canal_envio
            SET remetente = ?, nome_remetente = ?, id_numero = ?, modelo = ?, idioma_modelo = ?,
                verificado_em = CASE WHEN ?::boolean THEN NOW() ELSE NULL END, ultimo_erro = ?,
                atualizado_em = NOW(), atualizado_por = ?
            WHERE id_escola = ? AND canal = ?
        ")->execute([
            $canal['remetente'], $canal['nome_remetente'], $canal['id_numero'], $canal['modelo'], $canal['idioma_modelo'],
            $verificado ? 'true' : 'false', $erro, $idCoordenacao, $idEscola, $canal['canal'],
        ]);
        return;
    }
    $chave = chaveDosCanaisDeEnvio();
    if ($chave === null) {
        throw new RuntimeException('BIOMETRIA_CHAVE ausente: a credencial não pode ser cifrada.');
    }
    $cifrada = cifrarDado($credencial, $chave, contextoDaCredencial($idEscola, $canal['canal']));
    $conexao->prepare("
        INSERT INTO tb_canal_envio (id_escola, canal, provedor, remetente, nome_remetente, id_numero, credencial_cifrada,
                                    credencial_final, modelo, idioma_modelo, verificado_em, ultimo_erro, atualizado_em, atualizado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ?::boolean THEN NOW() ELSE NULL END, ?, NOW(), ?)
        ON CONFLICT (id_escola, canal) DO UPDATE SET
            provedor = EXCLUDED.provedor, remetente = EXCLUDED.remetente, nome_remetente = EXCLUDED.nome_remetente,
            id_numero = EXCLUDED.id_numero, credencial_cifrada = EXCLUDED.credencial_cifrada,
            credencial_final = EXCLUDED.credencial_final, modelo = EXCLUDED.modelo, idioma_modelo = EXCLUDED.idioma_modelo,
            verificado_em = EXCLUDED.verificado_em, ultimo_erro = EXCLUDED.ultimo_erro, ultimo_teste_em = NULL,
            atualizado_em = NOW(), atualizado_por = EXCLUDED.atualizado_por
    ")->execute([
        $idEscola, $canal['canal'], $canal['provedor'], $canal['remetente'], $canal['nome_remetente'], $canal['id_numero'],
        $cifrada, finalDaCredencial($credencial), $canal['modelo'], $canal['idioma_modelo'],
        $verificado ? 'true' : 'false', $erro, $idCoordenacao,
    ]);
}

/** Os canais da escola para a tela: sem segredo nenhum. */
function canaisDaEscolaParaTela(PDO $conexao, int $idEscola): array {
    $canais = [];
    foreach (CANAIS_DE_ENVIO as $canal) {
        $gravado = canalGravado($conexao, $idEscola, $canal);
        $canais[$canal] = $gravado === null ? null : [
            'provedor' => $gravado['provedor'],
            'remetente' => $gravado['remetente'],
            'nome_remetente' => $gravado['nome_remetente'],
            'id_numero' => $gravado['id_numero'],
            'modelo' => $gravado['modelo'],
            'idioma_modelo' => $gravado['idioma_modelo'],
            'credencial_final' => $gravado['credencial_final'],
            'verificado_em' => $gravado['verificado_em'],
            'ultimo_teste_em' => $gravado['ultimo_teste_em'],
            'ultimo_erro' => $gravado['ultimo_erro'],
            'atualizado_em' => $gravado['atualizado_em'],
            'atualizado_por' => $gravado['atualizado_por'],
        ];
    }
    return $canais;
}

/**
 * Os canais próprios da escola, prontos para entregar (com a credencial
 * aberta). Guardados durante a requisição: um aviso para vários
 * responsáveis abre a chave uma vez só. Canal que não abre volta com
 * 'credencial' null - a entrega falha com o motivo, em vez de sair por
 * outro remetente sem ninguém saber.
 *
 * @return array{email: ?array, whatsapp: ?array}
 */
function canaisParaEntrega(PDO $conexao, int $idEscola): array {
    static $porEscola = [];
    if (isset($porEscola[$idEscola])) {
        return $porEscola[$idEscola];
    }
    $canais = ['email' => null, 'whatsapp' => null];
    try {
        $stmt = $conexao->prepare("
            SELECT c.canal, c.provedor, c.remetente, c.nome_remetente, c.id_numero, c.modelo, c.idioma_modelo,
                   c.credencial_cifrada, e.nome_escola
            FROM tb_canal_envio c JOIN tb_escola e ON e.id_escola = c.id_escola
            WHERE c.id_escola = ?
        ");
        $stmt->execute([$idEscola]);
        $chave = chaveDosCanaisDeEnvio();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $aberta = $chave === null ? null
                : decifrarDado((string) $linha['credencial_cifrada'], $chave, contextoDaCredencial($idEscola, $linha['canal']));
            $linha['credencial'] = $aberta === null ? null : new Credencial($aberta);
            unset($linha['credencial_cifrada']);
            $canais[$linha['canal']] = $linha;
        }
    } catch (PDOException $erro) {
        // Banco sem a migração 015 (versão nova da API com banco antigo): vale o canal do sistema.
        error_log('[BioFrequenciaApi] Canais da escola não lidos: ' . $erro->getMessage());
    }
    return $porEscola[$idEscola] = $canais;
}

// ---------- envio pelo canal da escola ----------

/** @return array{0: string, 1: ?string} [ENVIO_*, motivo da falha] */
function enviarEmailPeloCanal(array $canal, string $destino, string $assunto, string $texto, ?string $html, ?string $chave, ?string $responderPara): array {
    if ($canal['credencial'] === null) {
        return [ENVIO_FALHOU, 'e-mail: a chave do e-mail da escola não pôde ser aberta no servidor. A gestão precisa salvar a configuração de novo em Escola › Envio automático.'];
    }
    $remetente = remetenteFormatado($canal['nome_remetente'] ?? $canal['nome_escola'] ?? null, $canal['remetente']);
    $pedido = pedidoDeEmail($canal['provedor'], $canal['credencial']->valor(), $remetente, $destino, $assunto, $texto, $html, $responderPara, $chave);
    $endereco = $canal['provedor'] === 'brevo' ? urlDoProvedor('brevo', '/v3/smtp/email') : urlDoProvedor('resend', '/emails');
    return postarNoProvedor($endereco, null, $pedido['corpo'], 'e-mail', $pedido['cabecalhos']);
}

/** @return array{0: string, 1: ?string} [ENVIO_*, motivo da falha] */
function enviarWhatsappPeloCanal(array $canal, string $telefone, string $mensagem): array {
    if ($canal['credencial'] === null) {
        return [ENVIO_FALHOU, 'WhatsApp: o token do WhatsApp da escola não pôde ser aberto no servidor. A gestão precisa salvar a configuração de novo em Escola › Envio automático.'];
    }
    $url = urlDoProvedor('meta', '/' . VERSAO_API_META . '/' . rawurlencode($canal['id_numero']) . '/messages');
    $corpo = corpoWhatsapp($telefone, $mensagem, $canal['modelo'], $canal['idioma_modelo'] ?: IDIOMA_MODELO_PADRAO);
    return postarNoProvedor($url, $canal['credencial']->valor(), $corpo, 'WhatsApp');
}
