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
$backup = $body['backup'] ?? null;

if (!is_array($backup) || ($backup['app'] ?? null) !== 'arca' || ($backup['version'] ?? null) !== 1) {
    respond(['error' => 'El archivo no parece ser una copia de seguridad válida de Arca.'], 422);
}

// --- Validar y limpiar cada sección, sin tocar la base de datos todavía ---

$banks = is_array($backup['banks'] ?? null) ? $backup['banks'] : [];
if (count($banks) > 20) {
    respond(['error' => 'La copia tiene más de 20 bancos; no se puede restaurar.'], 422);
}
$cleanBanks = [];
foreach ($banks as $b) {
    $name = trim((string) ($b['name'] ?? ''));
    $url = trim((string) ($b['url'] ?? ''));
    if ($name === '' || $url === '' || strlen($name) > 100 || strlen($url) > 2048) {
        continue;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || strpos($url, 'https://') !== 0) {
        continue; // se omite silenciosamente un banco inválido, en vez de abortar todo el restore
    }
    $cleanBanks[] = ['name' => $name, 'url' => $url, 'active' => !empty($b['active'])];
}

$categories = is_array($backup['categories'] ?? null) ? $backup['categories'] : [];
if (count($categories) > 30) {
    respond(['error' => 'La copia tiene más de 30 categorías; no se puede restaurar.'], 422);
}
$cleanCategoryNames = [];
$seenCat = [];
foreach ($categories as $c) {
    $name = trim((string) ($c['name'] ?? ''));
    if ($name === '' || strlen($name) > 60) {
        continue;
    }
    $key = strtolower($name);
    if (isset($seenCat[$key])) {
        continue;
    }
    $seenCat[$key] = true;
    $cleanCategoryNames[] = $name;
}

$distRaw = is_array($backup['distribution'] ?? null) ? $backup['distribution'] : [];
$distMode = in_array($distRaw['mode'] ?? null, ['percentage', 'fixed'], true) ? $distRaw['mode'] : 'percentage';
$distItemsRaw = is_array($distRaw['items'] ?? null) ? $distRaw['items'] : [];
if (count($distItemsRaw) > 20) {
    respond(['error' => 'La copia tiene más de 20 conceptos de distribución; no se puede restaurar.'], 422);
}
$cleanDist = [];
foreach ($distItemsRaw as $it) {
    $concept = trim((string) ($it['concept'] ?? ''));
    $value = is_numeric($it['value'] ?? null) ? (float) $it['value'] : null;
    if ($concept === '' || $value === null || $value < 0 || strlen($concept) > 100) {
        continue;
    }
    if ($distMode === 'percentage' && $value > 100) {
        continue;
    }
    $cleanDist[] = ['concept' => $concept, 'scaled_value' => (int) round($value * 100)];
}

$movements = is_array($backup['movements'] ?? null) ? $backup['movements'] : [];
if (count($movements) > 5000) {
    respond(['error' => 'La copia tiene más de 5000 movimientos; no se puede restaurar.'], 422);
}
$cleanMovements = [];
foreach ($movements as $m) {
    $date = trim((string) ($m['date'] ?? ''));
    $amountCents = eurosToCents($m['amount'] ?? null);
    $note = strip_tags(trim((string) ($m['note'] ?? '')));
    if (!isValidHistoryDate($date) || $amountCents === null || $amountCents === 0 || strlen($note) > 200) {
        continue; // movimiento corrupto: se omite, no aborta el resto del restore
    }
    $cleanMovements[] = [
        'date' => $date,
        'amount_cents' => $amountCents,
        'note' => $note !== '' ? $note : 'Movimiento manual',
        'category_name' => is_string($m['category_name'] ?? null) ? $m['category_name'] : null,
    ];
}

$goals = is_array($backup['goals'] ?? null) ? $backup['goals'] : [];
if (count($goals) > 20) {
    respond(['error' => 'La copia tiene más de 20 objetivos; no se puede restaurar.'], 422);
}
$cleanGoals = [];
foreach ($goals as $g) {
    $name = trim((string) ($g['name'] ?? ''));
    $targetCents = eurosToCents($g['target_amount'] ?? null);
    $savedCents = eurosToCents($g['saved_amount'] ?? 0) ?? 0;
    if ($name === '' || $targetCents === null || $targetCents <= 0 || strlen($name) > 100) {
        continue;
    }
    $cleanGoals[] = ['name' => $name, 'target_cents' => $targetCents, 'saved_cents' => max(0, $savedCents)];
}

$budgets = is_array($backup['budgets'] ?? null) ? $backup['budgets'] : [];
if (count($budgets) > 30) {
    respond(['error' => 'La copia tiene más de 30 presupuestos; no se puede restaurar.'], 422);
}
$cleanBudgets = [];
foreach ($budgets as $b) {
    $catName = is_string($b['category_name'] ?? null) ? $b['category_name'] : null;
    $limitCents = eurosToCents($b['limit_amount'] ?? null);
    if ($catName === null || $limitCents === null || $limitCents <= 0) {
        continue;
    }
    $cleanBudgets[] = ['category_name' => $catName, 'limit_cents' => $limitCents];
}

// --- A partir de aquí, todo o nada: transacción única ---

$pdo->beginTransaction();
try {
    // Se borra todo lo anterior del usuario (la fila de users, con su login, se conserva).
    foreach (['banks', 'categories', 'movements', 'distribution_items', 'goals', 'budgets'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$userId]);
    }

    $bankIns = $pdo->prepare('INSERT INTO banks (user_id, name, url, active) VALUES (?, ?, ?, ?)');
    foreach ($cleanBanks as $b) {
        $bankIns->execute([$userId, $b['name'], $b['url'], $b['active'] ? 1 : 0]);
    }

    $catIns = $pdo->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
    $categoryIdByName = [];
    foreach ($cleanCategoryNames as $name) {
        $catIns->execute([$userId, $name]);
        $categoryIdByName[strtolower($name)] = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('UPDATE users SET distribution_mode = ? WHERE id = ?')->execute([$distMode, $userId]);
    $distIns = $pdo->prepare('INSERT INTO distribution_items (user_id, concept, scaled_value) VALUES (?, ?, ?)');
    foreach ($cleanDist as $it) {
        $distIns->execute([$userId, $it['concept'], $it['scaled_value']]);
    }

    $movIns = $pdo->prepare('INSERT INTO movements (user_id, date, amount_cents, note, category_id) VALUES (?, ?, ?, ?, ?)');
    $importedMovements = 0;
    foreach ($cleanMovements as $m) {
        $catId = null;
        if ($m['category_name'] !== null) {
            $catId = $categoryIdByName[strtolower($m['category_name'])] ?? null;
        }
        $movIns->execute([$userId, $m['date'], $m['amount_cents'], $m['note'], $catId]);
        $importedMovements++;
    }

    $goalIns = $pdo->prepare('INSERT INTO goals (user_id, name, target_amount_cents, saved_amount_cents) VALUES (?, ?, ?, ?)');
    foreach ($cleanGoals as $g) {
        $goalIns->execute([$userId, $g['name'], $g['target_cents'], $g['saved_cents']]);
    }

    $budgetIns = $pdo->prepare('INSERT INTO budgets (user_id, category_id, limit_amount_cents) VALUES (?, ?, ?)');
    $importedBudgets = 0;
    $usedCategories = [];
    foreach ($cleanBudgets as $b) {
        $catId = $categoryIdByName[strtolower($b['category_name'])] ?? null;
        // Respeta la restricción UNIQUE(user_id, category_id): como mucho un
        // presupuesto por categoría, igual que en la app normal.
        if ($catId === null || isset($usedCategories[$catId])) {
            continue;
        }
        $usedCategories[$catId] = true;
        $budgetIns->execute([$userId, $catId, $b['limit_cents']]);
        $importedBudgets++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['error' => 'No se pudo restaurar la copia de seguridad.'], 500);
}

respond([
    'ok' => true,
    'summary' => [
        'banks' => count($cleanBanks),
        'categories' => count($cleanCategoryNames),
        'movements' => $importedMovements,
        'goals' => count($cleanGoals),
        'budgets' => $importedBudgets,
    ],
]);
