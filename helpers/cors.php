<?php
/**
 * CORS liberado para qualquer origem (Access-Control-Allow-Origin: *)
 * de propósito: a autenticação desta API é por token no cabeçalho
 * Authorization (Bearer), nunca por cookie de sessão - não existe o
 * risco de CSRF que normalmente motiva restringir a origem quando
 * cookies estão envolvidos. Isso permite o site institucional (rodando
 * num domínio diferente da API) e testes locais chamarem a API direto
 * do navegador sem bloqueio de CORS.
 *
 * Precisa ser o PRIMEIRO require de cada endpoint (antes até de
 * database/conexao.php): o navegador manda uma requisição OPTIONS de
 * "preflight" antes de qualquer POST com Authorization/Content-Type
 * customizado, e essa requisição não deve tentar abrir conexão com o
 * banco nem rodar nenhuma regra de negócio - só responder com os
 * cabeçalhos abaixo e encerrar.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
