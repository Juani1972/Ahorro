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
$budgets = $body['budgets'] ?? null;

if (!is_array($budgets)) {
    respond(['error' => 'Formato de presupuestos inválido.'], 422);
}
if (count($budgets) > 30) {
    respond(['error' => 'No se permiten más de 30 presupuestos.'], 422);
}

$clean = [];
$seenCategories = [];
foreach ($budgets as $b) {
    $categoryId = isset($b['category_id']) ? (int) $b['category_id'] : null;
    $limitCents = eurosToCents($b['limit_amount'] ?? null);

    if (!$categoryId || $limitCents === null) {
        continue;
    }
    if ($limitCents <= 0 || $limitCents > 1000000 * 100) {
        respond(['error' => 'El límite del presupuesto debe ser mayor que 0 y razonable.'], 422);
    }
    if (isset($seenCategories[$categoryId])) {
        respond(['error' => 'Solo puede haber un presupuesto por categoría.'], 422);
    }
    $seenCategories[$categoryId] = true;
    $clean[] = ['category_id' => $categoryId, 'limit_cents' => $limitCents];
}

$pdo->beginTransaction();
try {
    // Validamos que las categorías pertenezcan al usuario antes de insertar.
    $catCheck = $pdo->prepare('SELECT 1 FROM categories WHERE id = ? AND user_id = ?');
    foreach ($clean as $b) {
        $catCheck->execute([$b['category_id'], $userId]);
        if (!$catCheck->fetchColumn()) {
            $pdo->rollBack();
            respond(['error' => 'Una de las categorías del presupuesto ya no existe.'], 422);
        }
    }

    $pdo->prepare('DELETE FROM budgets WHERE user_id = ?')->execute([$userId]);

    $ins = $pdo->prepare('INSERT INTO budgets (user_id, category_id, limit_amount_cents) VALUES (?, ?, ?)');
    foreach ($clean as $b) {
        $ins->execute([$userId, $b['category_id'], $b['limit_cents']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['error' => 'No se pudieron guardar los presupuestos.'], 500);
}

respond(['ok' => true, 'budgets' => computeBudgetsStatus($pdo, $userId)]);
