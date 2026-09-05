<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

$pdo = getPDO();

$stmt = $pdo->prepare('SELECT id, password_hash, must_change_password, failed_attempts, locked_until, totp_enabled FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Mensaje genérico tanto si el usuario no existe como si la contraseña es
// incorrecta, para no revelar qué nombres de usuario están registrados.
$genericError = 'Usuario o contraseña incorrectos.';

if (!$user) {
    respond(['error' => $genericError], 401);
}

if (!empty($user['locked_until']) && time() < (int) $user['locked_until']) {
    $wait = (int) $user['locked_until'] - time();
    respond(['error' => 'Demasiados intentos fallidos. Espera ' . ceil($wait / 60) . ' minuto(s) antes de volver a intentarlo.'], 429);
}

if (!password_verify($password, $user['password_hash'])) {
    $attempts = (int) $user['failed_attempts'] + 1;
    $lockedUntil = 0;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $lockedUntil = time() + LOGIN_LOCKOUT_SECONDS;
        $attempts = 0;
    }
    $upd = $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
    $upd->execute([$attempts, $lockedUntil, $user['id']]);

    respond(['error' => $genericError], 401);
}

$reset = $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = 0 WHERE id = ?');
$reset->execute([$user['id']]);

if ((int) $user['totp_enabled'] === 1) {
    // Contraseña correcta, pero falta el segundo factor: no se concede sesión
    // completa todavía. Solo guardamos qué usuario está a mitad de login.
    session_regenerate_id(true);
    $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
    $_SESSION['pending_2fa_username'] = $username;
    respond(['ok' => true, 'requires_2fa' => true]);
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $username;
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

respond([
    'ok' => true,
    'csrf_token' => $_SESSION['csrf_token'],
    'must_change_password' => (bool) $user['must_change_password'],
    'username' => $username,
]);
