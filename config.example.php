<?php
// Configuracao do Coletor de Leads.
// Copie este arquivo para "config.php" e preencha com os dados do banco criado
// no cPanel (MySQL Databases) antes de subir para o GoDaddy.
// config.php NAO entra no git (veja .gitignore) para nunca vazar a senha real.

// --- Banco de dados ---
define('DB_HOST', 'localhost');           // normalmente 'localhost' no GoDaddy
define('DB_NAME', 'TROQUE_pelo_nome_do_banco');
define('DB_USER', 'TROQUE_pelo_usuario');
define('DB_PASS', 'TROQUE_pela_senha');

// --- Evento ---
// Datas dos dois dias do evento (usadas para agrupar o grafico do painel).
define('EVENT_DAY_1', '2026-08-11');
define('EVENT_DAY_2', '2026-08-12');

// --- Admin ---
// Senha usada para baixar o CSV/Excel com todas as capturas (admin/export.php?key=...).
// Troque por um valor longo e dificil de adivinhar antes de publicar.
define('ADMIN_EXPORT_KEY', 'TROQUE_por_uma_senha_forte');

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
