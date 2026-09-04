<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

requireAuth();
requirePasswordChanged();

$data = loadStore();

respond([
    'banks' => $data['banks'],
    'distribution' => $data['distribution'],
    'balance_total' => computeTotalBalance($data),
]);
