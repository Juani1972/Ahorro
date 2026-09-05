<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = getPDO();
$userId = requireAuth();
requirePasswordChanged($pdo, $userId);

$stmt = $pdo->prepare('SELECT id, name, url, active FROM banks WHERE user_id = ? AND active = 1 ORDER BY id');
$stmt->execute([$userId]);
$banks = array_map(fn($b) => [
    'id' => (int) $b['id'], 'name' => $b['name'], 'url' => $b['url'], 'active' => (bool) $b['active'],
], $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$categories = array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT id, date, amount_cents, note, category_id FROM movements WHERE user_id = ? ORDER BY date DESC, id DESC');
$stmt->execute([$userId]);
$history = array_map(fn($m) => [
    'id' => (int) $m['id'],
    'date' => $m['date'],
    'amount' => centsToEuros((int) $m['amount_cents']),
    'note' => $m['note'],
    'category_id' => $m['category_id'] !== null ? (int) $m['category_id'] : null,
], $stmt->fetchAll(PDO::FETCH_ASSOC));

respond([
    'banks' => $banks,
    'categories' => $categories,
    'balance' => [
        'total' => centsToEuros(computeTotalBalanceCents($pdo, $userId)),
        'history' => $history,
    ],
    'distribution' => computeDistribution($pdo, $userId),
    'goals' => computeGoalsProgress($pdo, $userId),
    'budgets' => computeBudgetsStatus($pdo, $userId),
    'stats' => computeMonthlyStats($pdo, $userId),
    'balance_series' => computeBalanceSeries($pdo, $userId),
]);
