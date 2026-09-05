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
$goals = $body['goals'] ?? null;

if (!is_array($goals)) {
    respond(['error' => 'Formato de objetivos inválido.'], 422);
}
if (count($goals) > 20) {
    respond(['error' => 'No se permiten más de 20 objetivos.'], 422);
}

$clean = [];
foreach ($goals as $goal) {
    $name = trim((string) ($goal['name'] ?? ''));
    $targetCents = eurosToCents($goal['target_amount'] ?? null);

    if ($name === '' || $targetCents === null) {
        continue;
    }
    if (strlen($name) > 100) {
        respond(['error' => "El nombre del objetivo \"$name\" supera los 100 caracteres."], 422);
    }
    if ($targetCents <= 0 || $targetCents > 10000000 * 100) {
        respond(['error' => "La meta de \"$name\" debe ser mayor que 0 y razonable."], 422);
    }

    $id = isset($goal['id']) && (int) $goal['id'] > 0 ? (int) $goal['id'] : null;
    $clean[] = ['id' => $id, 'name' => $name, 'target_cents' => $targetCents];
}

$pdo->beginTransaction();
try {
    $existingStmt = $pdo->prepare('SELECT id FROM goals WHERE user_id = ?');
    $existingStmt->execute([$userId]);
    $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

    $keptIds = [];
    $updateStmt = $pdo->prepare('UPDATE goals SET name = ?, target_amount_cents = ? WHERE id = ? AND user_id = ?');
    $insertStmt = $pdo->prepare('INSERT INTO goals (user_id, name, target_amount_cents, saved_amount_cents) VALUES (?, ?, ?, 0)');

    foreach ($clean as $g) {
        if ($g['id'] !== null && in_array($g['id'], $existingIds, true)) {
            $updateStmt->execute([$g['name'], $g['target_cents'], $g['id'], $userId]);
            $keptIds[] = $g['id'];
        } else {
            $insertStmt->execute([$userId, $g['name'], $g['target_cents']]);
            $keptIds[] = (int) $pdo->lastInsertId();
        }
    }

    $toDelete = array_diff($existingIds, $keptIds);
    if ($toDelete) {
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $pdo->prepare("DELETE FROM goals WHERE user_id = ? AND id IN ($placeholders)")
            ->execute(array_merge([$userId], array_values($toDelete)));
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['error' => 'No se pudieron guardar los objetivos.'], 500);
}

respond(['ok' => true, 'goals' => computeGoalsProgress($pdo, $userId)]);
