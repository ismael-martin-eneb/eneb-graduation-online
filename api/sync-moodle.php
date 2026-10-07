<?php
/**
 * Sincroniza con Moodle los alumnos de zoho_leads que aún no tienen programa
 * (sin filas en eneb_graduates) para poblar eneb_programs / eneb_graduates.
 *
 * SOLO línea de comandos:
 *   php api/sync-moodle.php          → procesa todos los pendientes
 *   php api/sync-moodle.php 50       → procesa como máximo 50
 *
 * Es idempotente: se puede relanzar; los alumnos ya sincronizados se omiten.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : PHP_INT_MAX;

$pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pending = $pdo->query(
    'SELECT zl.id, zl.id_alumno, zl.campus, zl.timecreated
     FROM ' . DB_TABLE_ZOHO_LEADS . ' zl
     LEFT JOIN ' . DB_TABLE_GRADUADOS . ' eg ON eg.id_alumno = zl.id
     WHERE eg.id IS NULL AND zl.campus IS NOT NULL AND zl.campus <> \'\'
     ORDER BY zl.id'
)->fetchAll();

$ok = $missing = 0;
foreach ($pending as $i => $lead) {
    if ($i >= $limit) {
        break;
    }
    $res = getMoodleEmbajador_cli((string) $lead['id_alumno'], (string) $lead['campus']);
    $n = saveMoodleGraduate($pdo, (int) $lead['id'], $res, (int) $lead['timecreated'] ?: time());
    if ($n > 0) {
        $ok++;
    } else {
        $missing++;
    }
    echo sprintf("[%d/%d] id_alumno=%s → %d programa(s)\n", $i + 1, count($pending), $lead['id_alumno'], $n);
}

echo "Hecho. Con programa: $ok · sin datos en Moodle: $missing\n";
