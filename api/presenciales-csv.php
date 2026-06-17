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
        $sql .= ' WHERE nombre LIKE :q';
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY nombre ASC';

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
             (nombre, id_alumno, idioma, phone, intolerancias, linkedin, email) 
             VALUES (:nombre, :id_alumno, :idioma, :phone, :intolerancias, :linkedin, :email)'
        );

        foreach ($students as $idx => $student) {
            try {
                // Validar y sanitizar campos
                $nombre = sanitizeString($student['nombre'] ?? '', 120);
                if (trim($nombre) === '') {
                    $errors[] = "Fila " . ($idx + 1) . ": El nombre es obligatorio";
                    continue;
                }

                $idAlumno = sanitizeString($student['id_alumno'] ?? '', 50);
                $idioma = sanitizeString($student['idioma'] ?? '', 50);
                $phone = sanitizeString($student['phone'] ?? '', 30);
                $intolerancias = sanitizeString($student['intolerancias'] ?? '', 500);
                $linkedin = sanitizeString($student['linkedin'] ?? '', 255);
                $email = sanitizeString($student['email'] ?? '', 120);

                // Validar email si está presente
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Fila " . ($idx + 1) . ": Email inválido: $email";
                    continue;
                }

                // Insertar
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':id_alumno' => $idAlumno ?: null,
                    ':idioma' => $idioma ?: null,
                    ':phone' => $phone ?: null,
                    ':intolerancias' => $intolerancias ?: null,
                    ':linkedin' => $linkedin ?: null,
                    ':email' => $email ?: null,
                ]);

                $insertedCount++;
                $results[] = [
                    'nombre' => $nombre,
                    'id_alumno' => $idAlumno,
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
