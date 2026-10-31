<?php
/**
 * O que este código espera encontrar no banco, por migração. Conferido
 * pelo health.php - ver o comentário de lá para o porquê.
 *
 * "tabela" para tabela inteira, "tabela.coluna" para coluna adicionada
 * numa migração posterior. Ao criar uma migração nova que o código passe
 * a usar, acrescente aqui um objeto que só ela cria.
 */
const OBJETOS_POR_MIGRACAO = [
    '002' => ['tb_acompanhamento', 'tb_alerta_encerrado'],
    '003' => ['tb_biometria_facial', 'tb_verificacao_facial', 'tb_codigo_vinculo', 'tb_dispositivo_aluno', 'tb_checkin_biometrico'],
    '005' => ['tb_escola', 'tb_ocorrencia_pendente', 'tb_alerta_nivel', 'tb_coordenacao.id_escola', 'tb_sala.id_escola', 'tb_notificacao.canal', 'tb_notificacao.nivel_alerta'],
    '006' => ['tb_presenca', 'tb_consentimento_biometria', 'tb_biometria_digital', 'tb_identificacao_biometrica', 'tb_biometria_facial.cifrado'],
];

/** Lista das migrações (números) com algum objeto ausente no banco. */
function migracoesPendentes(PDO $pdo): array {
    $existentes = [];
    $stmt = $pdo->query("
        SELECT table_name, NULL AS column_name FROM information_schema.tables WHERE table_schema = current_schema()
        UNION ALL
        SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema()
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $existentes[$linha['column_name'] === null ? $linha['table_name'] : $linha['table_name'] . '.' . $linha['column_name']] = true;
    }

    $pendentes = [];
    foreach (OBJETOS_POR_MIGRACAO as $migracao => $objetos) {
        foreach ($objetos as $objeto) {
            if (!isset($existentes[$objeto])) {
                $pendentes[] = $migracao;
                break;
            }
        }
    }
    return $pendentes;
}
