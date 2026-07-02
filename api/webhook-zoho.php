<?php
/**
 * ENEB Graduación Online — Webhook receptor de Zoho Forms
 *
 * URL de uso en Zoho Forms:
 *   https://dev-graduados.eneb.es/api/webhook-zoho.php?token=TU_TOKEN_SECRETO
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
$fotoRaw      = $payload[ZOHO_FIELD_FOTO] ?? '';
$foto         = get_resource_id_from_url($fotoRaw);
if ($foto === null && $fotoRaw !== '') {
    error_log('[zoho-webhook] No se pudo extraer resource_id de foto_referencia. Valor recibido: ' . $fotoRaw);
}
$frase        = sanitizeString($payload[ZOHO_FIELD_FRASE] ?? '', 255);
$timecreated  = sanitizeDate($payload[ZOHO_FIELD_TIMECREATED] ?? '');

$errors = [];

if ($nombre === '') {
    $errors[] = 'El campo nombre es obligatorio';
}
if ($id_alumno === '') {
    $errors[] = 'El campo ID de estudiante es obligatorio';
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

    // Función auxiliar para calcular similitud de nombres (algoritmo de Levenshtein)
    $calculateSimilarity = function($str1, $str2) {
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));
        
        if ($str1 === $str2) {
            return 100;
        }
        
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        $maxLen = max($len1, $len2);
        
        if ($maxLen === 0) {
            return 100;
        }
        
        $distance = levenshtein($str1, $str2);
        $similarity = (1 - ($distance / $maxLen)) * 100;
        
        return max(0, $similarity);
    };

    // Buscar registros existentes con id_alumno coincidente
    $searchSql = 'SELECT id, nombre FROM ' . DB_TABLE_ZOHO_LEADS . ' WHERE id_alumno = :id_alumno';
    $searchStmt = $pdo->prepare($searchSql);
    $searchStmt->execute([':id_alumno' => $id_alumno]);
    $existingRecord = $searchStmt->fetch();

    $insertedId = null;

    if ($existingRecord) {
        // Calcular similitud del nombre (mínimo 80%)
        $similarity = $calculateSimilarity($nombre, $existingRecord['nombre']);
        
        if ($similarity >= 80) {
            // Actualizar registro existente
            $updateSql = 'UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET nombre = :nombre, foto = :foto, frase = :frase, timecreated = :timecreated, raw_payload = :raw_payload WHERE id = :id';
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                ':nombre'      => $nombre,
                ':foto'        => $foto,
                ':frase'       => $frase,
                ':timecreated' => $timecreated,
                ':raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id'          => $existingRecord['id'],
            ]);
            $insertedId = $existingRecord['id'];
        } else {
            // Insertar nuevo registro si el nombre no coincide al 80%
            $sql = 'INSERT INTO ' . DB_TABLE_ZOHO_LEADS . ' (nombre, id_alumno, foto, frase, timecreated, raw_payload) VALUES (:nombre, :id_alumno, :foto, :frase, :timecreated, :raw_payload)';
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
        }
    } else {
        // Insertar nuevo registro si no existe
        $sql = 'INSERT INTO ' . DB_TABLE_ZOHO_LEADS . ' (nombre, id_alumno, foto, frase, timecreated, raw_payload) VALUES (:nombre, :id_alumno, :foto, :frase, :timecreated, :raw_payload)';
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
    }

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

// Actualizo el registro con el país obtenido de Moodle (si existe)
$pais = $moodleResult['data']['pais'] ?? null;
if ($pais !== null) {
    try {
        $updateSql = 'UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET pais = :pais, campus = :campus WHERE id = :id';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':pais' => $pais,
            ':campus' => $moodleResult['site'],
            ':id'   => $insertedId,
        ]);
    } catch (PDOException $e) {
        error_log('[zoho-webhook] DB error al actualizar país: ' . $e->getMessage());
    }
}

$programas = $moodleResult['data']['programas'] ?? [];
if (!empty($programas)) {
    try {
        // Moodle devuelve: curso (string nombre), categoria (int id del curso), nota (double)
        $graduationDate = date('Y-m-d', $timecreated);

        // Preparar sentencias reutilizables fuera del bucle
        $checkProgSql  = 'SELECT id FROM ' . DB_TABLE_PROGRAMAS . ' WHERE id_curso = :id_curso AND campus = :campus LIMIT 1';
        $checkProgStmt = $pdo->prepare($checkProgSql);

        $insertProgSql  = 'INSERT INTO ' . DB_TABLE_PROGRAMAS . ' (id_curso, name, campus) VALUES (:id_curso, :name, :campus)';
        $insertProgStmt = $pdo->prepare($insertProgSql);

        $checkGradSql  = 'SELECT COUNT(*) FROM ' . DB_TABLE_GRADUADOS . ' WHERE id_alumno = :id_alumno AND program_id = :program_id';
        $checkGradStmt = $pdo->prepare($checkGradSql);

        $insertGradSql  = 'INSERT INTO ' . DB_TABLE_GRADUADOS . ' (id_alumno, program_id, graduation_date, grade, cumlaude)
                           VALUES (:id_alumno, :program_id, :graduation_date, :grade, :cumlaude)';
        $insertGradStmt = $pdo->prepare($insertGradSql);

        foreach ($programas as $prog) {
            $idCurso        = (int)   $prog['curso_id'];  // ID del curso en Moodle
            $nombrePrograma = (string) $prog['curso'];     // Nombre del programa
            $nota           = (float)  $prog['nota'];
            $cumlaude       = !empty($prog['cumlaude']) ? 1 : 0; // booleano de Moodle
            // fecha_fin = 0 significa sin fecha; en ese caso usamos timecreated del formulario
            $fechaGrad      = !empty($prog['fecha_fin'])
                ? date('Y-m-d', (int) $prog['fecha_fin'])
                : $graduationDate;

            // 1. Obtener o crear el programa en eneb_programs
            $checkProgStmt->execute([
                ':id_curso' => $idCurso,
                ':campus'   => $moodleResult['site'],
            ]);
            $existingProg = $checkProgStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingProg) {
                $programId = (int) $existingProg['id'];
            } else {
                $insertProgStmt->execute([
                    ':id_curso' => $idCurso,
                    ':name'     => $nombrePrograma,
                    ':campus'   => $moodleResult['site'],
                ]);
                $programId = (int) $pdo->lastInsertId();
            }

            // 2. Registrar la graduación en eneb_graduates (si no existe ya)
            $checkGradStmt->execute([
                ':id_alumno'  => $insertedId,
                ':program_id' => $programId,
            ]);
            $gradExists = (int) $checkGradStmt->fetchColumn();

            if ($gradExists === 0) {
                $insertGradStmt->execute([
                    ':id_alumno'       => $insertedId,
                    ':program_id'      => $programId,
                    ':graduation_date' => $fechaGrad,
                    ':grade'           => $nota,
                    ':cumlaude'        => $cumlaude,
                ]);
            }
        }
    } catch (PDOException $e) {
        error_log('[zoho-webhook] DB error al insertar programas/graduados: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 9. Descargar, procesar y subir la foto (si hay resource_id)
// ---------------------------------------------------------------------------

if ($foto !== null && $foto !== '') {
    try {
        $downloadUrl = 'https://download.zoho.eu/v1/workdrive/download/' . rawurlencode($foto);

        $doDownload = function (bool $forceRefresh) use ($downloadUrl): array {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,            $downloadUrl);
            curl_setopt($ch, CURLOPT_HTTPGET,        true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT,        30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS,      5);
            curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Zoho-oauthtoken ' . getZohoWorkDriveToken($forceRefresh)]);
            if (defined('MOODLE_SSL_VERIFY') && MOODLE_SSL_VERIFY === false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $data      = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            return ['data' => $data, 'httpCode' => $httpCode, 'curlError' => $curlError];
        };

        // Primer intento con token en caché
        $dl = $doDownload(false);

        // Si Zoho rechaza el token (401/403), forzar refresco y reintentar
        if (in_array($dl['httpCode'], [401, 403], true)) {
            error_log('[zoho-webhook] Token rechazado (HTTP ' . $dl['httpCode'] . '), forzando refresco y reintentando...');
            $dl = $doDownload(true);
        }

        if ($dl['curlError'] !== '') {
            error_log('[zoho-webhook] cURL error al descargar foto resource_id=' . $foto . ': ' . $dl['curlError']);
        } elseif ($dl['httpCode'] !== 200 || $dl['data'] === false || $dl['data'] === '') {
            error_log('[zoho-webhook] Error al descargar foto resource_id=' . $foto . ' HTTP=' . $dl['httpCode']);
        } else {
            $imageData = $dl['data'];

            // Detectar extensión por MIME
            $ext = 'jpg';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = (string) finfo_buffer($finfo, $imageData);
                finfo_close($finfo);
                if ($mime === 'image/png') {
                    $ext = 'png';
                }
            }

            // Guardar en images/
            $imagesDir = realpath(__DIR__ . '/../images') . '/';
            $fileName  = $id_alumno . '.' . $ext;
            $filePath  = $imagesDir . $fileName;

            if (file_put_contents($filePath, $imageData) === false) {
                error_log('[zoho-webhook] No se pudo guardar la imagen en ' . $filePath);
            } else {
                $fotoUrl = rtrim(REDIRECT_URI, '/') . '/images/' . $fileName;
                $pdo->prepare('UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET foto = :foto WHERE id = :id')
                    ->execute([':foto' => $fotoUrl, ':id' => $insertedId]);
                error_log('[zoho-webhook] Foto guardada: ' . $fotoUrl);
            }
        }
    } catch (\Throwable $e) {
        error_log('[zoho-webhook] Excepción en procesamiento de foto: ' . $e->getMessage());
    }
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



