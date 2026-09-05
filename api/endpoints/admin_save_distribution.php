<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = getPDO();
$userId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

requireCsrf();
requirePasswordChanged($pdo, $userId);

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

$clean = [];
$percentSum = 0.0;
foreach ($items as $item) {
    $concept = trim((string) ($item['concept'] ?? ''));
    $rawValue = $item['value'] ?? null;
    if (is_string($rawValue)) {
        $rawValue = str_replace(',', '.', trim($rawValue));
    }
    $value = is_numeric($rawValue) ? (float) $rawValue : null;

    if ($concept === '' || $value === null || $value < 0) {
        continue;
    }
    if (strlen($concept) > 100) {
        respond(['error' => "El concepto \"$concept\" supera los 100 caracteres."], 422);
    }
    if ($mode === 'percentage' && $value > 100) {
        respond(['error' => "El porcentaje de \"$concept\" no puede superar 100%."], 422);
    }

    $percentSum += $mode === 'percentage' ? $value : 0;
    // scaled_value: percentage*100 (centipuntos) o euros->céntimos si es fijo.
    $scaledValue = $mode === 'percentage' ? (int) round($value * 100) : (int) round($value * 100);
    $clean[] = ['concept' => $concept, 'scaled_value' => $scaledValue];
}

if ($mode === 'percentage' && $percentSum > 100) {
    respond(['error' => "La suma de porcentajes ($percentSum%) no puede superar 100%."], 422);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET distribution_mode = ? WHERE id = ?')->execute([$mode, $userId]);

    $pdo->prepare('DELETE FROM distribution_items WHERE user_id = ?')->execute([$userId]);

    $ins = $pdo->prepare('INSERT INTO distribution_items (user_id, concept, scaled_value) VALUES (?, ?, ?)');
    foreach ($clean as $it) {
        $ins->execute([$userId, $it['concept'], $it['scaled_value']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['error' => 'No se pudo guardar la distribución.'], 500);
}

respond(['ok' => true, 'distribution' => computeDistribution($pdo, $userId)]);
