<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Metodo nao permitido'], 405);
}

$data = json_input();
require_fields($data, ['session_token', 'qr_code']);

$token = trim((string)$data['session_token']);
$qr = trim((string)$data['qr_code']);

if ($qr === '') {
    json_out(['error' => 'QR code vazio'], 400);
}

$pdo = get_pdo();
$capturador = find_capturador_by_token($pdo, $token);
if (!$capturador) {
    json_out(['error' => 'Sessao invalida'], 401);
}
$capturadorId = (int)$capturador['id'];

// Nao permite que o captador registre o proprio QR como lead capturado.
if ($qr === $capturador['own_qr_code']) {
    json_out(['error' => 'Este e o seu proprio QR code'], 400);
}

$check = $pdo->prepare('SELECT COUNT(*) AS qty FROM capturas WHERE capturador_id = ? AND qr_code_capturado = ?');
$check->execute([$capturadorId, $qr]);
$isDuplicate = ((int)$check->fetch()['qty']) > 0;

$ins = $pdo->prepare(
    'INSERT INTO capturas (capturador_id, qr_code_capturado, is_duplicate, event_day, captured_at)
     VALUES (?, ?, ?, CURDATE(), NOW())'
);
$ins->execute([$capturadorId, $qr, $isDuplicate ? 1 : 0]);
$capturaId = (int)$pdo->lastInsertId();

json_out([
    'ok' => true,
    'id' => $capturaId,
    'is_duplicate' => $isDuplicate,
    'stats' => get_stats($pdo, $capturadorId),
]);
