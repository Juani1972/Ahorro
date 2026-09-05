<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = getPDO();
$userId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

requireCsrf();

$body = readJsonBody();
$current = (string) ($body['current_password'] ?? '');
$new = (string) ($body['new_password'] ?? '');

if (strlen($new) < 8) {
    respond(['error' => 'La nueva contraseña debe tener al menos 8 caracteres.'], 422);
}

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$userId]);
$hash = $stmt->fetchColumn();

if (!password_verify($current, $hash)) {
    respond(['error' => 'La contraseña actual no es correcta.'], 401);
}

$newHash = password_hash($new, PASSWORD_BCRYPT);
$pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
    ->execute([$newHash, $userId]);

respond(['ok' => true]);
