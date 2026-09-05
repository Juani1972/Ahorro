<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../totp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

if (empty($_SESSION['pending_2fa_user_id'])) {
    respond(['error' => 'No hay un inicio de sesión pendiente de verificación.'], 401);
}

$userId = (int) $_SESSION['pending_2fa_user_id'];
$username = $_SESSION['pending_2fa_username'];

$body = readJsonBody();
$code = trim((string) ($body['code'] ?? ''));

$pdo = getPDO();
$stmt = $pdo->prepare('SELECT totp_secret, must_change_password, failed_attempts, locked_until FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION = [];
    respond(['error' => 'Sesión inválida. Inicia sesión de nuevo.'], 401);
}

// Mismo mecanismo de bloqueo por intentos fallidos que en el login normal,
// reutilizando las mismas columnas — evita fuerza bruta contra el código TOTP.
if (!empty($user['locked_until']) && time() < (int) $user['locked_until']) {
    $wait = (int) $user['locked_until'] - time();
    respond(['error' => 'Demasiados intentos fallidos. Espera ' . ceil($wait / 60) . ' minuto(s) antes de volver a intentarlo.'], 429);
}

if (!totpVerifyCode((string) $user['totp_secret'], $code)) {
    $attempts = (int) $user['failed_attempts'] + 1;
    $lockedUntil = 0;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $lockedUntil = time() + LOGIN_LOCKOUT_SECONDS;
        $attempts = 0;
    }
    $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
        ->execute([$attempts, $lockedUntil, $userId]);

    respond(['error' => 'Código incorrecto.'], 401);
}

$pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = 0 WHERE id = ?')->execute([$userId]);

// Código correcto: ahora sí se concede la sesión completa.
unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);
session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['username'] = $username;
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

respond([
    'ok' => true,
    'csrf_token' => $_SESSION['csrf_token'],
    'must_change_password' => (bool) $user['must_change_password'],
    'username' => $username,
]);
