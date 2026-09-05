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
$password = (string) ($body['password'] ?? '');

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$userId]);
$hash = $stmt->fetchColumn();

if (!password_verify($password, $hash)) {
    respond(['error' => 'La contraseña no es correcta.'], 401);
}

$pdo->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')->execute([$userId]);

respond(['ok' => true]);
