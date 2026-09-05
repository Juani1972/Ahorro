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

$stmt = $pdo->prepare('SELECT username, totp_enabled FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ((int) $user['totp_enabled'] === 1) {
    respond(['error' => 'La verificación en dos pasos ya está activada. Desactívala primero si quieres generar una clave nueva.'], 409);
}

// Genera un secreto nuevo y lo guarda como "pendiente" (totp_enabled sigue en 0
// hasta que el usuario confirme con un código real desde su app autenticadora).
$secret = totpGenerateSecret();
$pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([$secret, $userId]);

respond([
    'ok' => true,
    'secret' => $secret,
    'otpauth_uri' => totpProvisioningUri($secret, $user['username'], 'Arca'),
]);
