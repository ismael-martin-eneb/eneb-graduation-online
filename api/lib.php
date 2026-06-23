<?php

// archivo de funciones auxiliares para el webhook de Zoho Forms

/**
 * Limpia una cadena de texto: elimina espacios, caracteres de control y
 * limita la longitud para evitar desbordamientos.
 */
function sanitizeString($value, int $maxLen = 255): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value);
    return mb_substr(trim($clean), 0, $maxLen);
}

/**
 * Limpia una cadena de nombre y la devuelve formateada con mayusculas iniciales
 */
function sanitizeName($value, int $maxLen = 255): string
{
    $clean = sanitizeString($value, $maxLen);
    $cleaner = str_replace(',', '', $clean);
    // Convertir a minúsculas y luego capitalizar la primera letra de cada palabra
    return mb_convert_case($cleaner, MB_CASE_TITLE, "UTF-8");
}

/**
 * Función para limpiar las cadenas de email
 */
function sanitizeEmail($value, int $maxLen = 255): string
{
    $clean = sanitizeString($value, $maxLen);
    return mb_strtolower(filter_var($clean, FILTER_SANITIZE_EMAIL));
}

/**
 * función para comprobar url
 */
function checkUrl($url): string
{
    $clean = sanitizeString($url, 255);
    if (filter_var($clean, FILTER_VALIDATE_URL)) {
        return $clean;
    }
    return '';
}

/** 
 * Limpia el parámetro foto_referencia y devuelve solo el resource_id de la foto de Zoho WorkDrive, o null si no es válido.
 * 
 */
function get_resource_id_from_url($url): ?string
{
    if (!is_string($url) || trim($url) === '') {
        return null;
    }

    $url = trim($url);

    // Cualquier ruta bajo workdrive.zoho.eu o workdrive.zoho.com
    // Ej: /file/{id}, /home/files/{id}, /writer/open/{id}, etc.
    if (preg_match('/workdrive\.zoho\.(?:eu|com)\/(?:[^?#\/]+\/)*([a-zA-Z0-9_-]{10,})/', $url, $matches)) {
        return $matches[1];
    }

    // Si no parece una URL pero tiene el aspecto de un resource_id directamente
    if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $url)) {
        return $url;
    }

    return null;
}

/**
 * Prepara la fecha recibida por el formulario en formato DD-Mes-AAAA HH:MM:SS y la convierte a timestamp Unix. * Si no se puede parsear, devuelve 0.
 *
 * @param mixed $value
 * @return int
 */
function sanitizeDate($value): int
{
    $timestamp = strtotime($value);
    return $timestamp !== false ? $timestamp : 0;
}

/**
 * Llama a una función del webservice REST de Moodle.
 *
 * @param string $baseUrl   URL base de Moodle (sin barra final)
 * @param string $token     Token de webservice de Moodle
 * @param string $function  Nombre de la función (p. ej. local_external_functions_get_embajador_by_id)
 * @param array  $params    Parámetros adicionales de la función
 * @return array            Respuesta decodificada o ['error' => '...'] en caso de fallo
 */
function callMoodleWebservice(string $baseUrl, string $token, string $function, array $params = []): array
{
    if (!function_exists('curl_init')) {
        error_log('[moodle-ws] La extensión cURL no está disponible');
        return ['error' => 'cURL not available'];
    }

    $url = rtrim($baseUrl, '/') . '/webservice/rest/server.php';

    $postFields = array_merge($params, [
        'wstoken'             => $token,
        'wsfunction'          => $function,
        'moodlewsrestformat'  => 'json',
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // Desactiva la verificación SSL si MOODLE_SSL_VERIFY está definido como false en config.php
    if (defined('MOODLE_SSL_VERIFY') && MOODLE_SSL_VERIFY === false) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        error_log('[moodle-ws] cURL error: ' . $curlError);
        return ['error' => 'Moodle connection error'];
    }

    if ($httpCode !== 200) {
        error_log('[moodle-ws] HTTP ' . $httpCode . ' al llamar a ' . $function);
        return ['error' => 'Moodle HTTP error', 'code' => $httpCode];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        error_log('[moodle-ws] Respuesta no JSON de Moodle: ' . $response);
        return ['error' => 'Invalid Moodle response'];
    }

    // Moodle devuelve {'exception':...,'message':...} cuando hay un error de la función
    if (isset($data['exception'])) {
        error_log('[moodle-ws] Excepción Moodle: ' . ($data['message'] ?? $data['exception']));
        return ['error' => $data['message'] ?? $data['exception']];
    }

    return $data;
}

/**
 * Normaliza un nombre para comparación: minúsculas, sin acentos, sin puntuación, espacios simples.
 */
function normalizeText(string $text): string
{
    // Transliterar caracteres acentuados a ASCII
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($normalized === false) {
        $normalized = $text;
    }
    // Minúsculas, eliminar todo lo que no sea letra o espacio
    $normalized = strtolower($normalized);
    $normalized = preg_replace('/[^a-z\s]/', '', $normalized);
    // Colapsar múltiples espacios
    return trim(preg_replace('/\s+/', ' ', $normalized));
}

/**
 * Devuelve el porcentaje de similitud entre dos nombres (0-100).
 * Usa similar_text() de PHP sobre las versiones normalizadas.
 */
function nameMatchPercent(string $nameA, string $nameB): float
{
    $a = normalizeText($nameA);
    $b = normalizeText($nameB);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $percent);
    return round($percent, 2);
}

/**
 * Intenta obtener los datos del alumno probando los tres sitios Moodle.
 * Devuelve un array con 'data' (respuesta de Moodle) y 'site' (URL del sitio que respondió),
 * o null si ningún sitio devuelve datos válidos.
 *
 * @param string $idAlumno
 * @param string $zohoNombre  Nombre recibido de Zoho para validar la coincidencia
 * @param float  $minScore    Porcentaje mínimo de similitud requerido (0-100)
 * @return array
 */
function getMoodleEmbajador(string $idAlumno, string $zohoNombre, float $minScore = 80.0): array
{
    $sites = [
        ['url' => MOODLE_BASE_URL_1, 'token' => MOODLE_WS_TOKEN_1],
        ['url' => MOODLE_BASE_URL_2, 'token' => MOODLE_WS_TOKEN_2],
        ['url' => MOODLE_BASE_URL_3, 'token' => MOODLE_WS_TOKEN_3],
    ];

    $siteErrors = [];

    foreach ($sites as $site) {
        $result = callMoodleWebservice(
            $site['url'],
            $site['token'],
            'local_external_functions_get_embajador_by_id',
            ['userid' => (int) $idAlumno]
        );

        // Respuesta válida: debe contener el campo userid devuelto por el WS
        if (!isset($result['error']) && isset($result['userid'])) {
            // Validar que el nombre Zoho coincide con el nombre Moodle al menos un minScore%
            $moodleName = trim(($result['firstname'] ?? '') . ' ' . ($result['lastname'] ?? ''));
            $score = nameMatchPercent($zohoNombre, $moodleName);

            if ($score < $minScore) {
                error_log(sprintf(
                    '[moodle-ws] Nombre no coincide (%.1f%% < %.1f%%): Zoho="%s" Moodle="%s" userid=%s',
                    $score, $minScore, $zohoNombre, $moodleName, $idAlumno
                ));
                return [
                    'found'        => false,
                    'reason'       => 'name_mismatch',
                    'zoho_name'    => $zohoNombre,
                    'moodle_name'  => $moodleName,
                    'score'        => $score,
                    'min_score'    => $minScore,
                    'site'         => $site['url'],
                ];
            }

            return [
                'found'  => true,
                'data'   => $result,
                'site'   => $site['url'],
                'score'  => $score,
            ];
        }

        $siteErrors[$site['url']] = $result['error'] ?? 'no data';
        error_log('[moodle-ws] ' . $site['url'] . ' falló para userid=' . $idAlumno . ': ' . ($result['error'] ?? 'no data'));
    }

    return ['found' => false, 'reason' => 'not_found', 'errors' => $siteErrors];
}

/**
 * Intenta obtener los datos del alumno probando los tres sitios Moodle.
 * Devuelve un array con 'data' (respuesta de Moodle) y 'site' (URL del sitio que respondió),
 * o null si ningún sitio devuelve datos válidos.
 *
 * @param string $idAlumno
 * @param string $urlCampus  URL base del campus Moodle (para filtrar el sitio correcto)
 * @return array
 */
function getMoodleEmbajador_cli(string $idAlumno, string $urlCampus): array
{
    $sites = [
        ['url' => MOODLE_BASE_URL_1, 'token' => MOODLE_WS_TOKEN_1],
        ['url' => MOODLE_BASE_URL_2, 'token' => MOODLE_WS_TOKEN_2],
        ['url' => MOODLE_BASE_URL_3, 'token' => MOODLE_WS_TOKEN_3],
    ];

    $siteErrors = [];

    foreach ($sites as $site) {
        if ($urlCampus !== $site['url']) {
            continue; // Si se especificó un campus, solo consultar ese sitio
        }
        $result = callMoodleWebservice(
            $site['url'],
            $site['token'],
            'local_external_functions_get_embajador_by_id',
            ['userid' => (int) $idAlumno]
        );

        // Respuesta válida: debe contener el campo userid devuelto por el WS
        if (!isset($result['error']) && isset($result['userid'])) {
            // Validar que el nombre Zoho coincide con el nombre Moodle al menos un minScore%
            $moodleName = trim(($result['firstname'] ?? '') . ' ' . ($result['lastname'] ?? ''));

            return [
                'found'  => true,
                'data'   => $result,
                'site'   => $site['url'],
            ];
        }

        $siteErrors[$site['url']] = $result['error'] ?? 'no data';
        error_log('[moodle-ws] ' . $site['url'] . ' falló para userid=' . $idAlumno . ': ' . ($result['error'] ?? 'no data'));
    }

    return ['found' => false, 'reason' => 'not_found', 'errors' => $siteErrors];
}

// ---------------------------------------------------------------------------
// Zoho WorkDrive — Auto-refresh de access token
// ---------------------------------------------------------------------------

/**
 * Devuelve un access token válido para Zoho WorkDrive.
 *
 * Estrategia:
 *   1. Guarda el último token y su expiración en un fichero de caché junto
 *      a config.php (fuera del docroot, no accesible vía web).
 *   2. Si el token en caché sigue vigente (con 2 min de margen), lo devuelve.
 *   3. Si caducó o no existe, llama a accounts.zoho.eu para renovarlo con el
 *      refresh token y guarda el resultado en la caché.
 *
 * Requiere en config.php:
 *   ZOHO_WORKDRIVE_CLIENT_ID, ZOHO_WORKDRIVE_CLIENT_SECRET,
 *   ZOHO_WORKDRIVE_REFRESH_TOKEN
 *
 * @return string  Access token listo para usar en la cabecera Authorization.
 * @throws RuntimeException si no se puede renovar el token.
 */
function getZohoWorkDriveToken(bool $forceRefresh = false): string
{
    $cacheFile = __DIR__ . '/zoho_token_cache.json';
    $margin    = 120; // renovar 2 min antes de que caduque

    // 1. Leer caché (se omite si se pide refresco forzado)
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (
            is_array($cache)
            && isset($cache['access_token'], $cache['expires_at'])
            && time() < ((int) $cache['expires_at'] - $margin)
        ) {
            return (string) $cache['access_token'];
        }
    }

    // 2. Renovar token
    if (!function_exists('curl_init')) {
        throw new RuntimeException('[zoho-token] cURL no está disponible');
    }

    $postFields = http_build_query([
        'grant_type'    => 'refresh_token',
        'client_id'     => ZOHO_WORKDRIVE_CLIENT_ID,
        'client_secret' => ZOHO_WORKDRIVE_CLIENT_SECRET,
        'refresh_token' => ZOHO_WORKDRIVE_REFRESH_TOKEN,
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            'https://accounts.zoho.eu/oauth/v2/token');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/x-www-form-urlencoded']);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('[zoho-token] cURL error: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('[zoho-token] HTTP ' . $httpCode . ': ' . $response);
    }

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        throw new RuntimeException('[zoho-token] Respuesta inesperada: ' . $response);
    }

    // Zoho devuelve expires_in en segundos (normalmente 3600)
    $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;

    // 3. Guardar caché
    $cache = [
        'access_token' => $data['access_token'],
        'expires_at'   => time() + $expiresIn,
    ];
    file_put_contents($cacheFile, json_encode($cache), LOCK_EX);

    error_log('[zoho-token] Token renovado. Expira en ' . $expiresIn . 's');
    return (string) $data['access_token'];
}

/**
 * Sube un fichero a Amazon S3 usando AWS Signature Version 4 (sin dependencias externas).
 *
 * Usa virtual-hosted-style URL: https://{bucket}.s3.{region}.amazonaws.com/{key}
 *
 * @param string $bucket      Nombre del bucket S3
 * @param string $region      Región AWS (p. ej. 'eu-west-1', 'us-east-1')
 * @param string $key         Clave del objeto dentro del bucket (p. ej. 'fotos/imagen.jpg')
 * @param string $accessKey   AWS Access Key ID
 * @param string $secretKey   AWS Secret Access Key
 * @param string $fileContent Contenido binario del fichero
 * @param string $contentType MIME type del fichero (p. ej. 'image/jpeg')
 * @return array ['success' => bool, 'url' => string] | ['success' => bool, 'error' => string]
 */
function uploadToS3(
    string $bucket,
    string $region,
    string $key,
    string $accessKey,
    string $secretKey,
    string $fileContent,
    string $contentType
): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL not available'];
    }

    $service  = 's3';
    $host     = $bucket . '.s3.' . $region . '.amazonaws.com';

    // Codificar cada segmento de la ruta por separado (no el separador /)
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
    $endpoint   = 'https://' . $host . '/' . $encodedKey;

    $datetime    = gmdate('Ymd\THis\Z');
    $date        = gmdate('Ymd');
    $payloadHash = hash('sha256', $fileContent);

    // ------------------------------------------------------------------
    // Paso 1: Petición canónica (AWS SigV4)
    // ------------------------------------------------------------------
    $canonicalHeaders = "content-type:{$contentType}\n"
                      . "host:{$host}\n"
                      . "x-amz-content-sha256:{$payloadHash}\n"
                      . "x-amz-date:{$datetime}\n";
    $signedHeaders    = 'content-type;host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = implode("\n", [
        'PUT',
        '/' . $encodedKey,
        '',  // query string vacío
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    // ------------------------------------------------------------------
    // Paso 2: String to Sign
    // ------------------------------------------------------------------
    $credentialScope = "{$date}/{$region}/{$service}/aws4_request";
    $stringToSign    = "AWS4-HMAC-SHA256\n{$datetime}\n{$credentialScope}\n"
                     . hash('sha256', $canonicalRequest);

    // ------------------------------------------------------------------
    // Paso 3: Clave de firma derivada (HMAC en cadena)
    // ------------------------------------------------------------------
    $kDate    = hash_hmac('sha256', $date,          'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,        $kDate,              true);
    $kService = hash_hmac('sha256', $service,       $kRegion,            true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService,           true);

    // ------------------------------------------------------------------
    // Paso 4: Firma y cabecera Authorization
    // ------------------------------------------------------------------
    $signature     = hash_hmac('sha256', $stringToSign, $kSigning);
    $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope},"
                   . " SignedHeaders={$signedHeaders},"
                   . " Signature={$signature}";

    // ------------------------------------------------------------------
    // Petición PUT a S3
    // ------------------------------------------------------------------
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $fileContent);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        60);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: '          . $contentType,
        'Host: '                  . $host,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: '           . $datetime,
        'Authorization: '        . $authorization,
        'Content-Length: '       . strlen($fileContent),
    ]);

    if (defined('MOODLE_SSL_VERIFY') && MOODLE_SSL_VERIFY === false) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        error_log('[s3-upload] cURL error: ' . $curlError);
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }

    if ($httpCode !== 200) {
        error_log('[s3-upload] HTTP ' . $httpCode . ' al subir ' . $key . ': ' . $response);
        return ['success' => false, 'error' => 'S3 HTTP ' . $httpCode, 'response' => $response];
    }

    return [
        'success' => true,
        'url'     => 'https://' . $host . '/' . $encodedKey,
    ];
}

/**
 * Procesa la foto de un graduado con Vertex AI Imagen 3 (pipeline de 2 pasos):
 *
 *   Paso 1 — BGREMOVAL: elimina el fondo original (sea el que sea) y devuelve
 *             la persona sobre un fondo de color sólido variable (croma).
 *             Post-proceso GD: flood-fill desde los bordes para reemplazar ese
 *             fondo sólido por blanco puro.
 *
 *   Paso 2 — Transformación a toga: usando REFERENCE_TYPE_SUBJECT + SUBJECT_TYPE_PERSON
 *             el modelo genera al mismo alumno vestido con toga académica negra,
 *             birrete negro y beca de color #e41416, sobre fondo blanco.
 *             Si este paso falla, se devuelve como fallback la imagen del Paso 1.
 *
 * Requiere en config.php:
 *   GOOGLE_CLOUD_PROJECT_ID, GOOGLE_CLOUD_LOCATION, GOOGLE_AI_MODEL,
 *   GOOGLE_SERVICE_ACCOUNT_KEY_FILE
 *
 * @param string $imageData  Contenido binario de la imagen original
 * @param string $mimeType   MIME type de la imagen (p. ej. 'image/jpeg')
 * @return array ['success'=>true, 'data'=>string, 'mimeType'=>string]
 *             | ['success'=>false, 'error'=>string, 'detail'=>string]
 */
function processImageWithGoogleAI(string $imageData, string $mimeType): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL not available'];
    }

    // -----------------------------------------------------------------
    // 1. Access token OAuth2
    // -----------------------------------------------------------------
    $tokenResult = _getVertexAIAccessToken();
    if (!$tokenResult['success']) {
        return ['success' => false, 'error' => $tokenResult['error']];
    }
    $accessToken = $tokenResult['token'];

    // -----------------------------------------------------------------
    // 2. Endpoint
    // -----------------------------------------------------------------
    $project  = defined('GOOGLE_CLOUD_PROJECT_ID') ? GOOGLE_CLOUD_PROJECT_ID : '';
    $location = defined('GOOGLE_CLOUD_LOCATION')   ? GOOGLE_CLOUD_LOCATION   : 'us-central1';
    $model    = defined('GOOGLE_AI_MODEL')          ? GOOGLE_AI_MODEL         : 'imagen-3.0-capability-001';

    if ($project === '') {
        error_log('[google-ai] GOOGLE_CLOUD_PROJECT_ID no configurado');
        return ['success' => false, 'error' => 'GOOGLE_CLOUD_PROJECT_ID no configurado'];
    }

    $url = sprintf(
        'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:predict',
        rawurlencode($location),
        rawurlencode($project),
        rawurlencode($location),
        rawurlencode($model)
    );

    // =================================================================
    // PASO 1: BGREMOVAL — aislar persona sobre fondo sólido de croma
    // =================================================================
    $bgBody = json_encode([
        'instances' => [[
            'referenceImages' => [[
                'referenceType'  => 'REFERENCE_TYPE_RAW',
                'referenceId'    => 1,
                'referenceImage' => ['bytesBase64Encoded' => base64_encode($imageData)],
            ]],
            'prompt'     => 'remove the background',
            'editConfig' => ['editMode' => 'BGREMOVAL'],
        ]],
        'parameters' => ['sampleCount' => 1],
    ]);

    $bgResult = _vertexPredict($url, $bgBody, $accessToken);
    if (!$bgResult['success']) {
        // Si BGREMOVAL falla, continuar con la imagen original (fallback silencioso)
        error_log('[google-ai] Paso 1 (BGREMOVAL) falló: ' . $bgResult['error'] . ' — usando imagen original');
        $workingImage = $imageData;
    } else {
        // -----------------------------------------------------------------
        // Post-proceso GD: reemplazar el fondo de color sólido por blanco
        // usando flood-fill desde los bordes exteriores (no afecta a la persona).
        // -----------------------------------------------------------------
        $whiteBytes = _floodFillToWhite($bgResult['data']);

        // Verificar que el flood-fill realmente limpió el fondo:
        // si las 4 esquinas son blancas (≥240) el Paso 1 funcionó correctamente.
        // Si no, el fondo era demasiado complejo (p. ej. pared de ladrillo + ventana)
        // y es mejor usar la imagen original para el Paso 2.
        $bgClean = false;
        if ($whiteBytes !== false && function_exists('imagecreatefromstring')) {
            $tmp = @imagecreatefromstring($whiteBytes);
            if ($tmp !== false) {
                $tw = imagesx($tmp);
                $th = imagesy($tmp);
                $corners = [
                    imagecolorat($tmp, 0,       0),
                    imagecolorat($tmp, $tw - 1, 0),
                    imagecolorat($tmp, 0,       $th - 1),
                    imagecolorat($tmp, $tw - 1, $th - 1),
                ];
                $bgClean = true;
                foreach ($corners as $c) {
                    if ((($c >> 16) & 0xFF) < 240 || (($c >> 8) & 0xFF) < 240 || ($c & 0xFF) < 240) {
                        $bgClean = false;
                        break;
                    }
                }
                imagedestroy($tmp);
            }
        }

        if ($bgClean) {
            $workingImage = $whiteBytes;
            error_log('[google-ai] Paso 1 (BGREMOVAL) OK — fondo limpio, usando imagen con fondo blanco');
        } else {
            $workingImage = $imageData;
            error_log('[google-ai] Paso 1 (BGREMOVAL) descartado — fondo complejo, usando imagen original para el Paso 2');
        }
    }

    // =================================================================
    // PASO 2: Transformación a toga — REFERENCE_TYPE_SUBJECT + product-image
    //         Preserva la identidad del alumno y le viste con:
    //           · Ropa formal oscura y elegante
    //           · Beca mate roja #e41416 (sin brillo)
    //           · Fondo blanco puro
    //           · Cara, pelo y tono de piel sin modificar
    // =================================================================
    $graduationPrompt =
        'Professional formal portrait photograph. ' .
        'The same person wearing dark elegant clothes. ' .
        'A single solid deep red sash band going diagonally across the torso from the left shoulder down to the right hip, like a presidential sash. Only one band, not crossed, no X shape. Completely plain with no text, no letters, no embroidery. ' .
        'Clean plain white studio background. Sharp, well illuminated high quality professional portrait photography. ' .
        'Preserve exactly the person\'s face, facial features, eye shape, nose, lips, skin tone, hairstyle and hair color. The face must remain identical to the original.';

    // Referencia de sujeto (la persona a transformar)
    $referenceImages = [[
        'referenceType'      => 'REFERENCE_TYPE_SUBJECT',
        'referenceId'        => 1,
        'referenceImage'     => ['bytesBase64Encoded' => base64_encode($workingImage)],
        'subjectImageConfig' => ['subjectType' => 'SUBJECT_TYPE_PERSON'],
    ]];

    // Imágenes de estilo desde /images/: el modelo generará un resultado
    // lo más parecido posible visualmente a estos ejemplos de resultado final.
    $imagesDir = __DIR__ . '/../images';
    if (is_dir($imagesDir)) {
        $files = glob($imagesDir . '/*.{jpg,jpeg,png,gif}', GLOB_BRACE);
        foreach ($files as $idx => $file) {
            if (is_file($file) && $idx < 4) { // Máximo 4 imágenes de estilo
                $styleData = file_get_contents($file);
                if ($styleData !== false && $styleData !== '') {
                    $referenceImages[] = [
                        'referenceType'    => 'REFERENCE_TYPE_STYLE',
                        'referenceId'      => $idx + 2,
                        'referenceImage'   => ['bytesBase64Encoded' => base64_encode($styleData)],
                        'styleImageConfig' => ['styleDescription' => ''],
                    ];
                }
            }
        }
    }

    $togsBody = json_encode([
        'instances' => [[
            'referenceImages' => $referenceImages,
            'prompt'     => $graduationPrompt,
        ]],
        'parameters' => [
            'sampleCount'      => 1,
            'personGeneration' => 'allow_all',
        ],
    ]);

    $togsResult = _vertexPredict($url, $togsBody, $accessToken);
    if (!$togsResult['success']) {
        // Fallback: devolver imagen con fondo blanco sin transformar la ropa
        error_log('[google-ai] Paso 2 (toga) falló: ' . $togsResult['error'] . ' — usando fallback fondo blanco');
        return [
            'success'  => true,
            'data'     => $workingImage,
            'mimeType' => 'image/png',
        ];
    }

    return $togsResult;
}

/**
 * Realiza una llamada POST al endpoint :predict de Vertex AI Imagen.
 *
 * @param string $url
 * @param string $jsonBody  Cuerpo ya codificado en JSON
 * @param string $token     Bearer token OAuth2
 * @return array ['success'=>true, 'data'=>string, 'mimeType'=>string]
 *             | ['success'=>false, 'error'=>string, 'detail'=>string]
 */
function _vertexPredict(string $url, string $jsonBody, string $token): array
{
    // Debug: log la estructura de la petición (sin los datos base64)
    $debugBody = preg_replace('/"bytesBase64Encoded":"[A-Za-z0-9+\/=]{20,}"/', '"bytesBase64Encoded":"[BASE64]"', $jsonBody);
    file_put_contents('/tmp/vertex_last_request.json', $debugBody);
    error_log('[google-ai] Request → ' . $debugBody);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        120);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);

    if (defined('MOODLE_SSL_VERIFY') && MOODLE_SSL_VERIFY === false) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        error_log('[google-ai] cURL error: ' . $curlError);
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }
    if ($httpCode !== 200) {
        error_log('[google-ai] HTTP ' . $httpCode . ': ' . $response);
        return ['success' => false, 'error' => 'Vertex AI HTTP ' . $httpCode, 'detail' => $response];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log('[google-ai] Respuesta no JSON: ' . $response);
        return ['success' => false, 'error' => 'Respuesta no válida de Vertex AI'];
    }

    $prediction = $data['predictions'][0] ?? null;
    if (!isset($prediction['bytesBase64Encoded'])) {
        error_log('[google-ai] Sin imagen en predictions: ' . $response);
        return [
            'success' => false,
            'error'   => 'Vertex AI no devolvió imagen',
            'detail'  => substr($response, 0, 500),
        ];
    }

    $bytes = base64_decode($prediction['bytesBase64Encoded']);
    if ($bytes === false || $bytes === '') {
        return ['success' => false, 'error' => 'Error al decodificar base64 de Vertex AI'];
    }

    return [
        'success'  => true,
        'data'     => $bytes,
        'mimeType' => $prediction['mimeType'] ?? 'image/png',
    ];
}

/**
 * Reemplaza el fondo de color sólido de una imagen PNG por blanco puro.
 *
 * Estrategia: flood-fill iterativo (SplQueue) desde todos los píxeles del
 * perímetro exterior. Solo se reemplazan píxeles conectados al borde cuyo
 * color sea similar al promedio de las 8 muestras de esquina/borde.
 * Esto preserva íntegramente la persona interior.
 *
 * También elimina el letterbox negro (padding < 30,30,30) que añade el modelo.
 *
 * @param string $imageBytes  Contenido binario PNG de entrada
 * @return string|false       PNG resultante con fondo blanco, o false si GD no está disponible
 */
function _floodFillToWhite(string $imageBytes)
{
    if (!function_exists('imagecreatefromstring') || !class_exists('SplQueue')) {
        return false;
    }

    $src = @imagecreatefromstring($imageBytes);
    if ($src === false) {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    // Estimar color de fondo desde 8 puntos de borde
    $edgeSamples = [
        imagecolorat($src, 0,             0),
        imagecolorat($src, $w - 1,        0),
        imagecolorat($src, 0,             $h - 1),
        imagecolorat($src, $w - 1,        $h - 1),
        imagecolorat($src, (int)($w / 2), 0),
        imagecolorat($src, (int)($w / 2), $h - 1),
        imagecolorat($src, 0,             (int)($h / 2)),
        imagecolorat($src, $w - 1,        (int)($h / 2)),
    ];
    $sumR = $sumG = $sumB = 0;
    foreach ($edgeSamples as $ec) {
        $sumR += ($ec >> 16) & 0xFF;
        $sumG += ($ec >> 8)  & 0xFF;
        $sumB += $ec         & 0xFF;
    }
    $n    = count($edgeSamples);
    $bgR  = (int)($sumR / $n);
    $bgG  = (int)($sumG / $n);
    $bgB  = (int)($sumB / $n);
    $tol2 = 1225; // tolerancia euclidea = 35

    // Imagen destino: copia de src; los píxeles de fondo se pintarán de blanco
    $dst   = imagecreatetruecolor($w, $h);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    $white = imagecolorallocate($dst, 255, 255, 255);

    $visited = [];
    $queue   = new SplQueue();
    $dirs    = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    // Semillas: perímetro completo
    for ($x = 0; $x < $w; $x++) {
        foreach ([0, $h - 1] as $y) {
            $key = $y * $w + $x;
            if (!isset($visited[$key])) {
                $visited[$key] = true;
                $queue->enqueue([$x, $y]);
            }
        }
    }
    for ($y = 1; $y < $h - 1; $y++) {
        foreach ([0, $w - 1] as $x) {
            $key = $y * $w + $x;
            if (!isset($visited[$key])) {
                $visited[$key] = true;
                $queue->enqueue([$x, $y]);
            }
        }
    }

    while (!$queue->isEmpty()) {
        list($x, $y) = $queue->dequeue();
        $c  = imagecolorat($src, $x, $y);
        $r  = ($c >> 16) & 0xFF;
        $g  = ($c >> 8)  & 0xFF;
        $b  = $c         & 0xFF;
        $d2 = ($r - $bgR) * ($r - $bgR)
            + ($g - $bgG) * ($g - $bgG)
            + ($b - $bgB) * ($b - $bgB);
        // Píxel de fondo (similar al borde) o letterbox negro del modelo
        $isBackground = $d2 <= $tol2 || ($r < 30 && $g < 30 && $b < 30);
        if ($isBackground) {
            imagesetpixel($dst, $x, $y, $white);
            foreach ($dirs as $d) {
                $nx = $x + $d[0];
                $ny = $y + $d[1];
                if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                    $nk = $ny * $w + $nx;
                    if (!isset($visited[$nk])) {
                        $visited[$nk] = true;
                        $queue->enqueue([$nx, $ny]);
                    }
                }
            }
        }
    }
    unset($visited, $queue);

    ob_start();
    imagepng($dst);
    $result = ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);

    return ($result !== false && $result !== '') ? $result : false;
}

/**
 * Obtiene un access token de OAuth2 usando la Service Account de GCP.
 *
 * Construye un JWT firmado con RS256 y lo intercambia por un access token
 * en el endpoint de OAuth2 de Google. No requiere dependencias externas,
 * solo la extensión openssl de PHP (ya incluida en PHP 7.4+).
 *
 * @return array ['success'=>true, 'token'=>string] | ['success'=>false, 'error'=>string]
 */
function _getVertexAIAccessToken(): array
{
    if (!defined('GOOGLE_SERVICE_ACCOUNT_KEY_FILE') || GOOGLE_SERVICE_ACCOUNT_KEY_FILE === '') {
        return ['success' => false, 'error' => 'GOOGLE_SERVICE_ACCOUNT_KEY_FILE no configurado'];
    }

    $keyFile = GOOGLE_SERVICE_ACCOUNT_KEY_FILE;

    if (!file_exists($keyFile)) {
        error_log('[google-ai] Service account key no encontrada: ' . $keyFile);
        return ['success' => false, 'error' => 'Service account key file no encontrado'];
    }

    $keyData = json_decode(file_get_contents($keyFile), true);
    if (!is_array($keyData) || !isset($keyData['private_key'], $keyData['client_email'])) {
        error_log('[google-ai] Formato inválido del service account key file');
        return ['success' => false, 'error' => 'Service account key file inválido'];
    }

    $now = time();

    // Construir JWT (header.payload.signature) con RS256
    $header  = _base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = _base64url(json_encode([
        'iss'   => $keyData['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signingInput = $header . '.' . $payload;
    $privateKey   = openssl_pkey_get_private($keyData['private_key']);

    if ($privateKey === false) {
        error_log('[google-ai] No se pudo cargar la clave privada del service account');
        return ['success' => false, 'error' => 'Private key inválida en service account'];
    }

    $signature = '';
    openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    openssl_free_key($privateKey);  // PHP 7.4 requiere liberar explícitamente

    $jwt = $signingInput . '.' . _base64url($signature);

    // Intercambiar JWT por access token
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL not available'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        error_log('[google-ai] cURL error al obtener token: ' . $curlError);
        return ['success' => false, 'error' => 'cURL error obteniendo access token'];
    }

    if ($httpCode !== 200) {
        error_log('[google-ai] OAuth2 token endpoint HTTP ' . $httpCode . ': ' . $response);
        return ['success' => false, 'error' => 'Error OAuth2 HTTP ' . $httpCode];
    }

    $tokenData = json_decode($response, true);
    if (!isset($tokenData['access_token'])) {
        error_log('[google-ai] No se recibió access_token: ' . $response);
        return ['success' => false, 'error' => 'No se recibió access_token de Google'];
    }

    return ['success' => true, 'token' => $tokenData['access_token']];
}

/**
 * Codifica en Base64 URL-safe (sin padding), requerido por el estándar JWT.
 */
function _base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}