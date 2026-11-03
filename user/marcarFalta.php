<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/cors.php';
require_once __DIR__ . '/../helpers/resposta.php';

/**
 * Faltas não são mais registradas pelo BioFrequência: o objetivo do TCC
 * é registrar entradas atrasadas e saídas antecipadas, e a frequência
 * (faltas) já é controlada pela Sala do Futuro. Duas fontes de falta para
 * o mesmo aluno só gerariam divergência.
 *
 * O endereço continua respondendo, com 410 (Gone) e a explicação, para
 * um cliente antigo (desktop, app desatualizado) mostrar uma mensagem
 * clara em vez de "erro no servidor". As faltas antigas continuam em
 * tb_falta; nada foi apagado.
 */
enviarErro(
    "O BioFrequência não registra mais faltas: a frequência é controlada pela Sala do Futuro. Registre entradas atrasadas e saídas antecipadas.",
    410
);
