<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

$lock = acquireStoreLock();
$data = loadStore();
$security = $data['admin']['security'] ?? ['failed_attempts' => 0, 'locked_until' => 0];

// --- Límite de intentos ---
if (!empty($security['locked_until']) && time() < $security['locked_until']) {
    $wait = $security['locked_until'] - time();
    releaseStoreLock($lock);
    respond(['error' => "Demasiados intentos fallidos. Espera " . ceil($wait / 60) . " minuto(s) antes de volver a intentarlo."], 429);
}

$body = readJsonBody();
$password = (string) ($body['password'] ?? '');

$hash = $data['admin']['password_hash'] ?? '';

if ($password === '' || !password_verify($password, $hash)) {
    $security['failed_attempts'] = ($security['failed_attempts'] ?? 0) + 1;

    if ($security['failed_attempts'] >= LOGIN_MAX_ATTEMPTS) {
        $security['locked_until'] = time() + LOGIN_LOCKOUT_SECONDS;
        $security['failed_attempts'] = 0;
    }

    $data['admin']['security'] = $security;
    saveStore($data);
    releaseStoreLock($lock);

    respond(['error' => 'Contraseña incorrecta.'], 401);
}

// --- Login correcto: resetear intentos, regenerar sesión, emitir CSRF token ---
$data['admin']['security'] = ['failed_attempts' => 0, 'locked_until' => 0];
saveStore($data);
releaseStoreLock($lock);

session_regenerate_id(true);
$_SESSION['is_admin'] = true;
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

respond([
    'ok' => true,
    'csrf_token' => $_SESSION['csrf_token'],
    'must_change_password' => !empty($data['admin']['must_change_password']),
]);
