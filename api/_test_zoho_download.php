<?php
/**
 * _test_zoho_download.php — Prueba de descarga de fichero desde Zoho WorkDrive
 *
 * USO:
 *   php8.1 api/_test_zoho_download.php <resource_id>
 *
 * EJEMPLO:
 *   php8.1 api/_test_zoho_download.php j5sdf4cc0ab0d5d5e4b8889e6c0a21b87a95d
 */

if (PHP_SAPI !== 'cli') { exit('Solo CLI'); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

if (empty($argv[1])) {
    fwrite(STDERR, "USO: php8.1 api/_test_zoho_download.php <resource_id>\n");
    exit(1);
}

$resourceId  = trim($argv[1]);
$downloadUrl = 'https://download.zoho.eu/v1/workdrive/download/' . rawurlencode($resourceId);

echo "Resource ID : $resourceId\n";
echo "URL         : $downloadUrl\n";

// Obtener token (forzar refresco para asegurarnos de que es fresco)
try {
    $token = getZohoWorkDriveToken(true);
    echo "Token       : " . substr($token, 0, 30) . "...\n\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR al obtener token: " . $e->getMessage() . "\n");
    exit(1);
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $downloadUrl);
curl_setopt($ch, CURLOPT_HTTPGET,        true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS,      5);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Zoho-oauthtoken ' . $token]);
curl_setopt($ch, CURLOPT_VERBOSE,        true);
$verboseLog = fopen('php://stderr', 'w');
curl_setopt($ch, CURLOPT_STDERR,         $verboseLog);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$mime      = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$size      = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
$curlError = curl_error($ch);
curl_close($ch);

echo "\n--- RESULTADO ---\n";
echo "HTTP Code    : $httpCode\n";
echo "Content-Type : $mime\n";
echo "Bytes recib. : $size\n";

if ($curlError !== '') {
    echo "cURL Error   : $curlError\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "Body         : " . substr((string)$response, 0, 500) . "\n";
    exit(1);
}

// Guardar el fichero descargado para inspeccionarlo
$outFile = __DIR__ . '/_test_zoho_download_result';
file_put_contents($outFile, $response);
echo "Fichero guardado en: $outFile\n";
echo "Primeros bytes (hex): " . bin2hex(substr($response, 0, 8)) . "\n";

// Detectar MIME real
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    echo "MIME detectado: " . finfo_buffer($finfo, $response) . "\n";
    finfo_close($finfo);
}
