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
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: No se pudo conectar a la base de datos: " . $e->getMessage() . "\n");
    exit(1);
}

// Obtener todos los alumnos
try {
    $alumnos = $pdo->query('SELECT id, nombre, foto, frase, timecreated, raw_payload, id_alumno, campus FROM zoho_leads')->fetchAll();
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: No se pudieron consultar los alumnos: " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($alumnos)) {
    echo "No hay alumnos para procesar.\n";
    exit(0);
}

echo "Procesando " . count($alumnos) . " alumno(s)...\n\n";

// Función auxiliar para calcular similitud de nombres
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

// Procesar cada alumno
$processedCount = 0;
$errorCount = 0;

foreach ($alumnos as $alumno) {
    $idAlumno   = $alumno['id_alumno'];
    $campus     = $alumno['campus'];
    $nombre     = $alumno['nombre'];
    $foto       = $alumno['foto'];
    $frase      = $alumno['frase'];
    $timecreated = (int) $alumno['timecreated'];
    $payload    = $alumno['raw_payload'];

    // Validaciones iniciales
    if (empty($idAlumno)) {
        echo "[ID: {$alumno['id']}] ERROR: id_alumno vacío\n";
        $errorCount++;
        continue;
    }

    if (empty($campus)) {
        echo "[ID: $idAlumno] ERROR: campus no definido\n";
        $errorCount++;
        continue;
    }

    echo "[ID: $idAlumno] Procesando: $nombre\n";

    // Consultar Moodle
    try {
        $embajador = getMoodleEmbajador_cli($idAlumno, $campus);
        
        if (!is_array($embajador) || empty($embajador['found'])) {
            echo "  ⚠ Alumno no encontrado en Moodle\n";
            continue;
        }
    } catch (Exception $e) {
        echo "  ✗ Error al consultar Moodle: " . $e->getMessage() . "\n";
        $errorCount++;
        continue;
    }

    // Procesar en base de datos
    try {
        // Buscar registro existente
        $searchSql = 'SELECT id FROM ' . DB_TABLE_ZOHO_LEADS . ' WHERE id_alumno = :id_alumno AND campus = :campus LIMIT 1';
        $searchStmt = $pdo->prepare($searchSql);
        $searchStmt->execute([
            ':id_alumno' => $idAlumno,
            ':campus'    => $campus,
        ]);
        $existingRecord = $searchStmt->fetch();

        $insertedId = null;

        if ($existingRecord) {
            // Actualizar registro existente
            $updateSql = 'UPDATE ' . DB_TABLE_ZOHO_LEADS . ' 
                         SET nombre = :nombre, foto = :foto, frase = :frase, timecreated = :timecreated, raw_payload = :raw_payload 
                         WHERE id = :id';
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
            echo "  ✓ Registro actualizado\n";
        } else {
            // Insertar nuevo registro
            $insertSql = 'INSERT INTO ' . DB_TABLE_ZOHO_LEADS . ' 
                         (nombre, id_alumno, frase, timecreated, campus, raw_payload) 
                         VALUES (:nombre, :id_alumno, :frase, :timecreated, :campus, :raw_payload)';
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                ':nombre'      => $nombre,
                ':id_alumno'   => $idAlumno,
                ':frase'       => $frase,
                ':timecreated' => $timecreated,
                ':campus'      => $campus,
                ':raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $insertedId = (int) $pdo->lastInsertId();
            echo "  ✓ Registro insertado (ID: $insertedId)\n";
        }

        // Actualizar país si está disponible
        $pais = $embajador['data']['pais'] ?? null;
        if ($pais && $insertedId) {
            $updatePaisSql = 'UPDATE ' . DB_TABLE_ZOHO_LEADS . ' SET pais = :pais WHERE id = :id';
            $updatePaisStmt = $pdo->prepare($updatePaisSql);
            $updatePaisStmt->execute([
                ':pais' => $pais,
                ':id'   => $insertedId,
            ]);
        }

        // Procesar programas y graduaciones
        $programas = $embajador['data']['programas'] ?? [];
        if (!empty($programas) && $insertedId) {
            $graduationDate = date('Y-m-d', $timecreated);

            foreach ($programas as $prog) {
                try {
                    $idCurso        = (int)   $prog['curso_id'];
                    $nombrePrograma = (string) $prog['curso'];
                    $nota           = (float)  $prog['nota'];
                    $cumlaude       = !empty($prog['cumlaude']) ? 1 : 0;
                    $fechaGrad      = !empty($prog['fecha_fin'])
                        ? date('Y-m-d', (int) $prog['fecha_fin'])
                        : $graduationDate;

                    // Obtener o crear programa
                    $checkProgSql = 'SELECT id FROM ' . DB_TABLE_PROGRAMAS . ' WHERE id_curso = :id_curso AND campus = :campus LIMIT 1';
                    $checkProgStmt = $pdo->prepare($checkProgSql);
                    $checkProgStmt->execute([
                        ':id_curso' => $idCurso,
                        ':campus'   => $campus,
                    ]);
                    $existingProg = $checkProgStmt->fetch();

                    if ($existingProg) {
                        $programId = (int) $existingProg['id'];
                    } else {
                        $insertProgSql = 'INSERT INTO ' . DB_TABLE_PROGRAMAS . ' (id_curso, name, campus) VALUES (:id_curso, :name, :campus)';
                        $insertProgStmt = $pdo->prepare($insertProgSql);
                        $insertProgStmt->execute([
                            ':id_curso' => $idCurso,
                            ':name'     => $nombrePrograma,
                            ':campus'   => $campus,
                        ]);
                        $programId = (int) $pdo->lastInsertId();
                    }

                    // Verificar si ya existe graduación
                    $checkGradSql = 'SELECT COUNT(*) FROM ' . DB_TABLE_GRADUADOS . ' WHERE id_alumno = :id_alumno AND program_id = :program_id';
                    $checkGradStmt = $pdo->prepare($checkGradSql);
                    $checkGradStmt->execute([
                        ':id_alumno'  => $insertedId,
                        ':program_id' => $programId,
                    ]);
                    $gradExists = (int) $checkGradStmt->fetchColumn();

                    if ($gradExists === 0) {
                        $insertGradSql = 'INSERT INTO ' . DB_TABLE_GRADUADOS . ' 
                                         (id_alumno, program_id, graduation_date, grade, cumlaude) 
                                         VALUES (:id_alumno, :program_id, :graduation_date, :grade, :cumlaude)';
                        $insertGradStmt = $pdo->prepare($insertGradSql);
                        $insertGradStmt->execute([
                            ':id_alumno'       => $insertedId,
                            ':program_id'      => $programId,
                            ':graduation_date' => $fechaGrad,
                            ':grade'           => $nota,
                            ':cumlaude'        => $cumlaude,
                        ]);
                    }
                } catch (PDOException $e) {
                    echo "  ⚠ Error al procesar programa: " . $e->getMessage() . "\n";
                }
            }
            echo "  ✓ Procesados " . count($programas) . " programa(s)\n";
        }

        $processedCount++;
        echo "  ✓ Alumno procesado exitosamente\n\n";

    } catch (PDOException $e) {
        echo "  ✗ Error en BD: " . $e->getMessage() . "\n\n";
        error_log('[zoho_get_graduates] Error procesando ID $idAlumno: ' . $e->getMessage());
        $errorCount++;
    }
}

echo "\n========================================\n";
echo "Resumen: $processedCount alumno(s) procesado(s), $errorCount error(es)\n";
echo "========================================\n";

exit($errorCount > 0 ? 1 : 0);