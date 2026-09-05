<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '' || strlen($username) < 3 || strlen($username) > 40) {
    respond(['error' => 'El usuario debe tener entre 3 y 40 caracteres.'], 422);
}
if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
    respond(['error' => 'El usuario solo puede contener letras, números, punto, guion y guion bajo.'], 422);
}
if (strlen($password) < 8) {
    respond(['error' => 'La contraseña debe tener al menos 8 caracteres.'], 422);
}

$pdo = getPDO();

$stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetchColumn()) {
    respond(['error' => 'Ese nombre de usuario ya está en uso.'], 409);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, must_change_password) VALUES (?, ?, 0)');
    $stmt->execute([$username, $hash]);
    $userId = (int) $pdo->lastInsertId();

    // Categorías de ejemplo para que la cuenta nueva no arranque totalmente vacía.
    $defaultCategories = ['Ahorro', 'Servicios', 'Ocio', 'Transporte', 'Otros'];
    $catStmt = $pdo->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
    foreach ($defaultCategories as $name) {
        $catStmt->execute([$userId, $name]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['error' => 'No se pudo crear la cuenta.'], 500);
}

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['username'] = $username;
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

respond(['ok' => true, 'csrf_token' => $_SESSION['csrf_token'], 'must_change_password' => false, 'username' => $username]);
