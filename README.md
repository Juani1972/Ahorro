# Arca v2 — control de ahorro (multiusuario, SQLite)

Versión reescrita sobre una base de datos real (SQLite) en vez de un archivo
JSON, con soporte para varias cuentas independientes, PWA instalable, tema
claro/oscuro, y una suite de tests automatizados.

## ⚠️ Requisito importante del hosting

Esta versión necesita la extensión **`pdo_sqlite`** de PHP activada. La
mayoría de hostings modernos la traen, pero **no todos los básicos la
activan por defecto**. Antes de subir esta versión, confirma con tu
proveedor de hosting (o pruébalo directamente) que `pdo_sqlite` está
disponible. Si no lo está, pídeles que la activen — es una extensión
estándar de PHP, no un módulo raro.

También necesita que la carpeta `api/data/` tenga permisos de escritura
(el archivo de base de datos `arca.sqlite` se crea solo la primera vez que
alguien usa la app).

**Nota sobre dependencias externas:** Vue y Chart.js ya están alojados
localmente en `assets/js/vendor/` (no dependen de ningún CDN). La única
dependencia externa que queda son las tipografías, cargadas desde Google
Fonts (`fonts.googleapis.com` / `fonts.gstatic.com`) — es texto/CSS, no
código ejecutable, así que el riesgo de seguridad es mínimo comparado con
depender de JavaScript de terceros. Si prefieres eliminarla del todo,
tocaría descargar las fuentes (Fraunces e Inter) y servirlas también desde
`assets/`, algo que puedo hacer si te interesa.

## Qué cambia respecto a la v1

- **Multiusuario real**: cualquiera puede crear su cuenta (usuario +
  contraseña) desde la página principal. Cada cuenta ve solo sus propios
  datos — bancos, movimientos, categorías, objetivos, presupuestos y
  distribución son independientes por cuenta.
- **SQLite en vez de JSON**: mismas garantías de antes (nunca se pierde un
  movimiento por dos peticiones simultáneas) pero ahora resueltas de forma
  nativa por la base de datos, no con bloqueos de archivo hechos a mano.
- **Céntimos enteros**: todos los montos se guardan internamente como
  números enteros de céntimos, no como decimales. Esto elimina el clásico
  problema de precisión de las operaciones con coma flotante (ej. que
  `0.1 + 0.2` dé `0.30000000000000004` en vez de `0.3`).
- **PWA**: se puede instalar en el móvil como una app (botón "Añadir a
  pantalla de inicio" que ofrece el navegador). Funciona parcialmente sin
  conexión (la interfaz carga desde caché; los datos siempre requieren red,
  nunca se muestran cifras desactualizadas).
- **Tema claro/oscuro**: botón ☀/☾ en la cabecera; la preferencia se
  recuerda en el propio navegador.
- **Copia de seguridad**: desde "Mi cuenta" puedes descargar un archivo con
  todos tus datos (bancos, movimientos, categorías, objetivos, presupuestos
  y distribución), y restaurarlo más adelante — útil antes de hacer cambios
  grandes, o si migras de hosting. **Restaurar reemplaza todos los datos
  actuales de la cuenta**, no los combina con los existentes.
- **Verificación en dos pasos (2FA)**: compatible con Google Authenticator,
  Authy, Microsoft Authenticator o cualquier app TOTP estándar. Se activa
  desde "Mi cuenta"; una vez activada, el login pide primero la contraseña
  y después el código de 6 dígitos. La clave se muestra como texto para
  introducir manualmente en la app (no se genera un código QR gráfico, para
  no depender de una librería externa sin poder verificarla del todo aquí —
  la introducción manual es un método igual de estándar y funciona en
  cualquier app autenticadora).
- **Notificaciones del navegador**: aviso 🔔/🔕 en la cabecera. Si lo
  activas, el navegador te notifica cuando superas un presupuesto mensual o
  completas un objetivo de ahorro, mientras tengas la pestaña abierta.
  **Importante**: esto NO son notificaciones push reales (que llegarían con
  la app cerrada o el móvil bloqueado) — esas requieren infraestructura
  adicional (claves VAPID, suscripciones push, cifrado de payload) que no
  está incluida en esta versión. Si te interesa esa función más adelante,
  es un desarrollo aparte.
- **Vue y Chart.js alojados localmente**: ya no dependen de `unpkg.com` ni
  `cdnjs.com` — están en `assets/js/vendor/`, así que la app funciona igual
  aunque esos servicios externos fallen o se bloqueen.
- **Content-Security-Policy (CSP)** y cabeceras de seguridad adicionales en
  todas las páginas (no solo en la API). Con una salvedad técnica que hay
  que entender: `script-src` incluye `'unsafe-eval'` porque Vue, usado aquí
  "sin build" (las plantillas viven directamente en el HTML, no se
  precompilan), necesita el constructor `Function()` del navegador para
  compilarlas — lo comprobé literalmente buscando ese patrón dentro del
  archivo de Vue. Quitar esa necesidad implicaría añadir un paso de
  compilación (Vite, por ejemplo), lo que iría en contra del objetivo de
  que este proyecto se pueda subir tal cual a un hosting básico sin
  herramientas de compilación. A cambio, se bloquea con fuerza todo lo
  demás: ningún script de un origen externo, sin poder incrustarse en un
  `<iframe>` ajeno, sin poder enviar formularios a otro dominio, etc.
- **Accesibilidad**: todos los campos de formulario tienen `aria-label`
  (antes solo tenían `placeholder`, que un lector de pantalla no anuncia
  igual que una etiqueta real); los botones de solo icono (tema,
  notificaciones) tienen `aria-label` describiendo la acción; los avisos
  emergentes (toasts) se anuncian automáticamente a lectores de pantalla
  (`role="status" aria-live="polite"`); y hay un contorno de foco visible
  al navegar con teclado en botones y enlaces, no solo en campos de texto.
  **Esto no es una auditoría WCAG completa** — no tengo forma de probar
  esto con un lector de pantalla real ni de medir contraste de color de
  forma automática en este entorno; son mejoras concretas y verificables
  por inspección de código, no una certificación de accesibilidad.

## Instalación

### Automatizada (para desarrollo local o un VPS con terminal)

```
bash install.sh            # comprueba requisitos y corre los tests
bash install.sh --serve    # además, levanta un servidor local
```

El script comprueba PHP, la extensión `pdo_sqlite`, permisos de escritura,
corre toda la suite de tests, y opcionalmente arranca un servidor en el
primer puerto libre a partir del 8000. **Es seguro ejecutarlo aunque ya
tengas una base de datos real con datos de un usuario** — lo comprobé
expresamente: los tests usan siempre un archivo temporal aislado
(`ARCA_DB_FILE`), nunca `api/data/arca.sqlite`, así que nunca pueden
sobrescribir ni borrar datos reales, sin importar cuántas veces lo
ejecutes.

Si vas a subir la app a un hosting compartido básico por FTP, sin acceso a
terminal, este script no te sirve — pero tampoco lo necesitas para ese
caso: sigue los pasos manuales de abajo.

### Manual (para cualquier hosting, incluido uno básico por FTP)

1. Sube todo el contenido de esta carpeta (tal cual) a tu hosting.
2. Confirma que `pdo_sqlite` está activo (ver arriba) y que `api/data/`
   tiene permisos de escritura.
3. Abre `tu-dominio.com/index.html` — el primer acceso creará
   automáticamente la base de datos y sus tablas.
4. Crea tu cuenta desde la propia web (enlace "¿No tienes cuenta?
   Regístrate" en la pantalla de login).

## Copias de seguridad

Todo el contenido de la aplicación vive en un único archivo:
`api/data/arca.sqlite` (y sus archivos auxiliares `-wal`/`-shm`, propios del
modo de journaling que usa SQLite para mejorar la concurrencia). Para hacer
una copia de seguridad, basta con descargar ese archivo por FTP con la app
parada, o usar cualquier herramienta de backup de tu hosting que incluya la
carpeta `api/data/`.

## Tests

### Tests unitarios (funciones de cálculo)
```
php tests/unit_test.php
```
No requiere servidor ni red — corre en memoria contra una base de datos
temporal. Cubre: precisión de céntimos, validación de fechas, cálculo de
saldo, distribución (ambos modos), objetivos, presupuestos, y aislamiento
entre usuarios.

### Tests de integración (API completa)
```
bash tests/integration_test.sh
```
Arranca un servidor PHP real, registra dos cuentas de prueba, y ejercita la
API completa por HTTP: registro, login, altas de saldo con precisión de
céntimos, aislamiento entre usuarios (incluyendo intentos de acceso cruzado
adversariales), concurrencia con 20 peticiones simultáneas, objetivos,
copia de seguridad (exportar de una cuenta y restaurar en otra, verificando
que las relaciones entre movimientos/presupuestos y categorías sobreviven
aunque los IDs cambien), verificación en dos pasos (activar con un código
TOTP calculado de forma independiente en Python, login en dos pasos,
desactivar), y seguridad (rate limiting, CSRF, sesión). Limpia todo lo que
crea al terminar (el servidor de prueba, la base de datos temporal, las
cookies).

Ambos scripts terminan con código de salida 0 si todo pasa, o 1 si algo
falla — se pueden integrar en cualquier pipeline de CI si en el futuro
quieres automatizar esto más.

## Estructura del proyecto

```
index.html                  → web principal (login/registro + toda la app)
admin.html                    → "Mi cuenta" (bancos, categorías, objetivos, presupuestos, distribución, seguridad)
manifest.json                  → configuración PWA
service-worker.js               → caché de archivos estáticos (nunca de datos)
assets/
  css/style.css                 → estilos (incluye tema claro y oscuro)
  js/app.js                      → lógica de la web principal
  js/admin.js                    → lógica de "Mi cuenta"
  icons/icon.svg                 → icono de la PWA

api/
  config.php                    → sesión, cabeceras de seguridad, conversión euros↔céntimos
  db.php                         → conexión SQLite + todas las funciones de cálculo
  data/schema.sql                 → esquema de la base de datos (se aplica solo, la primera vez)
  data/arca.sqlite                → la base de datos en sí (se crea sola, no la subas tú)
  endpoints/                    → un archivo PHP por acción de la API

tests/
  unit_test.php                  → tests unitarios de las funciones de cálculo
  integration_test.sh             → tests de integración sobre la API completa
```
