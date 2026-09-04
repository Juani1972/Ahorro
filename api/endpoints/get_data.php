<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

requireAuth();
requirePasswordChanged();

$data = loadStore();

$activeBanks = array_values(array_filter($data['banks'], fn($b) => !empty($b['active'])));

respond([
    'banks' => $activeBanks,
    'balance' => [
        'total' => computeTotalBalance($data),
        'history' => array_reverse($data['balance']['history']),
    ],
    'distribution' => computeDistribution($data),
]);
