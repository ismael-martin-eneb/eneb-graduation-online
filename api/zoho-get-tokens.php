<?php
/**
 * zoho-get-tokens.php  — Gestión de tokens OAuth2 de Zoho (CLI)
 *
 * COMANDOS:
 *
 *   auth
 *     Genera la URL de autorización. Ábrela en el navegador, acepta los
 *     permisos y copia el "code" que aparece en la URL de redirección.
 *       php zoho-get-tokens.php auth
 *
 *   token <authorization_code>
 *     Intercambia el código de autorización por access_token + refresh_token.
 *     Guarda el refresh_token en config.php (solo necesitas hacerlo una vez).
 *       php zoho-get-tokens.php token 1000.xxxx...
 *
 *   refresh
 *     Regenera el access_token usando el refresh_token almacenado en config.php.
 *       php zoho-get-tokens.php refresh
 */

// ── Carga credenciales desde config.php ────────────────────────────────────
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: No se encuentra config.php en " . __DIR__ . "\n");
    exit(1);
}
require_once $configFile;

// ── Validaciones generales ──────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse por línea de comandos.');
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERROR: La extensión cURL de PHP no está disponible.\n");
    exit(1);
}

$command = $argv[1] ?? '';

// ────────────────────────────────────────────────────────────────────────────
// COMANDO: auth
// Genera la URL de autorización para abrir en el navegador.
// ────────────────────────────────────────────────────────────────────────────
if ($command === 'auth') {
    $url = 'https://accounts.zoho.eu/oauth/v2/auth?' . http_build_query([
        'client_id'     => ZOHO_WORKDRIVE_CLIENT_ID,
        'redirect_uri'  => REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'WorkDrive.files.READ,ZohoFiles.files.READ',
        'access_type'   => 'offline',
    ]);

    echo "\n";
    echo "Abre esta URL en el navegador y acepta los permisos:\n\n";
    echo "  $url\n\n";
    echo "Después copia el parámetro 'code' de la URL de redirección y ejecuta:\n";
    echo "  php zoho-get-tokens.php token <code>\n\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// COMANDO: token <authorization_code>
// Intercambia el código por access_token + refresh_token.
// ────────────────────────────────────────────────────────────────────────────
if ($command === 'token') {
    if (empty($argv[2])) {
        fwrite(STDERR, "USO: php zoho-get-tokens.php token <authorization_code>\n");
        exit(1);
    }

    $params = http_build_query([
        'code'          => trim($argv[2]),
        'client_id'     => ZOHO_WORKDRIVE_CLIENT_ID,
        'client_secret' => ZOHO_WORKDRIVE_CLIENT_SECRET,
        'redirect_uri'  => REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    $response = zoho_post_token($params);
    $data     = parse_token_response($response);

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║          ACCESS + REFRESH TOKEN OBTENIDOS                ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
    echo "access_token  (válido ~1 hora):\n  " . $data['access_token'] . "\n\n";

    if (!empty($data['refresh_token'])) {
        echo "refresh_token (no caduca — guárdalo en config.php):\n  " . $data['refresh_token'] . "\n\n";
        offer_save_refresh_token($data['refresh_token'], $configFile);
    } else {
        echo "AVISO: Zoho no devolvió refresh_token.\n";
    }

    echo "\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// COMANDO: refresh
// Regenera el access_token usando el refresh_token de config.php.
// ────────────────────────────────────────────────────────────────────────────
if ($command === 'refresh') {
    if (!defined('ZOHO_WORKDRIVE_REFRESH_TOKEN') || ZOHO_WORKDRIVE_REFRESH_TOKEN === '') {
        fwrite(STDERR, "ERROR: ZOHO_WORKDRIVE_REFRESH_TOKEN no está definido en config.php.\n");
        exit(1);
    }

    $params = http_build_query([
        'refresh_token' => ZOHO_WORKDRIVE_REFRESH_TOKEN,
        'client_id'     => ZOHO_WORKDRIVE_CLIENT_ID,
        'client_secret' => ZOHO_WORKDRIVE_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
    ]);

    $response = zoho_post_token($params);
    $data     = parse_token_response($response);

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║            ACCESS TOKEN REGENERADO                       ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
    echo "access_token  (válido ~1 hora):\n  " . $data['access_token'] . "\n\n";
    exit(0);
}

// ── Ayuda ───────────────────────────────────────────────────────────────────
fwrite(STDERR, "USO: php zoho-get-tokens.php <comando> [opciones]\n\n");
fwrite(STDERR, "  auth                    Genera la URL de autorización\n");
fwrite(STDERR, "  token <code>            Obtiene access + refresh token\n");
fwrite(STDERR, "  refresh                 Regenera el access token\n\n");
exit(1);

// ── Funciones auxiliares ─────────────────────────────────────────────────────
function zoho_post_token(string $params): string
{
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

    if ($curlError !== '') {
        fwrite(STDERR, "ERROR cURL: $curlError\n");
        exit(1);
    }
    if ($httpCode !== 200) {
        fwrite(STDERR, "ERROR HTTP $httpCode:\n$response\n");
        exit(1);
    }

    return $response;
}

function parse_token_response(string $response): array
{
    $data = json_decode($response, true);

    if (!is_array($data) || empty($data['access_token'])) {
        fwrite(STDERR, "ERROR — Respuesta inesperada de Zoho:\n$response\n");
        exit(1);
    }

    return $data;
}

function offer_save_refresh_token(string $refreshToken, string $configFile): void
{
    echo "¿Actualizar ZOHO_WORKDRIVE_REFRESH_TOKEN en config.php automáticamente? [s/N]: ";
    $input = strtolower(trim(fgets(STDIN)));

    if ($input !== 's') {
        echo "No actualizado. Copia el refresh_token manualmente en config.php:\n";
        echo "  define('ZOHO_WORKDRIVE_REFRESH_TOKEN', '" . $refreshToken . "');\n";
        return;
    }

    $configContent = file_get_contents($configFile);
    $updated = preg_replace(
        "/define\s*\(\s*'ZOHO_WORKDRIVE_REFRESH_TOKEN'\s*,\s*'[^']*'\s*\)/",
        "define('ZOHO_WORKDRIVE_REFRESH_TOKEN', '" . addslashes($refreshToken) . "')",
        $configContent,
        1,
        $count
    );

    if ($count === 0) {
        fwrite(STDERR, "AVISO: No se encontró ZOHO_WORKDRIVE_REFRESH_TOKEN en config.php. Actualízala manualmente.\n");
    } else {
        file_put_contents($configFile, $updated);
        echo "✓ config.php actualizado con el nuevo refresh_token.\n";
    }
}
