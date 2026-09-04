<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

requireAuth();
requireCsrf();
requirePasswordChanged();

$body = readJsonBody();
$rawAmount = $body['amount'] ?? null;
$amount = is_numeric($rawAmount) ? (float) $rawAmount : null;
$note = trim((string) ($body['note'] ?? ''));

if ($amount === null || $amount === 0.0) {
    respond(['error' => 'El monto es obligatorio y debe ser distinto de cero.'], 422);
}
if (abs($amount) > 1000000) {
    respond(['error' => 'El monto supera el máximo permitido (1.000.000).'], 422);
}
if (strlen($note) > 200) {
    respond(['error' => 'El concepto no puede superar 200 caracteres.'], 422);
}

// Todo el ciclo leer → modificar → guardar ocurre bajo un único bloqueo exclusivo,
// para que dos peticiones simultáneas no puedan pisarse el saldo.
$lock = acquireStoreLock();
$data = loadStore();

$nextId = 1;
foreach ($data['balance']['history'] as $entry) {
    $nextId = max($nextId, $entry['id'] + 1);
}

$data['balance']['history'][] = [
    'id' => $nextId,
    'date' => date('Y-m-d'),
    'amount' => round($amount, 2),
    'note' => $note !== '' ? $note : 'Movimiento manual',
];

saveStore($data);
releaseStoreLock($lock);

respond([
    'ok' => true,
    'balance' => [
        'total' => computeTotalBalance($data),
        'history' => array_reverse($data['balance']['history']),
    ],
    'distribution' => computeDistribution($data),
]);
