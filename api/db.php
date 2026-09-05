<?php
/**
 * Acceso a la base de datos SQLite. Sustituye al store.json de la v1:
 * SQLite da transacciones e integridad reales, en vez de bloqueos manuales
 * de archivo sobre un blob JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Abre (o crea) la conexión PDO a SQLite, con WAL para mejor concurrencia
 * lectura/escritura y un busy_timeout para que las escrituras simultáneas
 * esperen su turno en vez de fallar inmediatamente con "database is locked".
 */
function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $isNew = !file_exists(DB_FILE);

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($isNew) {
        $schema = file_get_contents(SCHEMA_FILE);
        $pdo->exec($schema);
    } else {
        migrateSchema($pdo);
    }

    return $pdo;
}

/**
 * Migración ligera para bases de datos creadas con una versión anterior del
 * esquema: añade columnas nuevas si faltan, sin tocar los datos existentes.
 * Se comprueba en cada arranque; el coste es insignificante (una consulta
 * PRAGMA) comparado con la seguridad de no romper instalaciones ya en uso.
 */
function migrateSchema(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');

    if (!in_array('totp_secret', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT');
    }
    if (!in_array('totp_enabled', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0');
    }
}

/**
 * Bloquea el uso normal hasta que el usuario cambie la contraseña por
 * defecto asignada al registrarse (si aplica). Se comprueba en el backend,
 * no solo en el frontend.
 */
function requirePasswordChanged(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT must_change_password FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() === 1) {
        respond(['error' => 'Debes cambiar la contraseña por defecto antes de continuar.', 'must_change_password' => true], 403);
    }
}

/**
 * Valida que una fecha venga en formato YYYY-MM-DD, sea una fecha real
 * y esté en un rango razonable.
 */
function isValidHistoryDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return false;
    }
    $min = new DateTime('-10 years');
    $max = new DateTime('+1 year');
    return $d >= $min && $d <= $max;
}

/**
 * Valida un category_id contra las categorías del propio usuario.
 * Devuelve null si no se envió (es opcional), o el id validado.
 */
function resolveCategoryId(PDO $pdo, int $userId, $rawCategoryId): ?int
{
    if ($rawCategoryId === null || $rawCategoryId === '') {
        return null;
    }
    if (!is_numeric($rawCategoryId)) {
        respond(['error' => 'Categoría inválida.'], 422);
    }
    $id = (int) $rawCategoryId;
    $stmt = $pdo->prepare('SELECT 1 FROM categories WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetchColumn()) {
        respond(['error' => 'La categoría seleccionada no existe.'], 422);
    }
    return $id;
}

function computeTotalBalanceCents(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_cents), 0) FROM movements WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function computeDistribution(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT distribution_mode FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $mode = $stmt->fetchColumn() ?: 'percentage';

    $totalCents = computeTotalBalanceCents($pdo, $userId);

    $stmt = $pdo->prepare('SELECT id, concept, scaled_value FROM distribution_items WHERE user_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    $assignedCents = 0;

    foreach ($items as $item) {
        if ($mode === 'percentage') {
            $amountCents = (int) round($totalCents * ($item['scaled_value'] / 10000));
            $displayValue = round($item['scaled_value'] / 100, 2);
        } else {
            $amountCents = (int) $item['scaled_value'];
            $displayValue = centsToEuros((int) $item['scaled_value']);
        }
        $assignedCents += $amountCents;
        $result[] = [
            'id' => (int) $item['id'],
            'concept' => $item['concept'],
            'value' => $displayValue,
            'amount' => centsToEuros($amountCents),
        ];
    }

    return [
        'mode' => $mode,
        'total' => centsToEuros($totalCents),
        'assigned' => centsToEuros($assignedCents),
        'remaining' => centsToEuros($totalCents - $assignedCents),
        'items' => $result,
    ];
}

function computeGoalsProgress(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, name, target_amount_cents, saved_amount_cents FROM goals WHERE user_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($goals as $g) {
        $target = (int) $g['target_amount_cents'];
        $saved = (int) $g['saved_amount_cents'];
        $percent = $target > 0 ? min(round(($saved / $target) * 100, 1), 100) : 0;

        $result[] = [
            'id' => (int) $g['id'],
            'name' => $g['name'],
            'target_amount' => centsToEuros($target),
            'saved_amount' => centsToEuros($saved),
            'remaining' => centsToEuros(max($target - $saved, 0)),
            'percent' => $percent,
        ];
    }
    return $result;
}

function computeBudgetsStatus(PDO $pdo, int $userId): array
{
    $currentMonth = date('Y-m');

    $stmt = $pdo->prepare(
        'SELECT b.id, b.category_id, c.name AS category_name, b.limit_amount_cents,
                COALESCE((
                    SELECT SUM(-m.amount_cents) FROM movements m
                    WHERE m.user_id = b.user_id
                      AND m.category_id = b.category_id
                      AND m.amount_cents < 0
                      AND substr(m.date, 1, 7) = :month
                ), 0) AS spent_cents
         FROM budgets b
         LEFT JOIN categories c ON c.id = b.category_id
         WHERE b.user_id = :user_id
         ORDER BY b.id'
    );
    $stmt->execute(['month' => $currentMonth, 'user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $r) {
        $limit = (int) $r['limit_amount_cents'];
        $spent = (int) $r['spent_cents'];
        $result[] = [
            'id' => (int) $r['id'],
            'category_id' => (int) $r['category_id'],
            'category_name' => $r['category_name'] ?? 'Categoría eliminada',
            'limit_amount' => centsToEuros($limit),
            'spent' => centsToEuros($spent),
            'remaining' => centsToEuros($limit - $spent),
            'percent' => $limit > 0 ? round(($spent / $limit) * 100, 1) : 0,
            'over_budget' => $spent > $limit,
        ];
    }
    return $result;
}

function computeMonthlyStats(PDO $pdo, int $userId): array
{
    $currentMonth = date('Y-m');
    $previousMonth = date('Y-m', strtotime('first day of last month'));

    $stmt = $pdo->prepare(
        "SELECT substr(date, 1, 7) AS month,
                COALESCE(SUM(CASE WHEN amount_cents >= 0 THEN amount_cents ELSE 0 END), 0) AS income_cents,
                COALESCE(SUM(CASE WHEN amount_cents < 0 THEN -amount_cents ELSE 0 END), 0) AS expense_cents
         FROM movements
         WHERE user_id = :user_id AND substr(date, 1, 7) IN (:current, :previous)
         GROUP BY month"
    );
    $stmt->execute(['user_id' => $userId, 'current' => $currentMonth, 'previous' => $previousMonth]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byMonth = [
        $currentMonth => ['income_cents' => 0, 'expense_cents' => 0],
        $previousMonth => ['income_cents' => 0, 'expense_cents' => 0],
    ];
    foreach ($rows as $r) {
        $byMonth[$r['month']] = ['income_cents' => (int) $r['income_cents'], 'expense_cents' => (int) $r['expense_cents']];
    }

    $currentNetCents = $byMonth[$currentMonth]['income_cents'] - $byMonth[$currentMonth]['expense_cents'];
    $previousNetCents = $byMonth[$previousMonth]['income_cents'] - $byMonth[$previousMonth]['expense_cents'];

    $savingsRate = $byMonth[$currentMonth]['income_cents'] > 0
        ? round(($currentNetCents / $byMonth[$currentMonth]['income_cents']) * 100, 1)
        : 0;

    $changePercent = null;
    if ($previousNetCents !== 0) {
        $changePercent = round((($currentNetCents - $previousNetCents) / abs($previousNetCents)) * 100, 1);
    }

    return [
        'month' => $currentMonth,
        'income' => centsToEuros($byMonth[$currentMonth]['income_cents']),
        'expense' => centsToEuros($byMonth[$currentMonth]['expense_cents']),
        'net' => centsToEuros($currentNetCents),
        'savings_rate' => $savingsRate,
        'previous_month_net' => centsToEuros($previousNetCents),
        'change_percent' => $changePercent,
    ];
}

function computeBalanceSeries(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT date, amount_cents FROM movements WHERE user_id = ? ORDER BY date ASC, id ASC');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $running = 0;
    $byDate = [];
    foreach ($rows as $r) {
        $running += (int) $r['amount_cents'];
        $byDate[$r['date']] = $running; // último valor del día se queda
    }

    $points = [];
    foreach ($byDate as $date => $cents) {
        $points[] = ['date' => $date, 'total' => centsToEuros($cents)];
    }
    return $points;
}
