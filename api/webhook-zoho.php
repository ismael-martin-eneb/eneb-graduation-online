<?php
/**
 * ENEB Graduación Online — Webhook receptor de Zoho Forms
 *
 * URL de uso en Zoho Forms:
 *   https://tudominio.com/api/webhook-zoho.php?token=TU_TOKEN_SECRETO
 *
 * Zoho Forms envía un POST con Content-Type application/json o
 * application/x-www-form-urlencoded según la configuración del formulario.
 * Este endpoint maneja ambos formatos.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Bootstrap
// ---------------------------------------------------------------------------

// Solo aceptar HTTPS en producción (quita el comentario cuando tengas SSL)
// if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
//     http_response_code(403);
//     exit(json_encode(['error' => 'HTTPS required']));
// }

header('Content-Type: application/json; charset=utf-8');

// Evitar que el PHP exponga información de error al cliente
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// 2. Cargar configuración
// ---------------------------------------------------------------------------

$configFile = __DIR__ . '/config.php';
$lib = __DIR__ . '/lib.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    error_log('[zoho-webhook] config.php no encontrado en ' . $configFile);
    exit(json_encode(['error' => 'Server misconfiguration']));
}

if (!file_exists($lib)) {
    http_response_code(500);
    error_log('[zoho-webhook] lib.php no encontrado en ' . $lib);
    exit(json_encode(['error' => 'Server misconfiguration']));
}

require_once $configFile;
require_once $lib;

// ---------------------------------------------------------------------------
// 3. Verificar método HTTP
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(json_encode(['error' => 'Method Not Allowed']));
}

// ---------------------------------------------------------------------------
// 4. Verificar token secreto
// ---------------------------------------------------------------------------

$receivedToken = isset($_GET['token']) ? trim($_GET['token']) : '';

if (!hash_equals(WEBHOOK_SECRET_TOKEN, $receivedToken)) {
    http_response_code(401);
    error_log('[zoho-webhook] Token inválido recibido desde ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit(json_encode(['error' => 'Unauthorized']));
}

// ---------------------------------------------------------------------------
// 5. Leer y decodificar el cuerpo de la petición
// ---------------------------------------------------------------------------

$rawBody = file_get_contents('php://input');

if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'Empty request body']));
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$payload = [];

if (strpos($contentType, 'application/json') !== false) {
    // Zoho puede enviar JSON
    $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid JSON payload']));
    }
    $payload = $decoded;
} elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
    // Zoho también puede enviar form-encoded
    parse_str($rawBody, $payload);
} else {
    // Intentar JSON como fallback
    $decoded = json_decode($rawBody, true);
    $payload = is_array($decoded) ? $decoded : [];
}

if (empty($payload)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Could not parse request body']));
}

// ---------------------------------------------------------------------------
// 6. Extraer y validar campos requeridos
// ---------------------------------------------------------------------------

$nombre       = sanitizeString($payload[ZOHO_FIELD_NOMBRE]    ?? '', 255);
$id_alumno    = sanitizeString($payload[ZOHO_FIELD_ID_ESTUDIANTE] ?? '', 10);
$foto         = sanitizeString($payload[ZOHO_FIELD_FOTO] ?? '', 255);
$frase        = sanitizeString($payload[ZOHO_FIELD_FRASE] ?? '', 255);
$timecreated  = sanitizeDate($payload[ZOHO_FIELD_TIMECREATED] ?? '');

$errors = [];

if ($nombre === '') {
    $errors[] = 'El campo nombre es obligatorio';
}
if ($id_alumno === '') {
    $errors[] = 'El campo ID de estudiante es obligatorio';
}
if ($foto === '') {
    $errors[] = 'El campo foto es obligatorio';
}
if ($frase === '') {
    $errors[] = 'El campo frase es obligatorio';
}
if ($timecreated === '') {
    $errors[] = 'El campo hora agregado es obligatorio';
}

if (!empty($errors)) {
    http_response_code(422);
    exit(json_encode(['error' => 'Validation failed', 'details' => $errors]));
}

// ---------------------------------------------------------------------------
// 7. Guardar en base de datos
// ---------------------------------------------------------------------------

try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $sql = 'INSERT INTO ' . DB_TABLE . ' (nombre, id_alumno, foto, frase, timecreated, raw_payload) VALUES (:nombre, :id_alumno, :foto, :frase, :timecreated, :raw_payload)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'      => $nombre,
        ':id_alumno'   => $id_alumno,
        ':foto'        => $foto,
        ':frase'       => $frase,
        ':timecreated' => $timecreated,
        ':raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $insertedId = $pdo->lastInsertId();

} catch (PDOException $e) {
    http_response_code(500);
    // Registrar el error real en el log del servidor, nunca exponerlo al cliente
    error_log('[zoho-webhook] DB error: ' . $e->getMessage());
    exit(json_encode(['error' => 'Database error']));
}

// ---------------------------------------------------------------------------
// 8. Consultar datos del alumno en Moodle (prueba los tres sitios)
// ---------------------------------------------------------------------------

// $id_alumno ya viene validado del paso 6, no hace falta releer la BD
$moodleResult = getMoodleEmbajador($id_alumno, $nombre);

if (!$moodleResult['found']) {
    if (($moodleResult['reason'] ?? '') === 'name_mismatch') {
        error_log(sprintf(
            '[zoho-webhook] Nombre no coincide para id_alumno=%s: Zoho="%s" Moodle="%s" score=%.1f%%',
            $id_alumno, $moodleResult['zoho_name'], $moodleResult['moodle_name'], $moodleResult['score']
        ));
        http_response_code(422);
        exit(json_encode([
            'error'       => 'El nombre de Zoho no coincide con el registrado en Moodle',
            'id_alumno'   => $id_alumno,
            'zoho_name'   => $moodleResult['zoho_name'],
            'moodle_name' => $moodleResult['moodle_name'],
            'score'       => $moodleResult['score'],
            'min_score'   => $moodleResult['min_score'],
        ]));
    }

    error_log('[zoho-webhook] Ningún sitio Moodle devolvió datos para id_alumno=' . $id_alumno);
    http_response_code(404);
    exit(json_encode([
        'error'     => 'Alumno no encontrado en ningún campus Moodle',
        'id_alumno' => $id_alumno,
        'sites'     => $moodleResult['errors'] ?? [],
    ]));
}

// ---------------------------------------------------------------------------
// 10. Respuesta de éxito
// ---------------------------------------------------------------------------

http_response_code(201);
exit(json_encode([
    'status'    => 'ok',
    'id'        => $insertedId,
    'id_alumno' => $id_alumno,
    'site'      => $moodleResult['site'],
    'moodle'    => $moodleResult['data'],
]));
