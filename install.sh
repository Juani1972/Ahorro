#!/usr/bin/env bash
# Instalación automatizada de Arca v2.
#
# Pensado para desarrollo local o un VPS con acceso por terminal (SSH).
# Si vas a subir la app a un hosting compartido básico por FTP, sin acceso
# a terminal, este script no te sirve — pero tampoco lo necesitas: basta con
# subir los archivos, la base de datos se crea sola al primer acceso.
#
# Uso:
#   bash install.sh            comprueba requisitos y corre los tests
#   bash install.sh --serve    además, levanta un servidor local en :8000

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

ERRORS=0
WARNINGS=0

ok()   { echo "  ✓ $1"; }
warn() { echo "  ⚠ $1"; WARNINGS=$((WARNINGS+1)); }
fail() { echo "  ✗ $1"; ERRORS=$((ERRORS+1)); }

echo "=== 1. Comprobando PHP ==="
if ! command -v php >/dev/null 2>&1; then
  fail "No se encontró el comando 'php'. Instálalo antes de continuar."
  echo ""
  echo "No se puede continuar sin PHP. Abortando."
  exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_VERSION;')
ok "PHP encontrado: versión $PHP_VERSION"

PHP_MAJOR_MINOR=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
if php -r 'exit(version_compare(PHP_VERSION, "7.4.0", ">=") ? 0 : 1);'; then
  ok "Versión de PHP compatible (>= 7.4)"
else
  fail "Se requiere PHP 7.4 o superior (tienes $PHP_VERSION)"
fi

echo ""
echo "=== 2. Comprobando extensiones necesarias ==="
if php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'; then
  ok "pdo_sqlite está activa (imprescindible, es la base de datos de la app)"
else
  fail "pdo_sqlite NO está activa. Sin ella la app no puede arrancar."
  echo "    En Ubuntu/Debian: sudo apt install php-sqlite3"
  echo "    En hosting compartido: pide a tu proveedor que la active."
fi

if php -r 'exit(extension_loaded("json") ? 0 : 1);'; then
  ok "json está activa"
else
  fail "json NO está activa (viene integrada en PHP por defecto; algo raro pasa con tu instalación)"
fi

if php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'; then
  ok "mbstring está activa (opcional, no la usa esta app, pero no molesta)"
else
  ok "mbstring no está activa — no pasa nada, esta app no la necesita"
fi

echo ""
echo "=== 3. Comprobando permisos de escritura ==="
if [ -w "api/data" ]; then
  ok "api/data/ tiene permisos de escritura"
else
  fail "api/data/ NO tiene permisos de escritura. Ejecuta: chmod 775 api/data"
fi

echo ""
echo "=== 4. Base de datos ==="
if [ -f "api/data/arca.sqlite" ]; then
  warn "Ya existe api/data/arca.sqlite — no se toca (podría tener datos reales). Si quieres empezar de cero, bórralo tú mismo."
else
  ok "No hay base de datos todavía — se creará sola en el primer acceso a la web"
fi

echo ""
echo "=== 5. Ejecutando tests automatizados ==="
if [ "$ERRORS" -gt 0 ]; then
  warn "Hay errores de requisitos arriba — me salto los tests hasta que los arregles"
else
  echo "--- Tests unitarios ---"
  if php tests/unit_test.php > /tmp/arca-install-unit.log 2>&1; then
    ok "Tests unitarios: $(tail -1 /tmp/arca-install-unit.log)"
  else
    fail "Fallaron los tests unitarios — revisa /tmp/arca-install-unit.log"
  fi

  echo "--- Tests de integración (arranca un servidor de prueba temporal) ---"
  if bash tests/integration_test.sh > /tmp/arca-install-integration.log 2>&1; then
    ok "Tests de integración: $(tail -1 /tmp/arca-install-integration.log)"
  else
    fail "Fallaron los tests de integración — revisa /tmp/arca-install-integration.log"
  fi
fi

echo ""
echo "=== Resumen ==="
echo "$ERRORS error(es), $WARNINGS aviso(s)."

if [ "$ERRORS" -gt 0 ]; then
  echo ""
  echo "Hay problemas que resolver antes de usar la app. Revisa los ✗ de arriba."
  exit 1
fi

echo ""
echo "Todo listo. Puedes:"
echo "  - Subir esta carpeta tal cual a tu hosting, o"
echo "  - Levantar un servidor local ahora mismo con: bash install.sh --serve"

if [ "${1:-}" == "--serve" ]; then
  echo ""
  echo "=== Levantando servidor local ==="
  port_is_free() {
    php -r '
      $fp = @fsockopen("127.0.0.1", (int) $argv[1], $errno, $errstr, 0.3);
      if ($fp) { fclose($fp); exit(1); } // alguien contesta ahí: puerto ocupado
      exit(0); // nadie contesta: puerto libre
    ' "$1"
  }
  PORT=8000
  while ! port_is_free "$PORT"; do
    PORT=$((PORT+1))
  done
  php -S "localhost:$PORT" > /tmp/arca-local-server.log 2>&1 &
  SERVER_PID=$!
  sleep 1
  if kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "Servidor arrancado (PID $SERVER_PID) en: http://localhost:$PORT/index.html"
    echo "Log del servidor: /tmp/arca-local-server.log"
    echo "Para detenerlo:   kill $SERVER_PID"
  else
    fail "No se pudo arrancar el servidor. Revisa /tmp/arca-local-server.log"
    exit 1
  fi
fi
