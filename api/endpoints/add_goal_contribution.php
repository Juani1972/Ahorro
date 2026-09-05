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

if (!$id) {
    respond(['error' => 'Falta el id del objetivo.'], 422);
}
if ($amountCents === null || $amountCents === 0) {
    respond(['error' => 'El monto de la contribución es obligatorio y distinto de cero.'], 422);
}
if (abs($amountCents) > 1000000 * 100) {
    respond(['error' => 'El monto supera el máximo permitido.'], 422);
}

// UPDATE con expresión: SQLite aplica esto de forma atómica, sin necesidad
// de leer-modificar-guardar manualmente ni de locks propios. MAX(0, ...)
// evita que el ahorrado quede negativo.
$stmt = $pdo->prepare(
    'UPDATE goals SET saved_amount_cents = MAX(0, saved_amount_cents + ?) WHERE id = ? AND user_id = ?'
);
$stmt->execute([$amountCents, $id, $userId]);

if ($stmt->rowCount() === 0) {
    respond(['error' => 'No se encontró el objetivo indicado.'], 404);
}

respond(['ok' => true, 'goals' => computeGoalsProgress($pdo, $userId)]);
