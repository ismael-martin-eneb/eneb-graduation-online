<?php
/**
 * GET /api/graduates.php
 * Devuelve programas y graduados desde la base de datos.
 * Respuesta: { programs: [...], graduates: [...] }
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';

// CORS: solo orígenes explícitamente permitidos (ADMIN_ALLOWED_ORIGINS en config.php).
// Por defecto no se habilita: la web y la API comparten origen.
$allowedOrigins = defined('ADMIN_ALLOWED_ORIGINS') ? ADMIN_ALLOWED_ORIGINS : [];
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


// Códigos ISO 3166-1 alpha-2 conocidos (solo para validación; los nombres se traducen en el frontend)
$KNOWN_COUNTRY_CODES = [
    'ES','MX','CO','AR','CL','PE','EC','VE','UY','PY','BO','CR','PA','DO',
    'GT','HN','SV','NI','CU','PR','US','BR','PT','AD','MA','FR','DE','IT',
    'GB','NL','BE','CH','AT','PL','RO','TR',
];

/**
 * Devuelve una clave de traducción neutral; el frontend la convierte
 * a la cadena localizada mediante i18n (honor_cum_laude, etc.).
 */
function deriveHonorKey($grade, $cumlaude) {
    if ($cumlaude)       return 'cum_laude';
    if ($grade >= 9.0)   return 'sobresaliente';
    if ($grade >= 8.0)   return 'notable_alto';
    if ($grade >= 7.0)   return 'notable';
    return 'aprobado';
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
            'year'      => 2026,
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
            zl.foto          AS foto,
            zl.foto_graduate AS foto_graduate,
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
        // Se devuelve el código ISO directamente; el frontend lo traduce con i18n.
        $country  = ($rawPais !== '') ? $rawPais : null;
        $grade    = $row['grade'] !== null    ? (float) $row['grade']    : 0.0;
        $cumlaude = $row['cumlaude'] !== null ? (int)   $row['cumlaude'] : 0;
        $year     = $row['graduation_date']
            ? (int) substr($row['graduation_date'], 0, 4)
            : 2026;

        $photo     = ($row['foto'] !== null && $row['foto'] !== '') ? $row['foto'] : null;

        $graduates[] = [
            'id'        => $row['program_id'] . '-' . $row['zoho_id'],
            'name'      => $row['name'],
            'country'   => $country,
            'programId' => (string) $row['program_id'],
            'honor'     => deriveHonorKey($grade, $cumlaude),
            'grade'     => number_format($grade, 2, '.', ''),
            'badges'    => deriveBadges($cumlaude),
            'year'      => $year,
            'message'   => $row['message'] !== null ? $row['message'] : '',
            'photo'     => $photo,
            'photo_graduate' => ($row['foto_graduate'] !== null && $row['foto_graduate'] !== '') ? $row['foto_graduate'] : null,
        ];
    }

    // ── Respaldo: alumnos de zoho_leads sin registro en eneb_graduates ──────
    // Aún no tienen programa/nota asociados (p. ej. importados por CSV), así
    // que se agrupan en una sección sintética por campus para que sean visibles.
    $orphans = $pdo->query('
        SELECT zl.id, zl.nombre, zl.pais, zl.foto, zl.foto_graduate, zl.frase, zl.campus
        FROM zoho_leads zl
        LEFT JOIN eneb_graduates eg ON eg.id_alumno = zl.id
        WHERE eg.id IS NULL
        ORDER BY zl.campus, zl.nombre
    ')->fetchAll();

    $campusNames = [];
    foreach ($orphans as $row) {
        $campus = (string) ($row['campus'] ?? '');
        $pid    = 'campus-' . substr(md5($campus), 0, 8);
        if (!isset($campusNames[$pid])) {
            $host = parse_url($campus, PHP_URL_HOST) ?: 'ENEB';
            $campusNames[$pid] = true;
            $programs[] = [
                'id'        => $pid,
                'name'      => 'Graduados ' . $host,
                'shortName' => 'Graduados ' . $host,
                'year'      => 2026,
                'campus'    => $campus,
            ];
        }
        $rawPais = $row['pais'] !== null ? strtoupper(trim((string) $row['pais'])) : '';
        $graduates[] = [
            'id'        => $pid . '-' . $row['id'],
            'name'      => $row['nombre'],
            'country'   => $rawPais !== '' ? $rawPais : null,
            'programId' => $pid,
            'honor'     => deriveHonorKey(0.0, 0),
            'grade'     => '0.00',
            'badges'    => deriveBadges(0),
            'year'      => 2026,
            'message'   => $row['frase'] !== null ? $row['frase'] : '',
            'photo'     => ($row['foto'] !== null && $row['foto'] !== '') ? $row['foto'] : null,
            'photo_graduate' => ($row['foto_graduate'] !== null && $row['foto_graduate'] !== '') ? $row['foto_graduate'] : null,
        ];
    }

    error_log('[graduates] OK — programas: ' . count($programs) . ', graduados: ' . count($graduates));
} catch (PDOException $e) {
    $queryError = $e->getMessage();
    error_log('[graduates] Error al leer graduates/zoho_leads: ' . $e->getMessage());
}

$output = ['programs' => $programs, 'graduates' => $graduates];
if ($queryError !== null) {
    // El detalle va solo al log; al cliente se le indica un fallo genérico
    $output['error'] = 'No se pudieron cargar los graduados';
}

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
