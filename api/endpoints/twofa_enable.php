<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../totp.php';

$pdo = getPDO();
$userId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

requireCsrf();
requirePasswordChanged($pdo, $userId);

$body = readJsonBody();
$code = trim((string) ($body['code'] ?? ''));

$stmt = $pdo->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ((int) $user['totp_enabled'] === 1) {
    respond(['error' => 'La verificación en dos pasos ya está activada.'], 409);
}
if (empty($user['totp_secret'])) {
    respond(['error' => 'Primero genera una clave desde "Configurar verificación en dos pasos".'], 422);
}
if (!totpVerifyCode($user['totp_secret'], $code)) {
    respond(['error' => 'El código no es correcto. Revisa la hora de tu móvil e inténtalo de nuevo.'], 401);
}

$pdo->prepare('UPDATE users SET totp_enabled = 1 WHERE id = ?')->execute([$userId]);

respond(['ok' => true]);
