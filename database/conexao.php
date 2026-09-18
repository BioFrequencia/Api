<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/resposta.php';

try {

    $dsn = "pgsql:host=" . DB_HOST .
       ";port=" . DB_PORT .
       ";dbname=" . DB_NAME;

    // ATTR_EMULATE_PREPARES precisa ser true quando DB_HOST é o connection
    // pooler do Supabase em modo Transaction (porta 6543, PgBouncer): nesse
    // modo, cada query de uma "sessão" pode ser roteada pra uma conexão de
    // backend diferente, então um prepared statement de verdade (criado no
    // backend A) pode não existir mais quando o PDO tenta executá-lo E o
    // PgBouncer manda pro backend B - falha intermitente e imprevisível,
    // exatamente o padrão relatado (a mesma chamada funciona umas vezes e
    // falha outras, sem nenhuma lógica aparente). Com emulate_prepares=true
    // o PDO nunca cria um prepared statement de verdade no servidor - ele
    // monta o SQL final (com os valores já escapados) inteiramente no PHP
    // antes de mandar, então não depende de nenhum estado ficar guardado
    // entre duas idas ao banco. Inofensivo em qualquer outro tipo de
    // conexão (direta, ou pooler em modo Session) - só deixa de usar uma
    // otimização que não faz diferença de performance perceptível aqui.
    $conexao = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);

    $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $erro) {
    // Detalhe completo (host, motivo) só vai para o log do servidor.
    enviarErro("Não foi possível conectar ao banco de dados. Tente novamente em instantes.", 503, $erro);
}
