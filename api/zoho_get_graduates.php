<?php

$configFile = __DIR__ . '/config.php';
$libFile    = __DIR__ . '/lib.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    error_log('[zoho_get_graduates] config.php no encontrado');
    exit(json_encode(['error' => 'Server misconfiguration']));
}

if (!file_exists($libFile)) {
    http_response_code(500);
    error_log('[zoho_get_graduates] lib.php no encontrado');
    exit(json_encode(['error' => 'Server misconfiguration']));
}

require_once $configFile;
require_once $libFile;

// ── Validaciones generales ──────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse por línea de comandos.');
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERROR: La extensión cURL de PHP no está disponible.\n");
    exit(1);
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

try {
    $alumnos = $pdo->query('SELECT id, nombre, foto, frase, timecreated, raw_payload, id_alumno, campus FROM zoho_leads')->fetchAll();

    foreach ($alumnos as $alumno) {
        if ($alumno['campus'] === null) {
            echo "Alumno ID: {$alumno['id_alumno']} - Campus no definido, se omite\n";
            continue;
        }
        if (empty($alumno['id_alumno'])) {
            echo "Alumno ID: {$alumno['id']} - id_alumno vacío, se omite\n";
            continue;
        }
        $idAlumno  = $alumno['id_alumno'];
        $campus    = $alumno['campus'];
        $nombre    = $alumno['nombre'];
        $foto      = $alumno['foto'];
        $frase     = $alumno['frase'];
        $timecreated = (int) $alumno['timecreated'];
        $payload   = $alumno['raw_payload'];


        $embajador = getMoodleEmbajador_cli($idAlumno, $campus);

        if ($embajador !== null) {
            echo "Alumno ID: $idAlumno - Embajador encontrado: " . json_encode($embajador) . "\n";
        } else {
            echo "Alumno ID: $idAlumno - No se encontró embajador válido\n";
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[zoho_get_graduates] Error al consultar alumnos: ' . $e->getMessage());
    exit(json_encode(['error' => 'Error al consultar alumnos']));
}

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
    $searchStmt->execute([':id_alumno' => $idAlumno]);
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
                ':id_alumno'   => $idAlumno,
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
            ':id_alumno'   => $idAlumno,
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

// Actualizo el registro con el país obtenido de Moodle (si existe)
$pais = $embajador['data']['pais'] ?? null;
if ($pais !== null) {
    try {
        $updateSql = 'UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET pais = :pais, campus = :campus WHERE id = :id';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':pais' => $pais,
            ':campus' => $embajador['site'],
            ':id'   => $insertedId,
        ]);
    } catch (PDOException $e) {
        error_log('[zoho-webhook] DB error al actualizar país: ' . $e->getMessage());
    }
}

$programas = $embajador['data']['programas'] ?? [];
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
                ':campus'   => $embajador['site'],
            ]);
            $existingProg = $checkProgStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingProg) {
                $programId = (int) $existingProg['id'];
            } else {
                $insertProgStmt->execute([
                    ':id_curso' => $idCurso,
                    ':name'     => $nombrePrograma,
                    ':campus'   => $embajador['site'],
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