# Arca — control de ahorro

Aplicación web sencilla con tres funciones:

1. **Simulador de ahorro a corto plazo** — redirige al usuario a la web de un banco configurado.
2. **Panel de saldos e ingresos** — registro manual de movimientos, con saldo actualizado en tiempo real.
3. **Distribución automática** — reparte el saldo total entre conceptos, por porcentaje o monto fijo.

Incluye un **panel de administración** para editar bancos, URLs y conceptos de distribución sin tocar código.

---

## Requisitos del hosting

- PHP 7.4 o superior (con extensión `json`, incluida por defecto).
- **No requiere base de datos.** Los datos se guardan en `api/data/store.json`.
- Apache con `mod_rewrite`/`.htaccess` habilitado (para proteger la carpeta de datos). Si tu hosting usa Nginx, revisa la nota al final.

## Instalación

1. Sube **todo el contenido de este proyecto** (tal cual, `index.html`, `admin.html`, `assets/` y `api/` todos al mismo nivel) a la raíz de tu hosting, o a una subcarpeta si prefieres alojarlo ahí.
2. Verifica que la carpeta `api/data/` tenga permisos de escritura para el servidor web (normalmente `755` funciona; si tu hosting lo exige, usa `775`).
3. Abre `tu-dominio.com/index.html` (o `tu-dominio.com/subcarpeta/index.html`) en el navegador.
4. Abre `tu-dominio.com/admin.html` para entrar al panel de administración.

**Importante:** no separes la carpeta `api/` del resto de archivos — deben estar siempre juntos en el mismo directorio, ya que la web llama a la API con rutas relativas (`api/endpoints/...`).

### Contraseña de administración por defecto

```
admin1234
```

**El sistema te obligará a cambiarla** la primera vez que inicies sesión — no podrás usar el resto de la app hasta hacerlo.

---

## Seguridad incluida

- **Toda la aplicación requiere login** (no solo el panel de administración) — nadie puede ver tu saldo ni registrar movimientos sin la contraseña.
- Cambio de contraseña **obligatorio en el primer acceso**, exigido tanto en la interfaz como en el propio backend (no es solo una barrera visual).
- **Límite de intentos de login**: tras 5 intentos fallidos, se bloquea el acceso durante 5 minutos.
- Protección **CSRF** en todas las operaciones que modifican datos.
- Sesión con expiración automática tras 2 horas de inactividad, modo estricto (`session.use_strict_mode`) y regeneración de ID tras el login.
- Cookie de sesión `HttpOnly`, `SameSite=Strict`, y `Secure` automático en cuanto sirvas por HTTPS.
- Cabeceras HTTP: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`.
- Escritura de datos **atómica** (archivo temporal + sustitución), evitando corrupción de `store.json` si el proceso se interrumpe a mitad de guardado.
- **Todo el ciclo leer → modificar → guardar ocurre bajo un bloqueo exclusivo** (`api/data/store.lock`), no solo la escritura — esto evita que dos peticiones simultáneas (por ejemplo, dos altas de saldo al mismo tiempo) se pisen entre sí y se pierda un movimiento. Probado con 20 peticiones concurrentes reales sin pérdida de datos.
- Las URLs de bancos solo aceptan `https://` (bloquea esquemas peligrosos como `javascript:`), con límites de longitud y de cantidad (máx. 20 bancos, 20 conceptos de distribución).
- Compatible con **PHP 7.4 o superior** (sin funciones exclusivas de PHP 8).

---

## Uso diario

### Cargar un ahorro
En la página principal, sección "Saldo e ingresos": ingresa el monto (usa un número negativo para registrar un retiro) y opcionalmente una nota. El saldo total y la distribución se recalculan al instante.

### Configurar bancos
Panel de administración → "Bancos y redirección": añade el nombre y la URL de cada banco, y marca cuáles están activos (solo los activos aparecen en el simulador).

### Configurar la distribución
Panel de administración → "Distribución automática":
- Elige el modo **Porcentaje** (la suma no puede superar 100%) o **Monto fijo**.
- Añade los conceptos que quieras (ej. Servicios, Cuotas, Metas) y su valor.
- Guarda: la página principal mostrará automáticamente cuánto le corresponde a cada concepto según el saldo actual.

---

## Estructura del proyecto

```
index.html               → web principal
admin.html                → panel de administración
assets/css/style.css
assets/js/app.js            → lógica de la web principal
assets/js/admin.js           → lógica del panel de administración

api/                      → backend PHP
  config.php                 → configuración y sesión
  db.php                      → lectura/escritura de datos + cálculo de distribución
  data/store.json              → aquí se guardan bancos, saldos y distribución
  endpoints/                  → un archivo PHP por acción (get_data, add_balance, admin_login, etc.)
```

Todo el proyecto es una sola carpeta autocontenida: se sube entera, junta, a donde quieras servirla.

## Nota para hosting con Nginx

Si tu hosting usa Nginx en vez de Apache, los archivos `.htaccess` no tienen efecto. Pide a tu proveedor (o indícame) que bloquee el acceso directo a `api/data/` añadiendo algo como:

```nginx
location /api/data/ { deny all; }
```

---

## Soporte

Cualquier ajuste sobre montos, textos o estilo lo puedo dejar preparado en la entrega. Para dudas de uso del panel de administración, esta guía cubre el flujo completo; si necesitas algo adicional, aquí estoy.
