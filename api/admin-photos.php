<?php
/**
 * Panel de Administración — API de fotos
 *
 * GET  /api/admin-photos.php              → lista todos los zoho_leads con foto actual
 *                    ?q=nombre            → filtra por nombre
 * POST /api/admin-photos.php              → sube/asigna una foto a un lead
 *                    lead_id (int)        → ID del registro en zoho_leads
 *                    file (multipart)     → imagen JPG o PNG
 *                    skip_ai (bool, opt.) → si "1", omite el procesado con Google AI
 * POST /api/admin-photos.php action=delete → elimina la foto de un lead (foto = NULL)
 *                    lead_id (int)
 *
 * Autenticación: cabecera HTTP  X-Admin-Password: <ADMIN_PASSWORD>
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

// ── CORS (solo origen de desarrollo) ─────────────────────────────────────────
$allowedOrigins = ['http://localhost:5500', 'http://127.0.0.1:5500'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Autenticación ─────────────────────────────────────────────────────────────
// El cliente envía la contraseña codificada en base64 para evitar problemas
// con caracteres no-ASCII en cabeceras HTTP (p. ej. «ñ», «á», etc.).
$receivedRaw      = isset($_SERVER['HTTP_X_ADMIN_PASSWORD']) ? $_SERVER['HTTP_X_ADMIN_PASSWORD'] : '';
$receivedPassword = ($receivedRaw !== '') ? (base64_decode($receivedRaw, false) ?: '') : '';

if (!defined('ADMIN_PASSWORD') || !hash_equals(ADMIN_PASSWORD, $receivedPassword)) {
    http_response_code(401);
    error_log('[admin-photos] Intento de acceso con contraseña incorrecta desde ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit(json_encode(['error' => 'No autorizado']));
}

// ── Conexión a la base de datos ───────────────────────────────────────────────
try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\PDOException $e) {
    http_response_code(503);
    error_log('[admin-photos] DB error: ' . $e->getMessage());
    exit(json_encode(['error' => 'Base de datos no disponible']));
}

// ═════════════════════════════════════════════════════════════════════════════
// GET — Listar leads
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';

    // GROUP BY para obtener UNA fila por lead aunque tenga varios programas.
    // GROUP_CONCAT agrega los nombres de programa separados por " · ".
    $sql = '
        SELECT
            zl.id                                                              AS id,
            zl.nombre                                                          AS nombre,
            zl.id_alumno                                                       AS id_alumno,
            zl.foto                                                            AS foto,
            zl.frase                                                           AS frase,
            zl.pais                                                            AS pais,
            GROUP_CONCAT(ep.name ORDER BY ep.id SEPARATOR \' · \')            AS programas
        FROM ' . DB_TABLE_ZOHO_LEADS . ' zl
        LEFT JOIN ' . DB_TABLE_GRADUADOS . ' eg ON eg.id_alumno = zl.id
        LEFT JOIN ' . DB_TABLE_PROGRAMAS . ' ep ON ep.id = eg.program_id
    ';

    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE zl.nombre LIKE :q ';
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= ' GROUP BY zl.id, zl.nombre, zl.id_alumno, zl.foto, zl.frase, zl.pais ORDER BY zl.nombre';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leads = $stmt->fetchAll();
        echo json_encode(['leads' => $leads]);
    } catch (\PDOException $e) {
        http_response_code(500);
        error_log('[admin-photos] Error al listar leads: ' . $e->getMessage());
        exit(json_encode(['error' => 'Error al listar leads']));
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// POST — Subir foto o eliminar
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = isset($_POST['action']) ? trim($_POST['action']) : 'upload';
    $leadId = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;

    if ($leadId <= 0) {
        http_response_code(422);
        exit(json_encode(['error' => 'El campo lead_id es obligatorio y debe ser un entero positivo']));
    }

    // Verificar que el lead existe
    $findStmt = $pdo->prepare('SELECT id, nombre FROM ' . DB_TABLE_ZOHO_LEADS . ' WHERE id = :id LIMIT 1');
    $findStmt->execute([':id' => $leadId]);
    $lead = $findStmt->fetch();

    if (!$lead) {
        http_response_code(404);
        exit(json_encode(['error' => 'Lead no encontrado', 'lead_id' => $leadId]));
    }

    // ── Acción: eliminar foto ─────────────────────────────────────────────────
    if ($action === 'delete') {
        $updStmt = $pdo->prepare('UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET foto = NULL WHERE id = :id');
        $updStmt->execute([':id' => $leadId]);
        error_log('[admin-photos] Foto eliminada para lead_id=' . $leadId . ' (' . $lead['nombre'] . ')');
        echo json_encode(['success' => true, 'lead_id' => $leadId, 'foto_url' => null]);
        exit;
    }

    // ── Acción: actualizar información del lead ───────────────────────────────
    if ($action === 'update_info') {
        $updates = [];
        $params  = [':id' => $leadId];

        // id_alumno: entero positivo obligatorio (columna NOT NULL)
        if (array_key_exists('id_alumno', $_POST)) {
            $rawId = trim($_POST['id_alumno']);
            if ($rawId === '') {
                http_response_code(422);
                exit(json_encode(['error' => 'id_alumno no puede estar vacío']));
            }
            $idAlumnoVal = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($idAlumnoVal === false) {
                http_response_code(422);
                exit(json_encode(['error' => 'id_alumno debe ser un entero positivo']));
            }
            $updates[]            = 'id_alumno = :id_alumno';
            $params[':id_alumno'] = $idAlumnoVal;
        }

        // frase: texto libre, máx. 100 caracteres, obligatorio (columna NOT NULL)
        if (array_key_exists('frase', $_POST)) {
            $fraseVal = mb_substr(trim($_POST['frase']), 0, 100);
            if ($fraseVal === '') {
                http_response_code(422);
                exit(json_encode(['error' => 'frase no puede estar vacía']));
            }
            $updates[]        = 'frase = :frase';
            $params[':frase'] = $fraseVal;
        }

        // pais: código ISO 3166-1 alpha-2, nullable (columna VARCHAR(2) YES)
        if (array_key_exists('pais', $_POST)) {
            $paisVal = strtoupper(trim($_POST['pais']));
            if ($paisVal === '') {
                $updates[]       = 'pais = NULL';
            } elseif (!preg_match('/^[A-Z]{2}$/', $paisVal)) {
                http_response_code(422);
                exit(json_encode(['error' => 'pais debe ser un código ISO de 2 letras (ej: ES)']));
            } else {
                $updates[]       = 'pais = :pais';
                $params[':pais'] = $paisVal;
            }
        }

        if (!empty($updates)) {
            $updStmt = $pdo->prepare(
                'UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET ' . implode(', ', $updates) . ' WHERE id = :id'
            );
            $updStmt->execute($params);
            error_log('[admin-photos] Info actualizada para lead_id=' . $leadId . ' (' . $lead['nombre'] . ')');
        }

        echo json_encode(['success' => true, 'lead_id' => $leadId]);
        exit;
    }

    // ── Acción: subir foto ────────────────────────────────────────────────────
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
        http_response_code(422);
        exit(json_encode(['error' => 'No se recibió ningún fichero válido', 'upload_error' => $uploadError]));
    }

    $file = $_FILES['file'];

    // Validar tamaño (máx. 10 MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        http_response_code(422);
        exit(json_encode(['error' => 'El fichero es demasiado grande (máximo 10 MB)']));
    }

    // Validar MIME real (no confiar en la extensión del cliente)
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!function_exists('finfo_open')) {
        http_response_code(500);
        exit(json_encode(['error' => 'Extensión finfo no disponible en el servidor']));
    }
    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = (string) finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($detectedMime, $allowedMimeTypes, true)) {
        http_response_code(422);
        exit(json_encode(['error' => 'Tipo de fichero no permitido. Solo JPG, PNG o WebP.', 'mime' => $detectedMime]));
    }

    // Leer datos binarios
    $imageData = file_get_contents($file['tmp_name']);
    if ($imageData === false) {
        http_response_code(500);
        exit(json_encode(['error' => 'No se pudo leer el fichero subido']));
    }

    $skipAi = isset($_POST['skip_ai']) && $_POST['skip_ai'] === '1';

    if ($skipAi) {
        // Sin procesado AI: subir directamente con la extensión original
        $ext      = $detectedMime === 'image/png' ? 'png' : 'jpg';
        $uploadMime = $detectedMime === 'image/png' ? 'image/png' : 'image/jpeg';
    } else {
        // Procesar con Google AI (elimina fondo, genera imagen con toga, devuelve PNG)
        $aiResult = processImageWithGoogleAI($imageData, $detectedMime);

        if (!$aiResult['success']) {
            http_response_code(502);
            error_log('[admin-photos] Error Google AI para lead_id=' . $leadId . ': ' . ($aiResult['error'] ?? 'unknown'));
            exit(json_encode([
                'error'  => 'Error al procesar la imagen con Google AI',
                'detail' => $aiResult['error'] ?? '',
            ]));
        }

        $imageData  = $aiResult['data'];
        $uploadMime = $aiResult['mimeType'];   // Google AI devuelve image/png
        $ext        = 'png';
    }

    // Nombre de fichero en S3: ADMIN_{leadId}.{ext}
    $photoName = 'ADMIN_' . $leadId . '.' . $ext;
    $s3Key     = rtrim(AWS_S3_PREFIX, '/') . '/' . $photoName;

    // Subir a S3
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
        error_log('[admin-photos] Error S3 para lead_id=' . $leadId . ': ' . ($s3Result['error'] ?? 'unknown'));
        exit(json_encode([
            'error'  => 'Error al subir la imagen a S3',
            'detail' => $s3Result['error'] ?? '',
        ]));
    }

    $photoUrl = $s3Result['url'];

    // Actualizar zoho_leads.foto
    $updStmt = $pdo->prepare('UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET foto = :foto WHERE id = :id');
    $updStmt->execute([':foto' => $photoUrl, ':id' => $leadId]);

    error_log('[admin-photos] Foto actualizada para lead_id=' . $leadId
        . ' (' . $lead['nombre'] . ') → ' . $photoUrl
        . ($skipAi ? ' [sin AI]' : ' [con AI]'));

    echo json_encode([
        'success'  => true,
        'lead_id'  => $leadId,
        'foto_url' => $photoUrl,
        'ai_used'  => !$skipAi,
    ]);
    exit;
}

// Método no permitido
http_response_code(405);
header('Allow: GET, POST, OPTIONS');
exit(json_encode(['error' => 'Método no permitido']));
