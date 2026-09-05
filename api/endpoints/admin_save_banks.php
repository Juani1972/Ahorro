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
$banks = $body['banks'] ?? null;

if (!is_array($banks)) {
    respond(['error' => 'Formato de bancos inválido.'], 422);
}
if (count($banks) > 20) {
    respond(['error' => 'No se permiten más de 20 bancos.'], 422);
}

$clean = [];
foreach ($banks as $bank) {
    $name = trim((string) ($bank['name'] ?? ''));
    $url = trim((string) ($bank['url'] ?? ''));

    if ($name === '' || $url === '') {
        continue;
    }
    if (strlen($name) > 100) {
        respond(['error' => "El nombre del banco \"$name\" supera los 100 caracteres."], 422);
    }
    if (strlen($url) > 2048) {
        respond(['error' => "La URL del banco \"$name\" supera los 2048 caracteres."], 422);
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || strpos($url, 'https://') !== 0) {
        respond(['error' => "La URL del banco \"$name\" debe ser una dirección https:// válida."], 422);
    }

    $clean[] = ['name' => $name, 'url' => $url, 'active' => !empty($bank['active']) ? 1 : 0];
}

$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM banks WHERE user_id = ?');
    $del->execute([$userId]);

    $ins = $pdo->prepare('INSERT INTO banks (user_id, name, url, active) VALUES (?, ?, ?, ?)');
    foreach ($clean as $b) {
        $ins->execute([$userId, $b['name'], $b['url'], $b['active']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['error' => 'No se pudieron guardar los bancos.'], 500);
}

respond(['ok' => true]);
