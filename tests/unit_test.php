<?php
/**
 * Tests unitarios de las funciones de cálculo (api/db.php).
 * Ejecutar con: php tests/unit_test.php
 *
 * No requiere PHPUnit ni ninguna dependencia externa — pensado para
 * poder correrse en cualquier hosting básico con solo PHP CLI.
 */

declare(strict_types=1);

// --- Arnés de pruebas minimalista ---
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function assertEqual($expected, $actual, string $label): void
{
    $ok = $expected === $actual;
    if ($ok) {
        $GLOBALS['__pass']++;
        echo "  OK  $label\n";
    } else {
        $GLOBALS['__fail']++;
        echo "FALLO  $label\n";
        echo "       esperado: " . var_export($expected, true) . "\n";
        echo "       obtenido: " . var_export($actual, true) . "\n";
    }
}

function assertTrue(bool $condition, string $label): void
{
    assertEqual(true, $condition, $label);
}

// --- Preparar entorno: base de datos en memoria + funciones de db.php ---
// db.php requiere config.php, que a su vez llama a session_start() y header().
// En CLI no hay cabeceras HTTP reales; definimos versiones mínimas antes de
// incluir el archivo real para poder reutilizar exactamente la misma lógica
// de negocio que usa la aplicación en producción (no una copia paralela).
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script es solo para línea de comandos.');
}

require_once __DIR__ . '/../api/config.php';

function makeTestPDO(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $schema = file_get_contents(__DIR__ . '/../api/data/schema.sql');
    $pdo->exec($schema);
    return $pdo;
}

function makeTestUser(PDO $pdo, string $username = 'test'): int
{
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
    $stmt->execute([$username, password_hash('x', PASSWORD_BCRYPT)]);
    return (int) $pdo->lastInsertId();
}

require_once __DIR__ . '/../api/db.php';

echo "=== Tests: eurosToCents / centsToEuros ===\n";
assertEqual(15025, eurosToCents(150.25), 'eurosToCents(150.25) == 15025');
assertEqual(15025, eurosToCents('150.25'), 'eurosToCents string con punto');
assertEqual(15025, eurosToCents('150,25'), 'eurosToCents string con coma');
assertEqual(10, eurosToCents(0.1), 'eurosToCents(0.1) == 10 (no 9 por redondeo binario)');
assertEqual(null, eurosToCents('abc'), 'eurosToCents no numérico devuelve null');
assertEqual(150.25, centsToEuros(15025), 'centsToEuros(15025) == 150.25');

echo "\n=== Tests: precisión (0.1 + 0.2 clásico) ===\n";
$sumCents = eurosToCents(0.1) + eurosToCents(0.2);
assertEqual(30, $sumCents, 'suma en céntimos de 0.10 + 0.20 == 30 céntimos exactos');
assertEqual(0.3, centsToEuros($sumCents), 'convertido de vuelta a euros == 0.3 exacto');

echo "\n=== Tests: isValidHistoryDate ===\n";
assertTrue(isValidHistoryDate('2026-09-04'), 'fecha válida normal');
assertTrue(!isValidHistoryDate('2026-02-30'), '30 de febrero no existe -> inválida');
assertTrue(!isValidHistoryDate('04/09/2026'), 'formato con barras -> inválida');
assertTrue(!isValidHistoryDate('2099-01-01'), 'fecha demasiado lejana en el futuro -> inválida');
assertTrue(!isValidHistoryDate('2010-01-01'), 'fecha demasiado antigua -> inválida');

echo "\n=== Tests: computeTotalBalanceCents ===\n";
$pdo = makeTestPDO();
$userId = makeTestUser($pdo);
$ins = $pdo->prepare('INSERT INTO movements (user_id, date, amount_cents, note) VALUES (?, ?, ?, ?)');
$ins->execute([$userId, '2026-09-01', 50000, 'Depósito']);
$ins->execute([$userId, '2026-09-02', -12345, 'Gasto']);
assertEqual(37655, computeTotalBalanceCents($pdo, $userId), 'total = 500.00 - 123.45 = 376.55 (en céntimos: 37655)');

echo "\n=== Tests: aislamiento entre usuarios ===\n";
$otherUserId = makeTestUser($pdo, 'otro');
$ins->execute([$otherUserId, '2026-09-01', 999999, 'No debería contar para el primer usuario']);
assertEqual(37655, computeTotalBalanceCents($pdo, $userId), 'el saldo del primer usuario no cambia por movimientos de otro usuario');
assertEqual(999999, computeTotalBalanceCents($pdo, $otherUserId), 'el segundo usuario tiene su propio saldo independiente');

echo "\n=== Tests: computeDistribution (modo porcentaje) ===\n";
$pdo2 = makeTestPDO();
$u2 = makeTestUser($pdo2, 'dist_pct');
$pdo2->prepare('INSERT INTO movements (user_id, date, amount_cents, note) VALUES (?, ?, ?, ?)')
    ->execute([$u2, '2026-09-01', 100000, 'Depósito']); // 1000.00€
$pdo2->prepare('INSERT INTO distribution_items (user_id, concept, scaled_value) VALUES (?, ?, ?)')
    ->execute([$u2, 'Servicios', 3050]); // 30.50%
$dist = computeDistribution($pdo2, $u2);
assertEqual('percentage', $dist['mode'], 'modo por defecto es percentage');
assertEqual(1000.0, $dist['total'], 'total 1000€');
assertEqual(305.0, $dist['items'][0]['amount'], '30.50% de 1000€ = 305€ exactos');
assertEqual(30.5, $dist['items'][0]['value'], 'value mostrado es 30.5 (no 3050)');

echo "\n=== Tests: computeDistribution (modo fijo, decimales) ===\n";
$pdo3 = makeTestPDO();
$u3 = makeTestUser($pdo3, 'dist_fixed');
$pdo3->prepare('UPDATE users SET distribution_mode = ? WHERE id = ?')->execute(['fixed', $u3]);
$pdo3->prepare('INSERT INTO movements (user_id, date, amount_cents, note) VALUES (?, ?, ?, ?)')
    ->execute([$u3, '2026-09-01', 25030, 'Depósito']); // 250.30€
$pdo3->prepare('INSERT INTO distribution_items (user_id, concept, scaled_value) VALUES (?, ?, ?)')
    ->execute([$u3, 'Alquiler', 3333]); // 33.33€
$dist3 = computeDistribution($pdo3, $u3);
assertEqual(33.33, $dist3['items'][0]['amount'], 'monto fijo de 33.33€ se conserva exacto');
assertEqual(250.3, $dist3['total'], 'total 250.30€ exacto (sin residuo binario)');

echo "\n=== Tests: computeGoalsProgress ===\n";
$pdo4 = makeTestPDO();
$u4 = makeTestUser($pdo4, 'goals');
$pdo4->prepare('INSERT INTO goals (user_id, name, target_amount_cents, saved_amount_cents) VALUES (?, ?, ?, ?)')
    ->execute([$u4, 'Vacaciones', 200000, 85000]); // meta 2000€, ahorrado 850€
$goals = computeGoalsProgress($pdo4, $u4);
assertEqual(42.5, $goals[0]['percent'], '850/2000 = 42.5% exacto');
assertEqual(1150.0, $goals[0]['remaining'], 'restan 1150€');

echo "\n=== Tests: computeBudgetsStatus (solo cuenta el mes en curso) ===\n";
$pdo5 = makeTestPDO();
$u5 = makeTestUser($pdo5, 'budgets');
$catStmt = $pdo5->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
$catStmt->execute([$u5, 'Ocio']);
$catId = (int) $pdo5->lastInsertId();
$pdo5->prepare('INSERT INTO budgets (user_id, category_id, limit_amount_cents) VALUES (?, ?, ?)')
    ->execute([$u5, $catId, 10000]); // límite 100€/mes
$movStmt = $pdo5->prepare('INSERT INTO movements (user_id, date, amount_cents, note, category_id) VALUES (?, ?, ?, ?, ?)');
$movStmt->execute([$u5, date('Y-m') . '-05', -6000, 'Gasto este mes', $catId]); // -60€ este mes
$movStmt->execute([$u5, '2020-01-05', -99999, 'Gasto de hace años, no debe contar', $catId]);
$budgets = computeBudgetsStatus($pdo5, $u5);
assertEqual(60.0, $budgets[0]['spent'], 'solo cuenta el gasto del mes en curso, no el histórico');
assertEqual(false, $budgets[0]['over_budget'], '60€ de 100€ no está sobrepasado');

echo "\n=== Resumen ===\n";
$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "$pass OK, $fail FALLOS de " . ($pass + $fail) . " comprobaciones.\n";
exit($fail > 0 ? 1 : 0);
