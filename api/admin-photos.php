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
 * Autenticación: sesión de usuario + X-CSRF-Token; permisos por acción (auth-lib.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/lib.php';

// CORS, cabeceras JSON y comprobación de método (ver auth-lib.php)
apiBootstrap(['GET', 'POST']);

// La autenticación (sesión + CSRF) y los permisos se exigen por acción más abajo.
$pdo = getDb();

// ═════════════════════════════════════════════════════════════════════════════
// GET — Listar leads
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requirePermission('graduates.view');
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';

    // GROUP BY para obtener UNA fila por lead aunque tenga varios programas.
    // GROUP_CONCAT agrega los nombres de programa separados por " · ".
    $sql = '
        SELECT
            zl.id                                                              AS id,
            zl.nombre                                                          AS nombre,
            zl.id_alumno                                                       AS id_alumno,
            zl.foto                                                            AS foto,
            zl.foto_graduate                                                   AS foto_graduate,
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

    $sql .= ' GROUP BY zl.id, zl.nombre, zl.id_alumno, zl.foto, zl.foto_graduate, zl.frase, zl.pais ORDER BY zl.nombre';

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

    // Permiso requerido según la acción
    $requiredPermission = [
        'delete'      => 'photos.delete',
        'delete_lead' => 'graduates.delete',
        'update_info' => 'graduates.edit',
    ][$action] ?? 'photos.upload';
    requirePermission($requiredPermission);

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
        $targetField = isset($_POST['target_field']) && $_POST['target_field'] === 'foto_graduate' ? 'foto_graduate' : 'foto';
        $updStmt = $pdo->prepare('UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET ' . $targetField . ' = NULL WHERE id = :id');
        $updStmt->execute([':id' => $leadId]);
        error_log('[admin-photos] ' . $targetField . ' eliminada para lead_id=' . $leadId . ' (' . $lead['nombre'] . ')');
        echo json_encode(['success' => true, 'lead_id' => $leadId, 'foto_url' => null, 'target_field' => $targetField]);
        exit;
    }

    // ── Acción: eliminar lead completo ─────────────────────────────────────────
    if ($action === 'delete_lead') {
        $pdo->beginTransaction();
        try {
            // Eliminar de graduados (relación)
            $stmtGrad = $pdo->prepare('DELETE FROM ' . DB_TABLE_GRADUADOS . ' WHERE id_alumno = :id');
            $stmtGrad->execute([':id' => $leadId]);

            // Eliminar de zoho_leads
            $stmtLead = $pdo->prepare('DELETE FROM ' . DB_TABLE_ZOHO_LEADS . ' WHERE id = :id');
            $stmtLead->execute([':id' => $leadId]);

            $pdo->commit();
            error_log('[admin-photos] Lead eliminado lead_id=' . $leadId . ' (' . $lead['nombre'] . ')');
            echo json_encode(['success' => true, 'lead_id' => $leadId]);
        } catch (\PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            error_log('[admin-photos] Error al eliminar lead_id=' . $leadId . ': ' . $e->getMessage());
            exit(json_encode(['error' => 'Error al eliminar el lead completo']));
        }
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

        // nombre: opcional (el panel antiguo lo enviaba pero se ignoraba); no puede quedar vacío
        if (array_key_exists('nombre', $_POST)) {
            $nombreVal = mb_substr(trim($_POST['nombre']), 0, 150);
            if ($nombreVal === '') {
                http_response_code(422);
                exit(json_encode(['error' => 'nombre no puede estar vacío']));
            }
            $updates[]          = 'nombre = :nombre';
            $params[':nombre']  = $nombreVal;
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

    $targetField = isset($_POST['target_field']) && $_POST['target_field'] === 'foto_graduate' ? 'foto_graduate' : 'foto';

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

    // Nombre de fichero en S3: ADMIN_{leadId}.{ext} o GRADUATE_{leadId}.{ext}
    if ($targetField === 'foto_graduate') {
        $photoName = 'GRADUATE_' . $leadId . '.' . $ext;
    } else {
        $photoName = 'ADMIN_' . $leadId . '.' . $ext;
    }
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

    // Actualizar zoho_leads.foto o zoho_leads.foto_graduate
    $updStmt = $pdo->prepare('UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET ' . $targetField . ' = :foto WHERE id = :id');
    $updStmt->execute([':foto' => $photoUrl, ':id' => $leadId]);

    error_log('[admin-photos] ' . $targetField . ' actualizada para lead_id=' . $leadId
        . ' (' . $lead['nombre'] . ') → ' . $photoUrl
        . ($skipAi ? ' [sin AI]' : ' [con AI]'));

    echo json_encode([
        'success'      => true,
        'lead_id'      => $leadId,
        'foto_url'     => $photoUrl,
        'ai_used'      => !$skipAi,
        'target_field' => $targetField,
    ]);
    exit;
}

// Método no permitido
http_response_code(405);
header('Allow: GET, POST, OPTIONS');
exit(json_encode(['error' => 'Método no permitido']));
