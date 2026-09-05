<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$action = $_GET['action'] ?? 'check';

if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'Método no permitido.'], 405);
    }
    $_SESSION = [];
    session_destroy();
    respond(['ok' => true, 'is_authed' => false]);
}

$isAuthed = !empty($_SESSION['user_id']);
$mustChange = false;
$totpEnabled = false;

if ($isAuthed) {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT must_change_password, totp_enabled FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $mustChange = (bool) $row['must_change_password'];
    $totpEnabled = (bool) $row['totp_enabled'];
}

respond([
    'is_authed' => $isAuthed,
    'username' => $isAuthed ? ($_SESSION['username'] ?? null) : null,
    'csrf_token' => $isAuthed ? ($_SESSION['csrf_token'] ?? null) : null,
    'must_change_password' => $mustChange,
    'totp_enabled' => $totpEnabled,
]);
