<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    json_out(['error' => 'Token ausente'], 400);
}

$pdo = get_pdo();
$capturador = find_capturador_by_token($pdo, $token);

if (!$capturador) {
    json_out(['error' => 'Sessao invalida'], 404);
}

json_out([
    'id' => (int)$capturador['id'],
    'name' => $capturador['name'],
    'company' => $capturador['company'],
    'stats' => get_stats($pdo, (int)$capturador['id']),
]);
