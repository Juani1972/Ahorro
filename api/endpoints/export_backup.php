<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = getPDO();
$userId = requireAuth();
requirePasswordChanged($pdo, $userId);

$stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
$stmt->execute([$userId]);
$username = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT name, url, active FROM banks WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$banks = array_map(fn($b) => ['name' => $b['name'], 'url' => $b['url'], 'active' => (bool) $b['active']], $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$categoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categoryNameById = [];
foreach ($categoryRows as $c) {
    $categoryNameById[(int) $c['id']] = $c['name'];
}
$categories = array_map(fn($c) => ['name' => $c['name']], $categoryRows);

$stmt = $pdo->prepare('SELECT date, amount_cents, note, category_id FROM movements WHERE user_id = ? ORDER BY date, id');
$stmt->execute([$userId]);
$movements = array_map(function ($m) use ($categoryNameById) {
    $catId = $m['category_id'] !== null ? (int) $m['category_id'] : null;
    return [
        'date' => $m['date'],
        'amount' => centsToEuros((int) $m['amount_cents']),
        'note' => $m['note'],
        'category_name' => $catId !== null ? ($categoryNameById[$catId] ?? null) : null,
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT distribution_mode FROM users WHERE id = ?');
$stmt->execute([$userId]);
$mode = $stmt->fetchColumn() ?: 'percentage';

$stmt = $pdo->prepare('SELECT concept, scaled_value FROM distribution_items WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$distItems = array_map(function ($it) use ($mode) {
    $value = $mode === 'percentage' ? round($it['scaled_value'] / 100, 2) : centsToEuros((int) $it['scaled_value']);
    return ['concept' => $it['concept'], 'value' => $value];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT name, target_amount_cents, saved_amount_cents FROM goals WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$goals = array_map(fn($g) => [
    'name' => $g['name'],
    'target_amount' => centsToEuros((int) $g['target_amount_cents']),
    'saved_amount' => centsToEuros((int) $g['saved_amount_cents']),
], $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->prepare('SELECT category_id, limit_amount_cents FROM budgets WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$budgets = array_map(function ($b) use ($categoryNameById) {
    return [
        'category_name' => $categoryNameById[(int) $b['category_id']] ?? null,
        'limit_amount' => centsToEuros((int) $b['limit_amount_cents']),
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$backup = [
    'version' => 1,
    'app' => 'arca',
    'exported_at' => date('c'),
    'username' => $username,
    'banks' => $banks,
    'categories' => $categories,
    'distribution' => ['mode' => $mode, 'items' => $distItems],
    'movements' => $movements,
    'goals' => $goals,
    'budgets' => $budgets,
];

$json = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$filename = 'arca-backup-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $username) . '-' . date('Y-m-d') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
echo $json;
