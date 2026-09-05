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
$amountCents = eurosToCents($body['amount'] ?? null);
$note = strip_tags(trim((string) ($body['note'] ?? '')));
$rawDate = trim((string) ($body['date'] ?? ''));
$rawCategoryId = $body['category_id'] ?? null;

if (!$id) {
    respond(['error' => 'Falta el id del movimiento a editar.'], 422);
}
if ($amountCents === null || $amountCents === 0) {
    respond(['error' => 'El monto es obligatorio y debe ser distinto de cero.'], 422);
}
if (abs($amountCents) > 1000000 * 100) {
    respond(['error' => 'El monto supera el máximo permitido (1.000.000).'], 422);
}
if (strlen($note) > 200) {
    respond(['error' => 'El concepto no puede superar 200 caracteres.'], 422);
}
if ($rawDate === '' || !isValidHistoryDate($rawDate)) {
    respond(['error' => 'La fecha debe tener el formato AAAA-MM-DD y estar en un rango razonable.'], 422);
}

$categoryId = resolveCategoryId($pdo, $userId, $rawCategoryId);

$stmt = $pdo->prepare(
    'UPDATE movements SET amount_cents = ?, note = ?, date = ?, category_id = ? WHERE id = ? AND user_id = ?'
);
$stmt->execute([$amountCents, $note !== '' ? $note : 'Movimiento manual', $rawDate, $categoryId, $id, $userId]);

if ($stmt->rowCount() === 0) {
    respond(['error' => 'No se encontró el movimiento indicado.'], 404);
}

respond(['ok' => true]);
