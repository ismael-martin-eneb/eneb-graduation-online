<?php
/**
 * Panel de Administración — API de Graduados Online (CSV)
 *
 * GET  /api/graduados-online-csv.php       → lista todos los zoho_leads
 *                    ?q=nombre             → filtra por nombre
 * POST /api/graduados-online-csv.php       → procesa CSV de graduados online
 *                    graduates (JSON array) → array de objetos con campos:
 *                                             nombre, id_alumno, frase, timecreated, campus
 *
 * Lógica:
 *   - Para cada fila, verificar si existe en zoho_leads con combinación id_alumno + campus
 *   - Si existe: actualizar los campos (nombre, frase, timecreated)
 *   - Si no existe: insertar EN zoho_leads Y llamar a getMoodleEmbajador_cli para obtener datos del campus
 *
 * Autenticación: cabecera HTTP X-Admin-Password: <ADMIN_PASSWORD>
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
$receivedRaw      = isset($_SERVER['HTTP_X_ADMIN_PASSWORD']) ? $_SERVER['HTTP_X_ADMIN_PASSWORD'] : '';
$receivedPassword = ($receivedRaw !== '') ? (base64_decode($receivedRaw, false) ?: '') : '';

if (!defined('ADMIN_PASSWORD') || !hash_equals(ADMIN_PASSWORD, $receivedPassword)) {
    http_response_code(401);
    error_log('[graduados-online-csv] Intento de acceso con contraseña incorrecta desde ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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
    error_log('[graduados-online-csv] DB error: ' . $e->getMessage());
    exit(json_encode(['error' => 'Base de datos no disponible']));
}

// ═════════════════════════════════════════════════════════════════════════════
// GET — Listar graduados online (zoho_leads)
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';

    $sql = 'SELECT id, nombre, id_alumno, campus, frase, foto, foto_graduate, timecreated FROM ' . DB_TABLE_ZOHO_LEADS;
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE nombre LIKE :q OR campus LIKE :q';
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY nombre ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $graduates = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $graduates]);
    } catch (\PDOException $e) {
        http_response_code(500);
        error_log('[graduados-online-csv] Error al listar: ' . $e->getMessage());
        exit(json_encode(['error' => 'Error al listar graduados online']));
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// POST — Procesar CSV de graduados online
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputData = file_get_contents('php://input');
    $decoded = json_decode($inputData, true);

    if ($decoded === null) {
        http_response_code(400);
        exit(json_encode(['error' => 'JSON inválido']));
    }

    if (!isset($decoded['graduates']) || !is_array($decoded['graduates'])) {
        http_response_code(422);
        exit(json_encode(['error' => 'Campo "graduates" obligatorio y debe ser array']));
    }

    $graduates = $decoded['graduates'];
    $results = [];
    $errors = [];
    $inserted_count = 0;
    $updated_count = 0;

    try {
        // Preparar sentencias
        $stmtCheck = $pdo->prepare(
            'SELECT id FROM ' . DB_TABLE_ZOHO_LEADS . ' WHERE id_alumno = :id_alumno AND campus = :campus LIMIT 1'
        );

        $stmtUpdate = $pdo->prepare(
            'UPDATE ' . DB_TABLE_ZOHO_LEADS . ' 
             SET nombre = :nombre, frase = :frase, timecreated = :timecreated 
             WHERE id_alumno = :id_alumno AND campus = :campus'
        );

        $stmtInsert = $pdo->prepare(
            'INSERT INTO ' . DB_TABLE_ZOHO_LEADS . ' 
             (nombre, id_alumno, frase, timecreated, campus, raw_payload) 
             VALUES (:nombre, :id_alumno, :frase, :timecreated, :campus, :raw_payload)'
        );

        foreach ($graduates as $idx => $graduate) {
            try {
                // Validar y sanitizar campos requeridos
                $nombre = sanitizeName($graduate['nombre'] ?? '', 255);
                if (trim($nombre) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El nombre es obligatorio";
                    continue;
                }

                $id_alumno_raw = trim($graduate['id_alumno'] ?? '');
                if ($id_alumno_raw === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El id_alumno es obligatorio";
                    continue;
                }
                $id_alumno = (int) $id_alumno_raw;

                $campus = sanitizeString($graduate['campus'] ?? '', 255);
                if (trim($campus) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El campus es obligatorio";
                    continue;
                }

                $frase = sanitizeString($graduate['frase'] ?? '', 255);
                if (trim($frase) === '') {
                    $frase = ''; // Permitir frase vacía
                }

                // timecreated puede ser una cadena de fecha o timestamp
                $timecreated_raw = $graduate['timecreated'] ?? '';
                if (is_numeric($timecreated_raw)) {
                    $timecreated = (int) $timecreated_raw;
                } else {
                    $timecreated = sanitizeDate($timecreated_raw);
                }

                if ($timecreated === 0) {
                    $timecreated = time();
                }

                // Verificar si ya existe
                $stmtCheck->execute([
                    ':id_alumno' => $id_alumno,
                    ':campus' => $campus,
                ]);
                $existing = $stmtCheck->fetch();

                if ($existing) {
                    // Actualizar
                    $stmtUpdate->execute([
                        ':nombre' => $nombre,
                        ':frase' => $frase,
                        ':timecreated' => $timecreated,
                        ':id_alumno' => $id_alumno,
                        ':campus' => $campus,
                    ]);
                    $updated_count++;
                    $results[] = [
                        'nombre' => $nombre,
                        'id_alumno' => $id_alumno,
                        'campus' => $campus,
                        'status' => 'updated'
                    ];
                    error_log('[graduados-online-csv] Fila ' . ($idx + 1) . ': Actualizado id_alumno=' . $id_alumno . ' campus=' . $campus);
                } else {
                    // Insertar
                    $stmtInsert->execute([
                        ':nombre' => $nombre,
                        ':id_alumno' => $id_alumno,
                        ':frase' => $frase,
                        ':timecreated' => $timecreated,
                        ':campus' => $campus,
                        ':raw_payload' => json_encode($graduate),
                    ]);
                    $inserted_count++;

                    // Lanzar la lógica de consulta a Moodle
                    $moodleResult = getMoodleEmbajador_cli((string) $id_alumno, $campus);
                    $moodleStatus = $moodleResult['found'] ? 'ok' : 'not_found';
                    
                    $results[] = [
                        'nombre' => $nombre,
                        'id_alumno' => $id_alumno,
                        'campus' => $campus,
                        'status' => 'inserted',
                        'moodle_status' => $moodleStatus,
                    ];
                    error_log('[graduados-online-csv] Fila ' . ($idx + 1) . ': Insertado id_alumno=' . $id_alumno . ' campus=' . $campus . ' moodle_status=' . $moodleStatus);
                }

            } catch (\Exception $e) {
                error_log('[graduados-online-csv] Error procesando fila ' . ($idx + 1) . ': ' . $e->getMessage());
                $errors[] = "Fila " . ($idx + 1) . ": " . $e->getMessage();
            }
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'inserted' => $inserted_count,
            'updated' => $updated_count,
            'total' => count($graduates),
            'results' => $results,
            'errors' => $errors
        ]);

    } catch (\PDOException $e) {
        http_response_code(500);
        error_log('[graduados-online-csv] DB error: ' . $e->getMessage());
        exit(json_encode(['error' => 'Error en base de datos: ' . $e->getMessage()]));
    }
    exit;
}

http_response_code(405);
exit(json_encode(['error' => 'Método no permitido']));
