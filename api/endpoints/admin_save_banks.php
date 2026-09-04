<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Método no permitido.'], 405);
}

requireCsrf();
requirePasswordChanged();

$body = readJsonBody();
$banks = $body['banks'] ?? null;

if (!is_array($banks)) {
    respond(['error' => 'Formato de bancos inválido.'], 422);
}
if (count($banks) > 20) {
    respond(['error' => 'No se permiten más de 20 bancos.'], 422);
}

// Primero determinamos el próximo id libre a partir de los ids existentes,
// para no chocar con un banco nuevo que aparezca antes en la lista.
$nextId = 1;
foreach ($banks as $bank) {
    if (isset($bank['id']) && (int) $bank['id'] > 0) {
        $nextId = max($nextId, (int) $bank['id'] + 1);
    }
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

    $id = isset($bank['id']) && (int) $bank['id'] > 0 ? (int) $bank['id'] : $nextId++;

    $clean[] = [
        'id' => $id,
        'name' => $name,
        'url' => $url,
        'active' => !empty($bank['active']),
    ];
}

$lock = acquireStoreLock();
$data = loadStore();
$data['banks'] = $clean;
saveStore($data);
releaseStoreLock($lock);

respond(['ok' => true, 'banks' => $clean]);
