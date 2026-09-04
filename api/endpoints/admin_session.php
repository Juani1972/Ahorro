<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? 'check';

if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'Método no permitido.'], 405);
    }
    $_SESSION = [];
    session_destroy();
    respond(['ok' => true, 'is_admin' => false]);
}

$isAdmin = !empty($_SESSION['is_admin']);
$mustChange = false;
if ($isAdmin) {
    require_once __DIR__ . '/../db.php';
    $data = loadStore();
    $mustChange = !empty($data['admin']['must_change_password']);
}

respond([
    'is_admin' => $isAdmin,
    'csrf_token' => $isAdmin ? ($_SESSION['csrf_token'] ?? null) : null,
    'must_change_password' => $mustChange,
]);
