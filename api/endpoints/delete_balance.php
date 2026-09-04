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
$id = isset($body['id']) ? (int) $body['id'] : null;

if (!$id) {
    respond(['error' => 'Falta el id del movimiento.'], 422);
}

$lock = acquireStoreLock();
$data = loadStore();

$data['balance']['history'] = array_values(array_filter(
    $data['balance']['history'],
    fn($entry) => $entry['id'] !== $id
));

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
