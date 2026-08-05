<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$key = (string)($_GET['key'] ?? $_POST['key'] ?? '');
if (!hash_equals(ADMIN_EXPORT_KEY, $key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado.';
    exit;
}

$pdo = get_pdo();

function normalize_header(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
    return strtr($s, $map);
}

function detect_column(array $headersNorm, array $mustContain, array $mustNotContain = []): ?int {
    foreach ($headersNorm as $idx => $h) {
        $ok = true;
        foreach ($mustContain as $needle) {
            if (!str_contains($h, $needle)) { $ok = false; break; }
        }
        if (!$ok) continue;
        foreach ($mustNotContain as $needle) {
            if (str_contains($h, $needle)) { $ok = false; break; }
        }
        if ($ok) return $idx;
    }
    return null;
}

function xlsx_col_to_index(string $ref): int {
    preg_match('/^([A-Z]+)/', $ref, $m);
    $letters = $m[1] ?? 'A';
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}

// Le um .xlsx sem depender de bibliotecas externas (so ZipArchive + SimpleXML, ja inclusos no PHP).
function read_xlsx_rows(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) { $text .= (string)$r->t; }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === false) return [];

    $xml = @simplexml_load_string($sheetXml);
    if ($xml === false) return [];

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $colIdx = $ref !== '' ? xlsx_col_to_index($ref) : count($rowData);
            $type = (string)$c['t'];
            if ($type === 's') {
                $value = $sharedStrings[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)($c->is->t ?? '');
            } else {
                $value = (string)$c->v;
            }
            $rowData[$colIdx] = $value;
        }
        if ($rowData) {
            $max = max(array_keys($rowData));
            $ordered = [];
            for ($i = 0; $i <= $max; $i++) { $ordered[] = $rowData[$i] ?? ''; }
            $rows[] = $ordered;
        } else {
            $rows[] = [];
        }
    }
    return $rows;
}

$importMessage = '';
$importError = '';
$detectedMap = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_sympla') {
    if (!isset($_FILES['planilha']) || $_FILES['planilha']['error'] !== UPLOAD_ERR_OK) {
        $importError = 'Nenhum arquivo enviado ou erro no upload.';
    } else {
        $path = $_FILES['planilha']['tmp_name'];
        $origName = (string)$_FILES['planilha']['name'];
        $isXlsx = str_ends_with(strtolower($origName), '.xlsx');

        $allRows = [];
        if ($isXlsx) {
            $allRows = read_xlsx_rows($path);
        } else {
            $raw = file_get_contents($path);
            // Sympla costuma exportar em UTF-8 ou Windows-1252; normaliza para UTF-8.
            if ($raw !== false && !mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            }
            $firstLine = strtok((string)$raw, "\n");
            $delimiter = (substr_count((string)$firstLine, ';') > substr_count((string)$firstLine, ',')) ? ';' : ',';
            $allRows = array_map(
                fn($line) => str_getcsv($line, $delimiter),
                preg_split('/\r\n|\r|\n/', trim((string)$raw))
            );
        }

        if (count($allRows) < 1) {
            $importError = 'Nao foi possivel ler o arquivo enviado.';
        } else {
            // O export do Sympla costuma ter algumas linhas de metadados (titulo do
            // evento, data, local) antes da linha real de cabecalho. Procura a primeira
            // linha que pareca um cabecalho de verdade (contem "nome" e "email").
            $headerIdx = null;
            foreach ($allRows as $idx => $row) {
                $norm = array_map('normalize_header', $row);
                $hasNome = detect_column($norm, ['nome'], ['sobrenome']) !== null;
                $hasEmail = detect_column($norm, ['email']) !== null;
                if ($hasNome && $hasEmail) { $headerIdx = $idx; break; }
            }

            if ($headerIdx === null) {
                $importError = 'Nao encontrei uma linha de cabecalho com colunas de nome e email no arquivo.';
                $header = [];
            } else {
                $header = $allRows[$headerIdx];
                $allRows = array_slice($allRows, $headerIdx + 1);
            }
            $headersNorm = array_map('normalize_header', $header);

            $colCodigo = detect_column($headersNorm, ['codigo'], [])
                ?? detect_column($headersNorm, ['ingresso'], [])
                ?? detect_column($headersNorm, ['pedido'], []);
            $colNome = detect_column($headersNorm, ['nome'], ['sobrenome']);
            $colSobrenome = detect_column($headersNorm, ['sobrenome']);
            $colEmpresa = detect_column($headersNorm, ['empresa']) ?? detect_column($headersNorm, ['instituicao']);
            $colCargo = detect_column($headersNorm, ['cargo']);
            $colTelefone = detect_column($headersNorm, ['telefone']) ?? detect_column($headersNorm, ['celular']) ?? detect_column($headersNorm, ['fone']);
            $colEmail = detect_column($headersNorm, ['email']) ?? detect_column($headersNorm, ['e-mail']);

            $detectedMap = [
                'codigo' => $colCodigo !== null ? $header[$colCodigo] : null,
                'nome' => $colNome !== null ? $header[$colNome] : null,
                'sobrenome' => $colSobrenome !== null ? $header[$colSobrenome] : null,
                'empresa' => $colEmpresa !== null ? $header[$colEmpresa] : null,
                'cargo' => $colCargo !== null ? $header[$colCargo] : null,
                'telefone' => $colTelefone !== null ? $header[$colTelefone] : null,
                'email' => $colEmail !== null ? $header[$colEmail] : null,
            ];

            if ($headerIdx === null) {
                // erro ja definido acima
            } elseif ($colCodigo === null) {
                $importError = 'Nao encontrei uma coluna de codigo/ingresso/pedido no arquivo.';
            } else {
                $upsert = $pdo->prepare(
                    'INSERT INTO sympla_inscritos (codigo, nome, sobrenome, empresa, cargo, telefone, email)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        nome = VALUES(nome), sobrenome = VALUES(sobrenome), empresa = VALUES(empresa),
                        cargo = VALUES(cargo), telefone = VALUES(telefone), email = VALUES(email)'
                );

                $count = 0;
                foreach ($allRows as $row) {
                    if ($row === null || $row === [null]) continue;
                    $codigo = trim((string)($row[$colCodigo] ?? ''));
                    if ($codigo === '') continue;
                    $upsert->execute([
                        $codigo,
                        trim((string)($colNome !== null ? ($row[$colNome] ?? '') : '')),
                        trim((string)($colSobrenome !== null ? ($row[$colSobrenome] ?? '') : '')),
                        trim((string)($colEmpresa !== null ? ($row[$colEmpresa] ?? '') : '')),
                        trim((string)($colCargo !== null ? ($row[$colCargo] ?? '') : '')),
                        trim((string)($colTelefone !== null ? ($row[$colTelefone] ?? '') : '')),
                        trim((string)($colEmail !== null ? ($row[$colEmail] ?? '') : '')),
                    ]);
                    $count++;
                }
                $importMessage = "Importado com sucesso: $count inscritos.";
            }
        }
    }
}

$stmt = $pdo->query(
    "SELECT
        c.id, c.captured_at, c.event_day, c.qr_code_capturado, c.is_duplicate, c.tags, c.notes,
        cd.name AS capturador_nome, cd.company AS capturador_empresa, cd.email AS capturador_email,
        si.nome AS sympla_nome, si.sobrenome AS sympla_sobrenome, si.empresa AS sympla_empresa,
        si.cargo AS sympla_cargo, si.telefone AS sympla_telefone, si.email AS sympla_email
     FROM capturas c
     JOIN capturadores cd ON cd.id = c.capturador_id
     LEFT JOIN sympla_inscritos si ON si.codigo = c.qr_code_capturado
     ORDER BY c.captured_at DESC"
);
$rows = $stmt->fetchAll();
$totalMatched = 0;
foreach ($rows as $r) { if ($r['sympla_nome'] !== null) $totalMatched++; }

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Painel do Gestor — Coletor de Leads</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; background: #0f172a; color: #f1f5f9; margin: 0; padding: 24px; }
  h1 { font-size: 20px; margin-bottom: 4px; }
  .sub { color: #94a3b8; font-size: 13px; margin-bottom: 24px; }
  .card { background: #1e293b; border-radius: 12px; padding: 18px 20px; margin-bottom: 24px; }
  .card h2 { font-size: 15px; margin: 0 0 12px; }
  .msg-ok { color: #22c55e; font-size: 14px; }
  .msg-err { color: #ef4444; font-size: 14px; }
  .map-list { font-size: 12px; color: #94a3b8; margin-top: 8px; }
  input[type=file] { color: #f1f5f9; margin-bottom: 12px; }
  button { background: #2563eb; color: white; border: none; border-radius: 8px; padding: 10px 18px; font-size: 14px; cursor: pointer; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid rgba(148,163,184,0.15); white-space: nowrap; }
  th { color: #94a3b8; font-weight: 600; position: sticky; top: 0; background: #1e293b; }
  .table-wrap { overflow-x: auto; max-height: 70vh; overflow-y: auto; }
  .tag-pill { display: inline-block; background: #2563eb33; color: #93c5fd; border-radius: 999px; padding: 2px 8px; font-size: 11px; margin-right: 4px; }
  .dim { color: #64748b; }
</style>
</head>
<body>
  <h1>Painel do Gestor</h1>
  <p class="sub">Leads capturados: <?= count($rows) ?> · Com correspondência no Sympla: <?= $totalMatched ?></p>

  <div class="card">
    <h2>Subir lista de inscritos do Sympla (.xlsx ou .csv)</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_sympla">
      <input type="hidden" name="key" value="<?= h($key) ?>">
      <input type="file" name="planilha" accept=".csv,.xlsx" required><br>
      <button type="submit">Subir e atualizar base</button>
    </form>
    <?php if ($importMessage): ?><p class="msg-ok"><?= h($importMessage) ?></p><?php endif; ?>
    <?php if ($importError): ?><p class="msg-err"><?= h($importError) ?></p><?php endif; ?>
    <?php if ($detectedMap): ?>
      <div class="map-list">
        Colunas detectadas —
        <?php foreach ($detectedMap as $field => $header): ?>
          <?= h($field) ?>: <strong><?= $header !== null ? h($header) : 'não encontrada' ?></strong>&nbsp;&nbsp;
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Leads capturados</h2>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Capturado em</th>
          <th>Dia</th>
          <th>Empresa (Capturador)</th>
          <th>Nome (Sympla)</th>
          <th>Empresa (Sympla)</th>
          <th>Cargo</th>
          <th>Telefone</th>
          <th>Email</th>
          <th>Tags</th>
          <th>Observação</th>
          <th>Capturador</th>
          <th>Repetido</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['captured_at']) ?></td>
          <td><?= h($r['event_day']) ?></td>
          <td><?= h($r['capturador_empresa']) ?></td>
          <td><?= $r['sympla_nome'] !== null ? h($r['sympla_nome'] . ' ' . $r['sympla_sobrenome']) : '<span class="dim">' . h($r['qr_code_capturado']) . '</span>' ?></td>
          <td><?= h($r['sympla_empresa']) ?></td>
          <td><?= h($r['sympla_cargo']) ?></td>
          <td><?= h($r['sympla_telefone']) ?></td>
          <td><?= h($r['sympla_email']) ?></td>
          <td><?php foreach (explode(',', (string)$r['tags']) as $t): if ($t === '') continue; ?><span class="tag-pill"><?= h($t) ?></span><?php endforeach; ?></td>
          <td><?= h($r['notes']) ?></td>
          <td><?= h($r['capturador_nome']) ?> (<?= h($r['capturador_empresa']) ?>)<br><span class="dim"><?= h($r['capturador_email']) ?></span></td>
          <td><?= $r['is_duplicate'] ? 'sim' : 'não' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</body>
</html>
