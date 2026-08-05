<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Metodo nao permitido'], 405);
}

$data = json_input();
require_fields($data, ['session_token', 'captura_id']);

$token = trim((string)$data['session_token']);
$capturaId = (int)$data['captura_id'];
$tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
$notes = trim((string)($data['notes'] ?? ''));

$pdo = get_pdo();
$capturador = find_capturador_by_token($pdo, $token);
if (!$capturador) {
    json_out(['error' => 'Sessao invalida'], 401);
}
$capturadorId = (int)$capturador['id'];

$tagsClean = array_values(array_filter(array_map(
    static fn($t) => trim((string)$t),
    $tags
)));
$tagsStr = implode(',', $tagsClean);

$upd = $pdo->prepare(
    'UPDATE capturas SET tags = ?, notes = ? WHERE id = ? AND capturador_id = ?'
);
$upd->execute([$tagsStr !== '' ? $tagsStr : null, $notes !== '' ? $notes : null, $capturaId, $capturadorId]);

if ($upd->rowCount() === 0) {
    json_out(['error' => 'Captura nao encontrada'], 404);
}

json_out(['ok' => true]);
