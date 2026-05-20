<?php
/**
 * ENEB Graduación Online — Webhook receptor de fotos (Zoho WorkDrive → AWS S3)
 *
 * Recibe photo_resource_id y photo_name, descarga la imagen de Zoho WorkDrive,
 * la sube a AWS S3 y actualiza la columna foto en zoho_leads con la URL pública.
 *
 * El id_alumno se extrae del nombre de fichero: el último bloque numérico antes
 * de la extensión. Ej.: ENEB_EXPERIENCE_Rev._Simon_Robert_Wake_20931.jpg → 20931
 *
 * URL de uso:
 *   POST https://dev-graduados.eneb.es/api/webhook-foto.php?token=TU_TOKEN_SECRETO
 *
 * Body (JSON o form-encoded):
 *   photo_resource_id  — Resource ID de Zoho WorkDrive
 *   photo_name         — Nombre del fichero incluyendo el id Moodle del alumno
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Bootstrap
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// 2. Cargar configuración y librería
// ---------------------------------------------------------------------------

$configFile = __DIR__ . '/config.php';
$libFile    = __DIR__ . '/lib.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    error_log('[webhook-foto] config.php no encontrado');
    exit(json_encode(['error' => 'Server misconfiguration']));
}

if (!file_exists($libFile)) {
    http_response_code(500);
    error_log('[webhook-foto] lib.php no encontrado');
    exit(json_encode(['error' => 'Server misconfiguration']));
}

require_once $configFile;
require_once $libFile;

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
    error_log('[webhook-foto] Token inválido desde ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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
    $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid JSON payload']));
    }
    $payload = $decoded;
} elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
    parse_str($rawBody, $payload);
} else {
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

$photoResourceId = sanitizeString($payload['photo_resource_id'] ?? '', 255);
$photoName       = sanitizeString($payload['photo_name'] ?? '', 255);

$errors = [];

if ($photoResourceId === '') {
    $errors[] = 'El campo photo_resource_id es obligatorio';
}
if ($photoName === '') {
    $errors[] = 'El campo photo_name es obligatorio';
}

if (!empty($errors)) {
    http_response_code(422);
    exit(json_encode(['error' => 'Validation failed', 'details' => $errors]));
}

// Seguridad: no permitir path traversal en el nombre de fichero
$photoName = basename($photoName);

// Validar extensión: solo imágenes permitidas
$allowedExtensions = ['jpg', 'jpeg', 'png'];
$ext = strtolower(pathinfo($photoName, PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExtensions, true)) {
    http_response_code(422);
    exit(json_encode(['error' => 'Tipo de fichero no permitido', 'extension' => $ext]));
}

// ---------------------------------------------------------------------------
// 7. Extraer id_alumno del nombre del fichero
//    Patrón: último bloque numérico antes de la extensión
//    Ej.: ENEB_EXPERIENCE_Rev._Simon_Robert_Wake_20931.jpg → 20931
// ---------------------------------------------------------------------------

$idAlumno = null;
if (preg_match('/(\d+)\.[^.]+$/', $photoName, $matches)) {
    $idAlumno = (int) $matches[1];
}

if ($idAlumno === null || $idAlumno <= 0) {
    http_response_code(422);
    exit(json_encode([
        'error'      => 'No se pudo extraer el id_alumno del nombre del fichero',
        'photo_name' => $photoName,
    ]));
}

// ---------------------------------------------------------------------------
// 8. Verificar que el alumno existe en zoho_leads
// ---------------------------------------------------------------------------

try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $findStmt = $pdo->prepare('SELECT id FROM ' . DB_TABLE_ZOHO_LEADS . ' WHERE id_alumno = :id_alumno LIMIT 1');
    $findStmt->execute([':id_alumno' => $idAlumno]);
    $lead = $findStmt->fetch();

} catch (\PDOException $e) {
    http_response_code(500);
    error_log('[webhook-foto] DB error al buscar alumno: ' . $e->getMessage());
    exit(json_encode(['error' => 'Database error']));
}

if (!$lead) {
    http_response_code(404);
    exit(json_encode([
        'error'      => 'Alumno no encontrado en zoho_leads',
        'id_alumno'  => $idAlumno,
        'photo_name' => $photoName,
    ]));
}

$leadId = (int) $lead['id'];

// ---------------------------------------------------------------------------
// 9. Descargar la imagen desde Zoho WorkDrive
// ---------------------------------------------------------------------------

if (!function_exists('curl_init')) {
    http_response_code(500);
    error_log('[webhook-foto] La extensión cURL no está disponible');
    exit(json_encode(['error' => 'cURL not available']));
}

$downloadUrl = 'https://download.zoho.eu/v1/workdrive/download/' . rawurlencode($photoResourceId);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $downloadUrl);
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Zoho-oauthtoken ' . getZohoWorkDriveToken(),
]);
// En dev local se puede desactivar la verificación SSL (igual que con Moodle)
if (defined('MOODLE_SSL_VERIFY') && MOODLE_SSL_VERIFY === false) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$imageData = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$contentTypeHeader = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($curlError !== '') {
    http_response_code(502);
    error_log('[webhook-foto] cURL error al descargar foto: ' . $curlError);
    exit(json_encode(['error' => 'Error al descargar la foto de Zoho WorkDrive', 'detail' => $curlError]));
}

if ($httpCode !== 200) {
    http_response_code(502);
    error_log('[webhook-foto] Zoho WorkDrive devolvió HTTP ' . $httpCode . ' para resource_id=' . $photoResourceId);
    exit(json_encode(['error' => 'Zoho WorkDrive error', 'http_code' => $httpCode]));
}

if ($imageData === false || $imageData === '') {
    http_response_code(502);
    error_log('[webhook-foto] Respuesta vacía de Zoho WorkDrive para resource_id=' . $photoResourceId);
    exit(json_encode(['error' => 'Zoho WorkDrive devolvió una respuesta vacía']));
}

// ---------------------------------------------------------------------------
// 10. Validar que el contenido descargado es realmente una imagen
// ---------------------------------------------------------------------------

$allowedMimeTypes = ['image/jpeg', 'image/png'];
$detectedMime     = '';

if (function_exists('finfo_open')) {
    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = (string) finfo_buffer($finfo, $imageData);
    finfo_close($finfo);
}

if ($detectedMime !== '' && !in_array($detectedMime, $allowedMimeTypes, true)) {
    http_response_code(422);
    error_log('[webhook-foto] MIME no permitido: ' . $detectedMime . ' para ' . $photoName);
    exit(json_encode(['error' => 'El contenido descargado no es una imagen válida', 'mime' => $detectedMime]));
}

// Determinar el Content-Type: preferimos el MIME detectado; si no, lo derivamos de la extensión
$mimeMap    = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$uploadMime = $detectedMime !== '' ? $detectedMime : ($mimeMap[$ext] ?? 'application/octet-stream');

// ---------------------------------------------------------------------------
// 11. Procesar la imagen con Google AI (eliminar fondo + añadir detalles)
// ---------------------------------------------------------------------------

$aiResult = processImageWithGoogleAI($imageData, $uploadMime);

if (!$aiResult['success']) {
    http_response_code(502);
    error_log('[webhook-foto] Error en procesamiento Google AI: ' . ($aiResult['error'] ?? 'unknown'));
    exit(json_encode([
        'error'  => 'Error al procesar la imagen con Google AI',
        'detail' => $aiResult['error'] ?? '',
    ]));
}

// Reemplazar imagen original por la procesada
$imageData  = $aiResult['data'];
$uploadMime = $aiResult['mimeType'];  // Gemini devuelve image/png

// Actualizar nombre de fichero a .png (Gemini siempre devuelve PNG)
$photoName = pathinfo($photoName, PATHINFO_FILENAME) . '.png';

// ---------------------------------------------------------------------------
// 12. Subir la imagen procesada a AWS S3
// ---------------------------------------------------------------------------

$s3Key    = rtrim(AWS_S3_PREFIX, '/') . '/' . $photoName;

$s3Result = uploadToS3(
    AWS_S3_BUCKET,
    AWS_S3_REGION,
    $s3Key,
    AWS_S3_ACCESS_KEY,
    AWS_S3_SECRET_KEY,
    $imageData,
    $uploadMime
);

if (!$s3Result['success']) {
    http_response_code(502);
    error_log('[webhook-foto] Error al subir a S3: ' . ($s3Result['error'] ?? 'unknown'));
    exit(json_encode(['error' => 'Error al subir la imagen a S3', 'detail' => $s3Result['error'] ?? '']));
}

$fotoUrl = $s3Result['url'];

// ---------------------------------------------------------------------------
// 13. Actualizar la columna foto en zoho_leads con la URL de S3
// ---------------------------------------------------------------------------

try {
    $updateStmt = $pdo->prepare('UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET foto = :foto WHERE id = :id');
    $updateStmt->execute([
        ':foto' => $fotoUrl,
        ':id'   => $leadId,
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    error_log('[webhook-foto] DB error al actualizar foto: ' . $e->getMessage());
    exit(json_encode(['error' => 'Database error']));
}

// ---------------------------------------------------------------------------
// 14. Respuesta de éxito
// ---------------------------------------------------------------------------

http_response_code(200);
exit(json_encode([
    'status'    => 'ok',
    'id'        => $leadId,
    'id_alumno' => $idAlumno,
    'foto'      => $fotoUrl,
]));
