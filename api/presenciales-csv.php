<?php
/**
 * Panel de Administración — API de Presenciales
 *
 * GET  /api/presenciales-csv.php              → lista todos los presenciales
 *                    ?q=nombre                → filtra por nombre
 * POST /api/presenciales-csv.php              → procesa CSV y agrega presenciales
 *                    students (JSON array)    → array de objetos con campos
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
$receivedRaw      = isset($_SERVER['HTTP_X_ADMIN_PASSWORD']) ? $_SERVER['HTTP_X_ADMIN_PASSWORD'] : '';
$receivedPassword = ($receivedRaw !== '') ? (base64_decode($receivedRaw, false) ?: '') : '';

if (!defined('ADMIN_PASSWORD') || !hash_equals(ADMIN_PASSWORD, $receivedPassword)) {
    http_response_code(401);
    error_log('[presenciales-csv] Intento de acceso con contraseña incorrecta desde ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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
    error_log('[presenciales-csv] DB error: ' . $e->getMessage());
    exit(json_encode(['error' => 'Base de datos no disponible']));
}

// ═════════════════════════════════════════════════════════════════════════════
// GET — Listar presenciales
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';

    $sql = 'SELECT * FROM ' . DB_TABLE_PRESENCIALES;
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE nombre_diploma LIKE :q';
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY nombre_diploma ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $presenciales = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $presenciales]);
    } catch (\PDOException $e) {
        http_response_code(500);
        error_log('[presenciales-csv] Error al listar: ' . $e->getMessage());
        exit(json_encode(['error' => 'Error al listar presenciales']));
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// POST — Procesar CSV
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputData = file_get_contents('php://input');
    $decoded = json_decode($inputData, true);

    if ($decoded === null) {
        http_response_code(400);
        exit(json_encode(['error' => 'JSON inválido']));
    }

    if (!isset($decoded['students']) || !is_array($decoded['students'])) {
        http_response_code(422);
        exit(json_encode(['error' => 'Campo "students" obligatorio y debe ser array']));
    }

    $students = $decoded['students'];
    $results = [];
    $errors = [];
    $insertedCount = 0;

    try {
        // Preparar la sentencia para insertar
        $stmt = $pdo->prepare(
            'INSERT INTO ' . DB_TABLE_PRESENCIALES . ' 
             (id_moodle, nombre_diploma, escuela, idioma, pais, ultimo_programa, n_programas_fin, graduacion, email, telefono, linkedin, interes_profesional, intolerancias, vip) 
             VALUES (:id_moodle, :nombre_diploma, :escuela, :idioma, :pais, :ultimo_programa, :n_programas_fin, :graduacion, :email, :telefono, :linkedin, :interes_profesional, :intolerancias, :vip)'
        );

        foreach ($students as $idx => $student) {
            try {
                $errors[] = 'Subiendo fila ' . ($idx + 1) . '. Datos: ' . json_encode($student);
                // Validar y sanitizar campos
                $nombre = sanitizeName($student['nombre_diploma'] ?? '', 120);
                if (trim($nombre) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El nombre es obligatorio";
                    continue;
                }
                $idAlumno = sanitizeString($student['id_moodle'] ?? '', 50);
                if(trim($idAlumno) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El ID Moodle es obligatorio";
                    continue;
                }
                $idAlumno = intval($idAlumno);
                $escuela = sanitizeString($student['escuela'] ?? '', 5);
                if(trim($escuela) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": La escuela es obligatoria";
                    continue;
                }
                $idioma = sanitizeString($student['idioma'] ?? '', 50);
                if(trim($idioma) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El idioma es obligatorio";
                    continue;
                }
                $pais = sanitizeString($student['pais'] ?? '', 100);
                if(trim($pais) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El país es obligatorio";
                    continue;
                }
                $ultimo_programa = sanitizeString($student['ultimo_programa'] ?? '', 255);
                if(trim($ultimo_programa) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El último programa es obligatorio";
                    continue;
                }
                $n_programas_fin = intval($student['n_programas_fin'] ?? 0);
                $graduacion = sanitizeString($student['graduacion'] ?? '', 50);
                if(trim($graduacion) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": La graduación es obligatoria";
                    continue;
                }
                $email = sanitizeEmail($student['email'] ?? '', 120);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Fila " . ($idx + 1) . ": Email inválido: $email";
                    continue;
                }
                $telefono = sanitizeString($student['telefono'] ?? '', 30);
                $linkedin = checkUrl($student['linkedin']);
                $interes_profesional = sanitizeString($student['interes_profesional'] ?? '', 255);
                $intolerancias = sanitizeString($student['intolerancias'] ?? '', 255);
                $vip_text = strtolower(trim($student['vip'] ?? ''));
                // Comprobamos si coincide con "si" o "sí"
                $vip = in_array($vip_text, ['si', 'sí']) ? 1 : 0;
                

                // Insertar
                $stmt->execute([
                    ':id_moodle' => $idAlumno,
                    ':nombre_diploma' => $nombre,
                    ':escuela' => $escuela,
                    ':idioma' => $idioma,
                    ':pais' => $pais,
                    ':ultimo_programa' => $ultimo_programa,
                    ':n_programas_fin' => $n_programas_fin,
                    ':graduacion' => $graduacion,
                    ':email' => $email,
                    ':telefono' => $telefono,
                    ':linkedin' => $linkedin,
                    ':interes_profesional' => $interes_profesional, 
                    ':intolerancias' => $intolerancias,
                    ':vip' => $vip
                ]);

                $insertedCount++;
                $results[] = [
                    'nombre_diploma' => $nombre,
                    'id_moodle' => $idAlumno,
                    'email' => $email,
                    'status' => 'ok'
                ];

            } catch (\Exception $e) {
                error_log('[presenciales-csv] Error procesando fila ' . ($idx + 1) . ': ' . $e->getMessage());
                $errors[] = "Fila " . ($idx + 1) . ": " . $e->getMessage();
            }
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'inserted' => $insertedCount,
            'total' => count($students),
            'results' => $results,
            'errors' => $errors
        ]);

    } catch (\PDOException $e) {
        http_response_code(500);
        error_log('[presenciales-csv] DB error: ' . $e->getMessage());
        exit(json_encode(['error' => 'Error en base de datos: ' . $e->getMessage()]));
    }
    exit;
}

http_response_code(405);
exit(json_encode(['error' => 'Método no permitido']));
