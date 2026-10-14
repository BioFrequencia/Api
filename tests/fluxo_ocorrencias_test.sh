set -e
API=http://127.0.0.1:8081
PG="psql -U postgres -d biofrequencia -t -A"
export PGHOST=/tmp

ok(){ echo "  ✓ $1"; }
falha(){ echo "  ✗ $1"; exit 1; }

# O teste confere contagens exatas (ex.: "tb_falta ainda vazia"), então
# precisa partir sempre do mesmo estado - sem isto, rodar duas vezes ou
# rodar depois de um teste manual faz asserções corretas falharem.
echo "=== Limpando estado de execuções anteriores ==="
$PG -c "DELETE FROM tb_ocorrencia_pendente;" >/dev/null
$PG -c "DELETE FROM tb_notificacao;" >/dev/null
$PG -c "DELETE FROM tb_falta;" >/dev/null
$PG -c "DELETE FROM tb_atraso;" >/dev/null
$PG -c "DELETE FROM tb_alerta_nivel;" >/dev/null
$PG -c "DELETE FROM tb_checkin_biometrico;" >/dev/null
$PG -c "DELETE FROM tb_dispositivo_aluno;" >/dev/null
$PG -c "DELETE FROM tb_codigo_vinculo;" >/dev/null

echo "=== Preparo: segunda escola + conta ==="
$PG -c "INSERT INTO tb_escola (nome_escola) SELECT 'Escola Vizinha' WHERE NOT EXISTS (SELECT 1 FROM tb_escola WHERE nome_escola='Escola Vizinha');" >/dev/null
ESC2=$($PG -c "SELECT id_escola FROM tb_escola WHERE nome_escola='Escola Vizinha';")
curl -s -X POST $API/user/addCoord.php -H 'Content-Type: application/json' \
  -d '{"nome":"Coord Vizinha","email":"vizinha@escola.test","senha":"Senha@123"}' >/dev/null
$PG -c "UPDATE tb_coordenacao SET id_escola=$ESC2 WHERE email_coordenacao='vizinha@escola.test';" >/dev/null
$PG -c "INSERT INTO tb_sala (nome_sala, periodo_sala, id_escola) SELECT '1B','Tarde',$ESC2 WHERE NOT EXISTS (SELECT 1 FROM tb_sala WHERE nome_sala='1B');" >/dev/null
SALA2=$($PG -c "SELECT id_sala FROM tb_sala WHERE nome_sala='1B';")

T1=$(curl -s -X POST $API/user/selectCoord.php -H 'Content-Type: application/json' -d '{"email":"coord@escola.test","senha":"Senha@123"}' | python3 -I -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])")
T2=$(curl -s -X POST $API/user/selectCoord.php -H 'Content-Type: application/json' -d '{"email":"vizinha@escola.test","senha":"Senha@123"}' | python3 -I -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])")
if [ "$($PG -c "SELECT count(*) FROM tb_aluno WHERE nome_aluno='Aluno Vizinho';")" = "0" ]; then
curl -s -X POST $API/user/addaluno.php -H "Authorization: Bearer $T2" -H 'Content-Type: application/json' \
  -d "{\"nome_aluno\":\"Aluno Vizinho\",\"id_sala\":$SALA2,\"dta_nasc_aluno\":\"2009-03-02\",\"genero_aluno\":\"Masculino\"}" >/dev/null
fi

echo "=== 1. Isolamento entre escolas ==="
N1=$(curl -s -X POST $API/user/filtroAluno.php -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -d '{"pagina":1,"por_pagina":50}' | python3 -I -c "import sys,json;d=json.load(sys.stdin)['dados'];print(len(d['alunos']))")
N2=$(curl -s -X POST $API/user/filtroAluno.php -H "Authorization: Bearer $T2" -H 'Content-Type: application/json' -d '{"pagina":1,"por_pagina":50}' | python3 -I -c "import sys,json;d=json.load(sys.stdin)['dados'];print(len(d['alunos']))")
echo "  escola 1 vê $N1 aluno(s); escola 2 vê $N2 aluno(s)"
[ "$N1" = "1" ] && [ "$N2" = "1" ] && ok "cada escola vê só os próprios alunos" || falha "isolamento falhou"

ALUNO_VIZ=$($PG -c "SELECT id_aluno FROM tb_aluno WHERE nome_aluno='Aluno Vizinho';")
R=$(curl -s -X POST $API/user/marcarFalta.php -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -d "{\"id_aluno\":$ALUNO_VIZ,\"motivo_falta\":\"invasao\"}")
echo "$R" | grep -q '"sucesso":false' && ok "escola 1 NÃO consegue lançar falta em aluno da escola 2" || falha "vazamento: $R"

echo "=== 2. Vínculo do aparelho ==="
ALUNO1=$($PG -c "SELECT id_aluno FROM tb_aluno WHERE nome_aluno='Aluno Teste';")
CODIGO=$(curl -s -X POST $API/digital/gerarCodigoVinculo.php -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -d "{\"id_aluno\":$ALUNO1}" | python3 -I -c "import sys,json;print(json.load(sys.stdin)['dados']['codigo'])")
ok "código gerado: $CODIGO"
UUID="aparelho-teste-$(date +%s)"
SEGREDO=$(curl -s -X POST $API/digital/vincularDispositivo.php -H 'Content-Type: application/json' -d "{\"codigo\":\"$CODIGO\",\"device_uuid\":\"$UUID\"}" | python3 -I -c "import sys,json;print(json.load(sys.stdin)['dados']['device_secret'])")
ok "aparelho vinculado (segredo recebido uma única vez)"

echo "=== 3. Envio de ocorrência pelo app ==="
TS=$(python3 -I -c "import datetime;print(datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S+00:00'))")
ASSIN=$(python3 -I -c "import hmac,hashlib,sys;print(hmac.new(sys.argv[1].encode(),f'{sys.argv[2]}|falta|{sys.argv[3]}'.encode(),hashlib.sha256).hexdigest())" "$SEGREDO" "$UUID" "$TS")
R=$(curl -s -X POST $API/ocorrencia/enviarOcorrencia.php -H 'Content-Type: application/json' \
  -d "{\"device_uuid\":\"$UUID\",\"tipo\":\"falta\",\"timestamp\":\"$TS\",\"assinatura\":\"$ASSIN\",\"motivo\":\"Consulta medica\",\"observacao\":\"Levo atestado amanha\",\"responsavel_ciente\":true}")
echo "$R" | grep -q '"sucesso":true' && ok "ocorrência enviada e PENDENTE" || falha "$R"
echo "$R" | grep -q '"status":"pendente"' && ok "não virou falta automaticamente" || falha "virou falta sozinha"
FALTAS=$($PG -c "SELECT count(*) FROM tb_falta WHERE id_aluno=$ALUNO1;")
[ "$FALTAS" = "0" ] && ok "tb_falta ainda vazia (confirmado no banco)" || falha "criou falta sem aprovação"

echo "=== 4. Proteção contra duplicado ==="
R=$(curl -s -X POST $API/ocorrencia/enviarOcorrencia.php -H 'Content-Type: application/json' \
  -d "{\"device_uuid\":\"$UUID\",\"tipo\":\"falta\",\"timestamp\":\"$TS\",\"assinatura\":\"$ASSIN\",\"motivo\":\"Consulta medica\",\"responsavel_ciente\":true}")
echo "$R" | grep -q 'enviou uma' && ok "segundo envio no mesmo dia recusado (409)" || falha "duplicou: $R"

echo "=== 5. Aparelho desconhecido ==="
ASSIN_FAKE=$(python3 -I -c "import hmac,hashlib,sys;print(hmac.new(b'segredo-errado',f'desconhecido|falta|{sys.argv[1]}'.encode(),hashlib.sha256).hexdigest())" "$TS")
R=$(curl -s -X POST $API/ocorrencia/enviarOcorrencia.php -H 'Content-Type: application/json' \
  -d "{\"device_uuid\":\"desconhecido\",\"tipo\":\"falta\",\"timestamp\":\"$TS\",\"assinatura\":\"$ASSIN_FAKE\",\"motivo\":\"x\"}")
echo "$R" | grep -q 'vinculado a nenhum' && ok "aparelho sem vínculo: recusado, sem criar nada" || falha "$R"

echo "=== 6. Assinatura inválida ==="
R=$(curl -s -X POST $API/ocorrencia/enviarOcorrencia.php -H 'Content-Type: application/json' \
  -d "{\"device_uuid\":\"$UUID\",\"tipo\":\"atraso\",\"timestamp\":\"$TS\",\"assinatura\":\"0000\",\"motivo\":\"x\"}")
echo "$R" | grep -q 'Assinatura inv' && ok "assinatura forjada recusada" || falha "$R"

echo "=== 7. Coordenação revisa ==="
ID_OC=$(curl -s -X POST $API/ocorrencia/listarPendentes.php -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -d '{}' | python3 -I -c "import sys,json;d=json.load(sys.stdin)['dados'];print(d['ocorrencias'][0]['id_ocorrencia'])")
ok "coordenação vê a ocorrência #$ID_OC na fila"
VIZ=$(curl -s -X POST $API/ocorrencia/listarPendentes.php -H "Authorization: Bearer $T2" -H 'Content-Type: application/json' -d '{}' | python3 -I -c "import sys,json;print(json.load(sys.stdin)['dados']['total'])")
[ "$VIZ" = "0" ] && ok "escola 2 não vê a fila da escola 1" || falha "fila vazou entre escolas"

R=$(curl -s -X POST $API/ocorrencia/revisarOcorrencia.php -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -d "{\"id_ocorrencia\":$ID_OC,\"decisao\":\"aprovar\",\"justificado\":true}")
echo "$R" | grep -q '"status":"aprovado"' && ok "aprovada" || falha "$R"
FALTAS=$($PG -c "SELECT count(*) FROM tb_falta WHERE id_aluno=$ALUNO1;")
[ "$FALTAS" = "1" ] && ok "falta criada em tb_falta só APÓS a aprovação" || falha "esperava 1 falta, achei $FALTAS"
MOTIVO=$($PG -c "SELECT motivo_falta FROM tb_falta WHERE id_aluno=$ALUNO1;")
[ "$MOTIVO" = "Consulta medica" ] && ok "motivo preenchido pelo aluno chegou na falta" || falha "motivo: $MOTIVO"

echo "=== 8. Dupla decisão ==="
R=$(curl -s -X POST $API/ocorrencia/revisarOcorrencia.php -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -d "{\"id_ocorrencia\":$ID_OC,\"decisao\":\"rejeitar\"}")
echo "$R" | grep -q 'foi analisada' && ok "segunda decisão na mesma ocorrência recusada" || falha "$R"

echo "=== 9. Lançamento manual pelo site continua ==="
R=$(curl -s -X POST $API/user/marcarFalta.php -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -d "{\"id_aluno\":$ALUNO1,\"motivo_falta\":\"Lancamento manual\"}")
echo "$R" | grep -q '"sucesso":true' && ok "coordenação ainda lança falta manualmente" || falha "$R"
