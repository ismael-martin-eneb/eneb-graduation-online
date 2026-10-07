<?php
/**
 * Sincroniza con Moodle los alumnos de zoho_leads que aún no tienen ninguna
 * graduación en eneb_graduates (rellena programas, nota y fecha).
 *
 * Solo por línea de comandos:
 *   php api/sync-graduates.php           # procesa todos los pendientes
 *   php api/sync-graduates.php --limit=20
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

$limit = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sql = 'SELECT zl.id, zl.id_alumno, zl.campus, zl.timecreated
        FROM ' . DB_TABLE_ZOHO_LEADS . ' zl
        LEFT JOIN ' . DB_TABLE_GRADUADOS . ' eg ON eg.id_alumno = zl.id
        WHERE eg.id IS NULL AND zl.campus IS NOT NULL AND zl.campus <> \'\'
        ORDER BY zl.id' . ($limit > 0 ? ' LIMIT ' . $limit : '');
$pending = $pdo->query($sql)->fetchAll();

$ok = 0; $missing = 0;
foreach ($pending as $row) {
    $res = getMoodleEmbajador_cli((string) $row['id_alumno'], (string) $row['campus']);
    $n = saveMoodleGraduate($pdo, (int) $row['id'], $res, (int) $row['timecreated'] ?: time());
    if ($n > 0) { $ok++; } else { $missing++; }
    echo ($n > 0 ? 'OK   ' : 'SKIP ') . $row['id_alumno'] . ' (' . $n . " programas)\n";
}
echo "Pendientes: " . count($pending) . " | sincronizados: $ok | sin datos: $missing\n";
