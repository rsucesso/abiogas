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
// UPDATE IGNORE pula silenciosamente as linhas que colidiriam com um codigo ja
// existente apos remover os separadores (duplicatas), em vez de abortar tudo.
$stmt = $pdo->query(
    "UPDATE IGNORE sympla_inscritos SET codigo = UPPER(REPLACE(REPLACE(REPLACE(codigo,'-',''),' ',''),'_',''))"
);
echo 'Linhas atualizadas: ' . $stmt->rowCount();

$dupStmt = $pdo->query(
    "SELECT COUNT(*) AS qty FROM sympla_inscritos WHERE codigo REGEXP '[^A-Z0-9]'"
);
echo ' | Linhas ainda com separador (duplicatas puladas): ' . (int)$dupStmt->fetch()['qty'];
