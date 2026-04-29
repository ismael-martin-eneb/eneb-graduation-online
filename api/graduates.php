<?php
/**
 * GET /api/graduates.php
 * Devuelve programas y graduados desde la base de datos.
 * Respuesta: { programs: [...], graduates: [...] }
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// CORS: permite peticiones desde el servidor de desarrollo (Live Server)
$allowedOrigins = ['http://localhost:5500', 'http://127.0.0.1:5500'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';

// Mapa de códigos ISO 3166-1 alpha-2 a nombres en español
$COUNTRY_NAMES = [
    'ES' => 'España',       'MX' => 'México',       'CO' => 'Colombia',
    'AR' => 'Argentina',    'CL' => 'Chile',         'PE' => 'Perú',
    'EC' => 'Ecuador',      'VE' => 'Venezuela',     'UY' => 'Uruguay',
    'PY' => 'Paraguay',     'BO' => 'Bolivia',       'CR' => 'Costa Rica',
    'PA' => 'Panamá',       'DO' => 'Rep. Dominicana', 'GT' => 'Guatemala',
    'HN' => 'Honduras',     'SV' => 'El Salvador',   'NI' => 'Nicaragua',
    'CU' => 'Cuba',         'PR' => 'Puerto Rico',   'US' => 'Estados Unidos',
    'BR' => 'Brasil',       'PT' => 'Portugal',      'AD' => 'Andorra',
    'MA' => 'Marruecos',    'FR' => 'Francia',       'DE' => 'Alemania',
    'IT' => 'Italia',       'GB' => 'Reino Unido',   'NL' => 'Países Bajos',
    'BE' => 'Bélgica',      'CH' => 'Suiza',         'AT' => 'Austria',
    'PL' => 'Polonia',      'RO' => 'Rumanía',       'TR' => 'Turquía',
    'MX' => 'México',
];

function deriveHonor($grade, $cumlaude) {
    if ($cumlaude) return 'Cum Laude';
    if ($grade >= 9.0) return 'Sobresaliente';
    if ($grade >= 8.0) return 'Notable Alto';
    if ($grade >= 7.0) return 'Notable';
    return 'Aprobado';
}

function deriveBadges($cumlaude) {
    if ($cumlaude) {
        return [['id' => 'cum-laude', 'label' => 'Graduate Cum Laude', 'icon' => 'diploma']];
    }
    return [];
}

function deriveShortName($name) {
    // "MBA - Máster en ..." → "MBA"
    if (preg_match('/^([A-Z][A-Z0-9 &+]+?)\s*[-–]/u', $name, $m)) {
        return trim($m[1]);
    }
    // "Máster en Big Data y Business Intelligence" → "Big Data y Business Intelligence"
    $short = preg_replace('/^(M[aá]ster en |Posgrado en |Curso en )/ui', '', $name);
    return mb_strlen($short) > 36 ? mb_substr($short, 0, 34) . '…' : $short;
}

try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(503);
    echo json_encode(['error' => 'DB unavailable']);
    exit;
}

// ── Programas ────────────────────────────────────────────────────────────────
$programs   = [];
$graduates  = [];
$queryError = null;

try {
    $rawPrograms = $pdo->query(
        'SELECT id, name, campus FROM eneb_programs ORDER BY id'
    )->fetchAll();

    foreach ($rawPrograms as $row) {
        $programs[] = [
            'id'        => (string) $row['id'],
            'name'      => $row['name'],
            'shortName' => deriveShortName($row['name']),
            'promo'     => 'Promoción 2026',
            'campus'    => $row['campus'],
        ];
    }
} catch (PDOException $e) {
    $queryError = $e->getMessage();
    error_log('[graduates] Error al leer eneb_programs: ' . $e->getMessage());
}

// ── Graduados ─────────────────────────────────────────────────────────────────
try {
    $sql = '
        SELECT
            zl.id            AS zoho_id,
            zl.nombre        AS name,
            zl.pais          AS pais,
            zl.foto          AS photo,
            zl.frase         AS message,
            eg.program_id    AS program_id,
            eg.graduation_date,
            eg.grade,
            eg.cumlaude
        FROM eneb_graduates eg
        JOIN zoho_leads zl ON zl.id = eg.id_alumno
        ORDER BY eg.program_id, zl.nombre
    ';

    $rows = $pdo->query($sql)->fetchAll();

    foreach ($rows as $row) {
        $rawPais  = $row['pais'] !== null ? strtoupper(trim((string) $row['pais'])) : '';
        $country  = ($rawPais !== '' && isset($COUNTRY_NAMES[$rawPais]))
            ? $COUNTRY_NAMES[$rawPais]
            : ($rawPais !== '' ? $rawPais : 'Desconocido');
        $grade    = $row['grade'] !== null    ? (float) $row['grade']    : 0.0;
        $cumlaude = $row['cumlaude'] !== null ? (int)   $row['cumlaude'] : 0;
        $year     = $row['graduation_date']
            ? (int) substr($row['graduation_date'], 0, 4)
            : 2026;

        $graduates[] = [
            'id'        => $row['program_id'] . '-' . $row['zoho_id'],
            'name'      => $row['name'],
            'country'   => $country,
            'programId' => (string) $row['program_id'],
            'honor'     => deriveHonor($grade, $cumlaude),
            'grade'     => number_format($grade, 2, '.', ''),
            'badges'    => deriveBadges($cumlaude),
            'year'      => $year,
            'message'   => $row['message'] !== null ? $row['message'] : '',
            'photo'     => ($row['photo'] !== null && $row['photo'] !== '') ? $row['photo'] : null,
        ];
    }

    error_log('[graduates] OK — programas: ' . count($programs) . ', graduados: ' . count($graduates));
} catch (PDOException $e) {
    $queryError = $e->getMessage();
    error_log('[graduates] Error al leer graduates/zoho_leads: ' . $e->getMessage());
}

$output = ['programs' => $programs, 'graduates' => $graduates];
if ($queryError !== null) {
    $output['_error'] = $queryError; // solo en depuración; eliminar en producción
}

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
