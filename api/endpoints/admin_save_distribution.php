<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

requireCsrf();
requirePasswordChanged();

$body = readJsonBody();
$mode = $body['mode'] ?? 'percentage';
$items = $body['items'] ?? null;

if (!in_array($mode, ['percentage', 'fixed'], true)) {
    respond(['error' => 'Modo de distribución inválido.'], 422);
}
if (!is_array($items)) {
    respond(['error' => 'Formato de conceptos inválido.'], 422);
}
if (count($items) > 20) {
    respond(['error' => 'No se permiten más de 20 conceptos de distribución.'], 422);
}

$nextId = 1;
foreach ($items as $item) {
    if (isset($item['id']) && (int) $item['id'] > 0) {
        $nextId = max($nextId, (int) $item['id'] + 1);
    }
}

$clean = [];
$percentSum = 0.0;

foreach ($items as $item) {
    $concept = trim((string) ($item['concept'] ?? ''));
    $value = isset($item['value']) ? (float) $item['value'] : null;

    if ($concept === '' || $value === null || $value < 0) {
        continue;
    }
    if (strlen($concept) > 100) {
        respond(['error' => "El concepto \"$concept\" supera los 100 caracteres."], 422);
    }
    if ($mode === 'percentage' && $value > 100) {
        respond(['error' => "El porcentaje de \"$concept\" no puede superar 100%."], 422);
    }

    $id = isset($item['id']) && (int) $item['id'] > 0 ? (int) $item['id'] : $nextId++;

    $percentSum += $mode === 'percentage' ? $value : 0;

    $clean[] = [
        'id' => $id,
        'concept' => $concept,
        'value' => $value,
    ];
}

if ($mode === 'percentage' && $percentSum > 100) {
    respond(['error' => "La suma de porcentajes ($percentSum%) no puede superar 100%."], 422);
}

$lock = acquireStoreLock();
$data = loadStore();
$data['distribution'] = [
    'mode' => $mode,
    'items' => $clean,
];
saveStore($data);
releaseStoreLock($lock);

respond(['ok' => true, 'distribution' => computeDistribution($data)]);
