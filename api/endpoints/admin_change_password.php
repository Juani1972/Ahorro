<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

requireAuth();

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

$lock = acquireStoreLock();
$data = loadStore();

if (!password_verify($current, $data['admin']['password_hash'])) {
    releaseStoreLock($lock);
    respond(['error' => 'La contraseña actual no es correcta.'], 401);
}

$data['admin']['password_hash'] = password_hash($new, PASSWORD_BCRYPT);
$data['admin']['must_change_password'] = false;
saveStore($data);
releaseStoreLock($lock);

respond(['ok' => true]);
