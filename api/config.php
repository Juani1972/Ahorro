<?php
/**
 * Configuración central de la API (v2 — SQLite + multiusuario).
 */

declare(strict_types=1);

// --- Sesión ---
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => $isHttps,
    ]);
    session_start();
}

const SESSION_MAX_IDLE_SECONDS = 2 * 60 * 60;
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 5 * 60;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

// La ruta de la base de datos se puede sobreescribir con la variable de
// entorno ARCA_DB_FILE — la usan los tests de integración para trabajar
// siempre sobre un archivo temporal y no arriesgarse jamás a tocar datos
// reales de una instalación ya en uso.
define('DB_FILE', getenv('ARCA_DB_FILE') ?: __DIR__ . '/data/arca.sqlite');
define('SCHEMA_FILE', __DIR__ . '/data/schema.sql');

function respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

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
 * Comprueba que hay una sesión de usuario activa y no expirada por inactividad.
 * Deja el user_id disponible como valor de retorno para comodidad del endpoint.
 */
function requireAuth(): int
{
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'No autorizado. Inicia sesión primero.'], 401);
    }

    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if (time() - $lastActivity > SESSION_MAX_IDLE_SECONDS) {
        $_SESSION = [];
        session_destroy();
        respond(['error' => 'Tu sesión ha expirado por inactividad. Inicia sesión de nuevo.'], 401);
    }

    $_SESSION['last_activity'] = time();
    return (int) $_SESSION['user_id'];
}

function requireCsrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        respond(['error' => 'Token de seguridad inválido o ausente. Recarga la página e inténtalo de nuevo.'], 403);
    }
}

/**
 * Convierte un monto en euros (float o string, admite coma decimal) a
 * céntimos enteros. Devuelve null si el valor no es numérico.
 */
function eurosToCents($rawEuros): ?int
{
    if (is_string($rawEuros)) {
        $rawEuros = str_replace(',', '.', trim($rawEuros));
    }
    if (!is_numeric($rawEuros)) {
        return null;
    }
    return (int) round(((float) $rawEuros) * 100);
}

function centsToEuros(int $cents): float
{
    return round($cents / 100, 2);
}
