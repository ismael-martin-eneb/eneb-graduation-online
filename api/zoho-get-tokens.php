<?php
/**
 * zoho-get-tokens.php  — Script de UN SOLO USO por CLI
 *
 * Intercambia un authorization code de Zoho por access_token + refresh_token.
 * Una vez obtenido el refresh_token, guárdalo en config.php y no necesitarás
 * volver a ejecutar este script (el refresh_token no caduca).
 *
 * PASO PREVIO (manual en el navegador):
 *   1. Ve a https://api-console.zoho.eu/
 *   2. Abre tu Self Client → pestaña "Generate Code"
 *   3. Scope:       WorkDrive.files.READ
 *      (o el que necesites; separa varios con coma)
 *   4. Expiry time: 10 minutos (o el máximo disponible)
 *   5. Haz clic en CREATE → copia el código generado
 *
 * USO:
 *   php zoho-get-tokens.php <authorization_code>
 *
 * EJEMPLO:
 *   php zoho-get-tokens.php 1000.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 */

// ── Carga credenciales desde config.php ────────────────────────────────────
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: No se encuentra config.php en " . __DIR__ . "\n");
    exit(1);
}
require_once $configFile;

// ── Valida el argumento ─────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse por línea de comandos.');
}

if ($argc < 2 || trim($argv[1]) === '') {
    fwrite(STDERR, "Uso: php " . basename(__FILE__) . " <authorization_code>\n");
    exit(1);
}

$authCode = trim($argv[1]);

// ── Petición al endpoint de Zoho ────────────────────────────────────────────
if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERROR: La extensión cURL de PHP no está disponible.\n");
    exit(1);
}

$params = http_build_query([
    'grant_type'    => 'authorization_code',
    'client_id'     => ZOHO_WORKDRIVE_CLIENT_ID,
    'client_secret' => ZOHO_WORKDRIVE_CLIENT_SECRET,
    'code'          => $authCode,
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            'https://accounts.zoho.eu/oauth/v2/token');
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $params);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        15);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ── Gestión de errores ───────────────────────────────────────────────────────
if ($curlError !== '') {
    fwrite(STDERR, "ERROR cURL: $curlError\n");
    exit(1);
}

if ($httpCode !== 200) {
    fwrite(STDERR, "ERROR HTTP $httpCode:\n$response\n");
    exit(1);
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data['access_token'])) {
    fwrite(STDERR, "ERROR — Respuesta inesperada de Zoho:\n$response\n");
    exit(1);
}

// ── Resultado ───────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║            TOKENS OBTENIDOS CORRECTAMENTE                ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

echo "access_token  (válido ~1 hora):\n";
echo "  " . $data['access_token'] . "\n\n";

if (!empty($data['refresh_token'])) {
    echo "refresh_token (no caduca — guárdalo en config.php):\n";
    echo "  " . $data['refresh_token'] . "\n\n";

    // ── Ofrece actualizar config.php automáticamente ─────────────────────────
    echo "¿Actualizar ZOHO_WORKDRIVE_REFRESH_TOKEN en config.php automáticamente? [s/N]: ";
    $input = strtolower(trim(fgets(STDIN)));

    if ($input === 's') {
        $configContent = file_get_contents($configFile);
        $updated = preg_replace(
            "/define\s*\(\s*'ZOHO_WORKDRIVE_REFRESH_TOKEN'\s*,\s*'[^']*'\s*\)/",
            "define('ZOHO_WORKDRIVE_REFRESH_TOKEN', '" . addslashes($data['refresh_token']) . "')",
            $configContent,
            1,
            $count
        );

        if ($count === 0) {
            fwrite(STDERR, "AVISO: No se encontró la constante ZOHO_WORKDRIVE_REFRESH_TOKEN en config.php. Actualízala manualmente.\n");
        } else {
            file_put_contents($configFile, $updated);
            echo "✓ config.php actualizado con el nuevo refresh_token.\n";
        }
    } else {
        echo "No actualizado. Copia el refresh_token manualmente en config.php:\n";
        echo "  define('ZOHO_WORKDRIVE_REFRESH_TOKEN', '" . $data['refresh_token'] . "');\n";
    }
} else {
    echo "AVISO: Zoho no devolvió refresh_token. Respuesta completa:\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
}

echo "\n";
