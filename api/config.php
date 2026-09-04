<?php
/**
 * Configuración central de la API.
 * No requiere base de datos: los datos se guardan en api/data/store.json
 */

declare(strict_types=1);

// --- Sesión (usada para proteger toda la app: index.html y admin.html) ---
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1'); // rechaza IDs de sesión no generados por PHP
    session_set_cookie_params([
        'lifetime' => 0, // cookie de sesión: expira al cerrar el navegador
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => $isHttps, // true automáticamente en cuanto sirvas por HTTPS
    ]);
    session_start();
}

const SESSION_MAX_IDLE_SECONDS = 2 * 60 * 60; // 2 horas de inactividad máx.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 5 * 60; // 5 minutos de bloqueo tras agotar intentos

// --- Cabeceras comunes de la API ---
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
// Si el frontend se sirve desde el mismo dominio (recomendado) no hace falta CORS.
// Si lo sirves desde otro origen, descomenta y ajusta la siguiente línea:
// header('Access-Control-Allow-Origin: https://tu-dominio.com');

define('DATA_FILE', __DIR__ . '/data/store.json');

/**
 * Envía una respuesta JSON y termina la ejecución.
 */
function respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Lee el body JSON de la petición actual.
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Comprueba si el usuario tiene sesión activa y no ha expirado por inactividad.
 * Toda la app (lectura y escritura) vive detrás de este único login personal.
 */
function requireAuth(): void
{
    if (empty($_SESSION['is_admin'])) {
        respond(['error' => 'No autorizado. Inicia sesión primero.'], 401);
    }

    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if (time() - $lastActivity > SESSION_MAX_IDLE_SECONDS) {
        $_SESSION = [];
        session_destroy();
        respond(['error' => 'Tu sesión ha expirado por inactividad. Inicia sesión de nuevo.'], 401);
    }

    $_SESSION['last_activity'] = time();
}

/**
 * Comprueba que la petición incluya un token CSRF válido (cabecera X-CSRF-Token),
 * emitido al iniciar sesión. Debe llamarse siempre después de requireAuth().
 */
function requireCsrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        respond(['error' => 'Token de seguridad inválido o ausente. Recarga la página e inténtalo de nuevo.'], 403);
    }
}

/**
 * Bloquea el uso normal de la app hasta que se haya cambiado la contraseña
 * por defecto. Debe llamarse después de requireAuth() en todos los endpoints
 * salvo admin_login.php y admin_change_password.php.
 *
 * Importante: esta comprobación vive en el backend, no solo en el frontend —
 * el frontend puede guiar al usuario, pero no puede ser la única barrera de seguridad.
 */
function requirePasswordChanged(): void
{
    require_once __DIR__ . '/db.php';
    $data = loadStore();
    if (!empty($data['admin']['must_change_password'])) {
        respond(['error' => 'Debes cambiar la contraseña por defecto antes de continuar.', 'must_change_password' => true], 403);
    }
}
