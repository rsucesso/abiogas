<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$key = (string)($_GET['key'] ?? '');
if (!hash_equals(ADMIN_EXPORT_KEY, $key)) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->query(
    "UPDATE sympla_inscritos SET codigo = UPPER(REPLACE(REPLACE(REPLACE(codigo,'-',''),' ',''),'_',''))"
);
echo 'Linhas atualizadas: ' . $stmt->rowCount();
