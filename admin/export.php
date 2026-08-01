<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$key = (string)($_GET['key'] ?? '');
if (!hash_equals(ADMIN_EXPORT_KEY, $key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado.';
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->query(
    'SELECT
        c.id,
        c.captured_at,
        c.event_day,
        c.qr_code_capturado,
        c.is_duplicate,
        cd.name AS capturador_nome,
        cd.company AS capturador_empresa,
        cd.own_qr_code AS capturador_qr
     FROM capturas c
     JOIN capturadores cd ON cd.id = c.capturador_id
     ORDER BY c.captured_at ASC'
);

$filename = 'capturas_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// BOM para o Excel reconhecer UTF-8 corretamente.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'id',
    'data_hora_captura',
    'dia_evento',
    'qr_code_capturado',
    'repetido',
    'captador_nome',
    'captador_empresa',
    'captador_qr_proprio',
]);

foreach ($stmt as $row) {
    fputcsv($out, [
        $row['id'],
        $row['captured_at'],
        $row['event_day'],
        $row['qr_code_capturado'],
        $row['is_duplicate'] ? 'sim' : 'nao',
        $row['capturador_nome'],
        $row['capturador_empresa'],
        $row['capturador_qr'],
    ]);
}

fclose($out);
