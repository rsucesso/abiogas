<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Metodo nao permitido'], 405);
}

$data = json_input();
require_fields($data, ['own_qr_code', 'name', 'company']);

$ownQr = trim((string)$data['own_qr_code']);
$name = trim((string)$data['name']);
$company = trim((string)$data['company']);

if ($ownQr === '' || $name === '' || $company === '') {
    json_out(['error' => 'Dados invalidos'], 400);
}

$pdo = get_pdo();
$token = new_token();

// Se esse QR proprio ja se cadastrou antes (ex: reinstalou o app / limpou o navegador),
// retoma o mesmo capturador em vez de criar um registro duplicado, preservando o historico.
$stmt = $pdo->prepare('SELECT id FROM capturadores WHERE own_qr_code = ?');
$stmt->execute([$ownQr]);
$existing = $stmt->fetch();

if ($existing) {
    $id = (int)$existing['id'];
    $upd = $pdo->prepare('UPDATE capturadores SET name = ?, company = ?, session_token = ? WHERE id = ?');
    $upd->execute([$name, $company, $token, $id]);
} else {
    $ins = $pdo->prepare('INSERT INTO capturadores (own_qr_code, name, company, session_token) VALUES (?, ?, ?, ?)');
    $ins->execute([$ownQr, $name, $company, $token]);
    $id = (int)$pdo->lastInsertId();
}

json_out([
    'id' => $id,
    'session_token' => $token,
    'name' => $name,
    'company' => $company,
    'stats' => get_stats($pdo, $id),
]);
