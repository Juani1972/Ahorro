<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

$pdo = getPDO();
$userId = requireAuth();
requireCsrf();
requirePasswordChanged($pdo, $userId);

$body = readJsonBody();
$id = isset($body['id']) ? (int) $body['id'] : null;

if (!$id) {
    respond(['error' => 'Falta el id del movimiento.'], 422);
}

$stmt = $pdo->prepare('DELETE FROM movements WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $userId]);

respond(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
