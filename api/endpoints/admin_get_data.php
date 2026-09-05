<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = getPDO();
$userId = requireAuth();
requirePasswordChanged($pdo, $userId);

$stmt = $pdo->prepare('SELECT id, name, url, active FROM banks WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$banks = array_map(fn($b) => [
    'id' => (int) $b['id'], 'name' => $b['name'], 'url' => $b['url'], 'active' => (bool) $b['active'],
], $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$categories = array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT distribution_mode FROM users WHERE id = ?');
$stmt->execute([$userId]);
$mode = $stmt->fetchColumn() ?: 'percentage';

$stmt = $pdo->prepare('SELECT id, concept, scaled_value FROM distribution_items WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$distItems = array_map(function ($it) use ($mode) {
    $value = $mode === 'percentage' ? round($it['scaled_value'] / 100, 2) : centsToEuros((int) $it['scaled_value']);
    return ['id' => (int) $it['id'], 'concept' => $it['concept'], 'value' => $value];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT id, name, target_amount_cents, saved_amount_cents FROM goals WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$goals = array_map(fn($g) => [
    'id' => (int) $g['id'], 'name' => $g['name'],
    'target_amount' => centsToEuros((int) $g['target_amount_cents']),
], $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT id, category_id, limit_amount_cents FROM budgets WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$budgets = array_map(fn($b) => [
    'id' => (int) $b['id'], 'category_id' => (int) $b['category_id'],
    'limit_amount' => centsToEuros((int) $b['limit_amount_cents']),
], $stmt->fetchAll(PDO::FETCH_ASSOC));

respond([
    'banks' => $banks,
    'categories' => $categories,
    'distribution' => ['mode' => $mode, 'items' => $distItems],
    'goals' => $goals,
    'budgets' => $budgets,
    'balance_total' => centsToEuros(computeTotalBalanceCents($pdo, $userId)),
]);
