<?php
declare(strict_types=1);

// DEBUG TEMPORARIO: mostra o erro real em vez de tela em branco. Remover depois de diagnosticar.
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'DEBUG: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit;
});

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_fields(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') {
            json_out(['error' => "Campo obrigatorio ausente: $f"], 400);
        }
    }
}

function new_token(): string {
    return bin2hex(random_bytes(32));
}

function find_capturador_by_token(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare('SELECT * FROM capturadores WHERE session_token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_stats(PDO $pdo, int $capturadorId): array {
    $stmt = $pdo->prepare(
        'SELECT event_day, is_duplicate, COUNT(*) AS qty
         FROM capturas
         WHERE capturador_id = ?
         GROUP BY event_day, is_duplicate'
    );
    $stmt->execute([$capturadorId]);
    $rows = $stmt->fetchAll();

    $days = [
        EVENT_DAY_1 => ['unique' => 0, 'repeated' => 0],
        EVENT_DAY_2 => ['unique' => 0, 'repeated' => 0],
    ];
    $totalUnique = 0;
    $totalRepeated = 0;

    foreach ($rows as $row) {
        $day = $row['event_day'];
        $qty = (int)$row['qty'];
        $isDup = (int)$row['is_duplicate'] === 1;

        if (!isset($days[$day])) {
            $days[$day] = ['unique' => 0, 'repeated' => 0];
        }
        if ($isDup) {
            $days[$day]['repeated'] += $qty;
            $totalRepeated += $qty;
        } else {
            $days[$day]['unique'] += $qty;
            $totalUnique += $qty;
        }
    }

    return [
        'total_unique' => $totalUnique,
        'total_repeated' => $totalRepeated,
        'days' => [
            ['date' => EVENT_DAY_1, 'label' => 'Dia 1', 'unique' => $days[EVENT_DAY_1]['unique'], 'repeated' => $days[EVENT_DAY_1]['repeated']],
            ['date' => EVENT_DAY_2, 'label' => 'Dia 2', 'unique' => $days[EVENT_DAY_2]['unique'], 'repeated' => $days[EVENT_DAY_2]['repeated']],
        ],
    ];
}
