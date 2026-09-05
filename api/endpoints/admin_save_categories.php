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
$categories = $body['categories'] ?? null;

if (!is_array($categories)) {
    respond(['error' => 'Formato de categorías inválido.'], 422);
}
if (count($categories) > 30) {
    respond(['error' => 'No se permiten más de 30 categorías.'], 422);
}

$clean = [];
$seen = [];
foreach ($categories as $cat) {
    $name = trim((string) ($cat['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    if (strlen($name) > 60) {
        respond(['error' => "El nombre de categoría \"$name\" supera los 60 caracteres."], 422);
    }
    $key = strtolower($name);
    if (isset($seen[$key])) {
        respond(['error' => "La categoría \"$name\" está repetida."], 422);
    }
    $seen[$key] = true;
    $id = isset($cat['id']) && (int) $cat['id'] > 0 ? (int) $cat['id'] : null;
    $clean[] = ['id' => $id, 'name' => $name];
}

// Importante: actualizamos las categorías existentes por id en vez de borrar
// y reinsertar todo. Si borráramos y volviéramos a crear con el mismo nombre,
// los presupuestos que las referencian se perderían (ON DELETE CASCADE) y los
// movimientos quedarían con category_id inconsistente.
$pdo->beginTransaction();
try {
    $existingStmt = $pdo->prepare('SELECT id FROM categories WHERE user_id = ?');
    $existingStmt->execute([$userId]);
    $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

    $keptIds = [];
    $updateStmt = $pdo->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?');
    $insertStmt = $pdo->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');

    foreach ($clean as $cat) {
        if ($cat['id'] !== null && in_array($cat['id'], $existingIds, true)) {
            $updateStmt->execute([$cat['name'], $cat['id'], $userId]);
            $keptIds[] = $cat['id'];
        } else {
            $insertStmt->execute([$userId, $cat['name']]);
            $keptIds[] = (int) $pdo->lastInsertId();
        }
    }

    $toDelete = array_diff($existingIds, $keptIds);
    if ($toDelete) {
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $delStmt = $pdo->prepare("DELETE FROM categories WHERE user_id = ? AND id IN ($placeholders)");
        $delStmt->execute(array_merge([$userId], array_values($toDelete)));
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['error' => 'No se pudieron guardar las categorías.'], 500);
}

respond(['ok' => true]);
