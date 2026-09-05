#!/usr/bin/env bash
# Test de integración de Arca v2.
# Arranca un servidor PHP embebido sobre una base de datos temporal y
# ejercita la API completa: registro, login, movimientos, objetivos,
# presupuestos, aislamiento entre usuarios y concurrencia.
#
# Uso: bash tests/integration_test.sh
#
# Requiere: PHP CLI, curl, python3 (solo para parsear JSON de forma legible).

set -uo pipefail

PORT=8099
BASE="http://localhost:$PORT"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# IMPORTANTE: nunca usar api/data/arca.sqlite aquí. Ese es el archivo real
# de una instalación en uso; este script lo borra y recrea constantemente,
# así que trabaja siempre sobre una copia temporal aislada, exportada a
# ARCA_DB_FILE para que config.php la use en vez de la ruta por defecto.
DB_FILE=$(mktemp /tmp/arca-integration-test-XXXXXX.sqlite)
rm -f "$DB_FILE" # mktemp lo crea vacío; lo borramos para que arranque limpio
export ARCA_DB_FILE="$DB_FILE"
COOKIE_A=$(mktemp)
COOKIE_B=$(mktemp)

PASS=0
FAIL=0

check() {
  local label="$1" expected="$2" actual="$3"
  if [ "$expected" == "$actual" ]; then
    PASS=$((PASS+1))
    echo "  OK  $label"
  else
    FAIL=$((FAIL+1))
    echo "FALLO  $label (esperado: $expected, obtenido: $actual)"
  fi
}

json_get() {
  python3 -c "
import json, sys
d = json.load(sys.stdin)
print(eval('d' + sys.argv[1]))
" "$1" 2>/dev/null
}

cleanup() {
  kill "$SERVER_PID" 2>/dev/null
  rm -f "$COOKIE_A" "$COOKIE_B" "$DB_FILE" "$DB_FILE-wal" "$DB_FILE-shm"
}
trap cleanup EXIT

rm -f "$DB_FILE" "$DB_FILE-wal" "$DB_FILE-shm"
cd "$ROOT_DIR"
php -S "localhost:$PORT" > /tmp/arca-integration-test.log 2>&1 &
SERVER_PID=$!
sleep 1

echo "=== Registro y login ==="
RESP=$(curl -s --max-time 5 -c "$COOKIE_A" -X POST "$BASE/api/endpoints/register.php" -H "Content-Type: application/json" -d '{"username":"ana_test","password":"claveSegura2026"}')
check "registro exitoso" "true" "$(echo "$RESP" | json_get "['ok']" | tr 'A-Z' 'a-z')"
TOKEN_A=$(echo "$RESP" | json_get "['csrf_token']")

RESP2=$(curl -s --max-time 5 -c "$COOKIE_B" -X POST "$BASE/api/endpoints/register.php" -H "Content-Type: application/json" -d '{"username":"beto_test","password":"otraClaveSegura9"}')
check "segundo registro exitoso" "true" "$(echo "$RESP2" | json_get "['ok']" | tr 'A-Z' 'a-z')"
TOKEN_B=$(echo "$RESP2" | json_get "['csrf_token']")

echo ""
echo "=== Precisión de céntimos ==="
curl -s --max-time 5 -b "$COOKIE_A" -X POST "$BASE/api/endpoints/add_balance.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_A" -d '{"amount":0.1,"note":"a"}' > /dev/null
curl -s --max-time 5 -b "$COOKIE_A" -X POST "$BASE/api/endpoints/add_balance.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_A" -d '{"amount":0.2,"note":"b"}' > /dev/null
TOTAL=$(curl -s --max-time 5 -b "$COOKIE_A" "$BASE/api/endpoints/get_data.php" | json_get "['balance']['total']")
check "0.1 + 0.2 = 0.3 exacto (sin residuo binario)" "0.3" "$TOTAL"

echo ""
echo "=== Aislamiento entre usuarios ==="
TOTAL_B=$(curl -s --max-time 5 -b "$COOKIE_B" "$BASE/api/endpoints/get_data.php" | json_get "['balance']['total']")
check "el segundo usuario no ve el saldo del primero" "0" "$TOTAL_B"

DELETE_RESP=$(curl -s --max-time 5 -b "$COOKIE_B" -X POST "$BASE/api/endpoints/delete_balance.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_B" -d '{"id":1}')
check "un usuario no puede borrar movimientos de otro (deleted=false)" "false" "$(echo "$DELETE_RESP" | json_get "['deleted']" | tr 'A-Z' 'a-z')"

echo ""
echo "=== Concurrencia (20 altas simultáneas de +10) ==="
CURL_PIDS=()
for i in $(seq 1 20); do
  curl -s --max-time 5 -b "$COOKIE_A" -X POST "$BASE/api/endpoints/add_balance.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_A" -d "{\"amount\":10,\"note\":\"c$i\"}" > /dev/null &
  CURL_PIDS+=($!)
done
wait "${CURL_PIDS[@]}"
FINAL_TOTAL=$(curl -s --max-time 5 -b "$COOKIE_A" "$BASE/api/endpoints/get_data.php" | json_get "['balance']['total']")
check "saldo final tras concurrencia = 0.3 + 200 = 200.3" "200.3" "$FINAL_TOTAL"

MOV_COUNT=$(curl -s --max-time 5 -b "$COOKIE_A" "$BASE/api/endpoints/get_data.php" | json_get "['balance']['history']" | python3 -c "import ast,sys; print(len(ast.literal_eval(sys.stdin.read())))" 2>/dev/null)
check "22 movimientos guardados (2 + 20), ninguno perdido" "22" "$MOV_COUNT"

echo ""
echo "=== Objetivos con decimales ==="
curl -s --max-time 5 -b "$COOKIE_A" -X POST "$BASE/api/endpoints/admin_save_goals.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_A" -d '{"goals":[{"id":null,"name":"Meta","target_amount":100}]}' > /dev/null
GOAL_ID=$(curl -s --max-time 5 -b "$COOKIE_A" "$BASE/api/endpoints/admin_get_data.php" | json_get "['goals'][0]['id']")
curl -s --max-time 5 -b "$COOKIE_A" -X POST "$BASE/api/endpoints/add_goal_contribution.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_A" -d "{\"id\":$GOAL_ID,\"amount\":0.1}" > /dev/null
curl -s --max-time 5 -b "$COOKIE_A" -X POST "$BASE/api/endpoints/add_goal_contribution.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_A" -d "{\"id\":$GOAL_ID,\"amount\":0.2}" > /dev/null
SAVED=$(curl -s --max-time 5 -b "$COOKIE_A" "$BASE/api/endpoints/get_data.php" | json_get "['goals'][0]['saved_amount']")
check "objetivo: 0.1 + 0.2 = 0.3 exacto" "0.3" "$SAVED"

echo ""
echo "=== Seguridad: rate limiting por cuenta ==="
for i in 1 2 3 4 5; do
  curl -s --max-time 5 -X POST "$BASE/api/endpoints/login.php" -H "Content-Type: application/json" -d '{"username":"beto_test","password":"mala"}' > /dev/null
done
LOCK_RESP=$(curl -s --max-time 5 -w "\n%{http_code}" -X POST "$BASE/api/endpoints/login.php" -H "Content-Type: application/json" -d '{"username":"beto_test","password":"otraClaveSegura9"}')
LOCK_CODE=$(echo "$LOCK_RESP" | tail -1)
check "cuenta bloqueada tras 5 intentos fallidos (HTTP 429)" "429" "$LOCK_CODE"

OTHER_LOGIN=$(curl -s --max-time 5 -w "\n%{http_code}" -X POST "$BASE/api/endpoints/login.php" -H "Content-Type: application/json" -d '{"username":"ana_test","password":"claveSegura2026"}')
OTHER_CODE=$(echo "$OTHER_LOGIN" | tail -1)
check "otra cuenta no se ve afectada por el bloqueo (HTTP 200)" "200" "$OTHER_CODE"

echo ""
echo "=== Seguridad: CSRF y sesión ==="
NO_CSRF=$(curl -s --max-time 5 -w "\n%{http_code}" -b "$COOKIE_A" -X POST "$BASE/api/endpoints/add_balance.php" -H "Content-Type: application/json" -d '{"amount":5}')
check "sin token CSRF -> HTTP 403" "403" "$(echo "$NO_CSRF" | tail -1)"

NO_SESSION=$(curl -s --max-time 5 -w "\n%{http_code}" -X POST "$BASE/api/endpoints/add_balance.php" -H "Content-Type: application/json" -d '{"amount":5}')
check "sin sesión -> HTTP 401" "401" "$(echo "$NO_SESSION" | tail -1)"

echo ""
echo "=== Copia de seguridad: exportar y restaurar ==="
curl -s --max-time 5 -b "$COOKIE_A" -X POST "$BASE/api/endpoints/admin_save_banks.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_A" -d '{"banks":[{"id":null,"name":"Banco Backup","url":"https://banco-backup.example.com","active":true}]}' > /dev/null
curl -s --max-time 5 -b "$COOKIE_A" "$BASE/api/endpoints/export_backup.php" -o /tmp/arca-integration-backup.json

BACKUP_APP=$(python3 -c "import json; print(json.load(open('/tmp/arca-integration-backup.json'))['app'])" 2>/dev/null)
check "el backup exportado tiene la marca de app correcta" "arca" "$BACKUP_APP"

python3 -c "
import json
backup = json.load(open('/tmp/arca-integration-backup.json'))
print(json.dumps({'backup': backup}))
" > /tmp/arca-integration-restore-payload.json

RESTORE_RESP=$(curl -s --max-time 5 -b "$COOKIE_B" -X POST "$BASE/api/endpoints/import_backup.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_B" -d @/tmp/arca-integration-restore-payload.json)
check "restaurar en la cuenta B funciona (ok=true)" "true" "$(echo "$RESTORE_RESP" | json_get "['ok']" | tr 'A-Z' 'a-z')"

BANK_NAME_B=$(curl -s --max-time 5 -b "$COOKIE_B" "$BASE/api/endpoints/get_data.php" | json_get "['banks'][0]['name']")
check "la cuenta B recibió el banco de la cuenta A tras restaurar" "Banco Backup" "$BANK_NAME_B"

rm -f /tmp/arca-integration-backup.json /tmp/arca-integration-restore-payload.json

echo ""
echo "=== 2FA: configurar, activar, login en dos pasos, desactivar ==="

totp_code() {
  python3 -c "
import hmac, hashlib, struct, base64, sys
secret = sys.argv[1]
key = base64.b32decode(secret.upper())
import time
counter = int(time.time()) // 30
h = hmac.new(key, struct.pack('>Q', counter), hashlib.sha1).digest()
offset = h[-1] & 0x0F
binary = ((h[offset] & 0x7F) << 24) | ((h[offset+1] & 0xFF) << 16) | ((h[offset+2] & 0xFF) << 8) | (h[offset+3] & 0xFF)
print(str(binary % 1000000).zfill(6))
" "$1"
}

COOKIE_2FA=$(mktemp)
REG_2FA=$(curl -s --max-time 5 -c "$COOKIE_2FA" -X POST "$BASE/api/endpoints/register.php" -H "Content-Type: application/json" -d '{"username":"twofa_integration","password":"claveSegura2026"}')
TOKEN_2FA=$(echo "$REG_2FA" | json_get "['csrf_token']")

SETUP_2FA=$(curl -s --max-time 5 -b "$COOKIE_2FA" -X POST "$BASE/api/endpoints/twofa_setup.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_2FA" -d '{}')
SECRET_2FA=$(echo "$SETUP_2FA" | json_get "['secret']")

CODE_2FA=$(totp_code "$SECRET_2FA")
ENABLE_RESP=$(curl -s --max-time 5 -b "$COOKIE_2FA" -X POST "$BASE/api/endpoints/twofa_enable.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_2FA" -d "{\"code\":\"$CODE_2FA\"}")
check "activar 2FA con código real (calculado en Python, no en la app)" "true" "$(echo "$ENABLE_RESP" | json_get "['ok']" | tr 'A-Z' 'a-z')"

COOKIE_2FA_LOGIN=$(mktemp)
LOGIN1_2FA=$(curl -s --max-time 5 -c "$COOKIE_2FA_LOGIN" -X POST "$BASE/api/endpoints/login.php" -H "Content-Type: application/json" -d '{"username":"twofa_integration","password":"claveSegura2026"}')
check "login paso 1 pide 2FA (requires_2fa=true)" "true" "$(echo "$LOGIN1_2FA" | json_get "['requires_2fa']" | tr 'A-Z' 'a-z')"

NO_2FA_YET=$(curl -s --max-time 5 -w "\n%{http_code}" -b "$COOKIE_2FA_LOGIN" "$BASE/api/endpoints/get_data.php")
check "sin completar el paso 2, get_data sigue dando 401" "401" "$(echo "$NO_2FA_YET" | tail -1)"

CODE_2FA_LOGIN=$(totp_code "$SECRET_2FA")
LOGIN2_2FA=$(curl -s --max-time 5 -b "$COOKIE_2FA_LOGIN" -c "$COOKIE_2FA_LOGIN" -X POST "$BASE/api/endpoints/twofa_login_verify.php" -H "Content-Type: application/json" -d "{\"code\":\"$CODE_2FA_LOGIN\"}")
check "login paso 2 con código correcto concede sesión (ok=true)" "true" "$(echo "$LOGIN2_2FA" | json_get "['ok']" | tr 'A-Z' 'a-z')"

TOKEN_2FA_2=$(echo "$LOGIN2_2FA" | json_get "['csrf_token']")
DISABLE_2FA=$(curl -s --max-time 5 -b "$COOKIE_2FA_LOGIN" -X POST "$BASE/api/endpoints/twofa_disable.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN_2FA_2" -d '{"password":"claveSegura2026"}')
check "desactivar 2FA con contraseña correcta funciona" "true" "$(echo "$DISABLE_2FA" | json_get "['ok']" | tr 'A-Z' 'a-z')"

rm -f "$COOKIE_2FA" "$COOKIE_2FA_LOGIN"

echo ""
echo "=== Resumen ==="
echo "$PASS OK, $FAIL FALLOS de $((PASS+FAIL)) comprobaciones."
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
