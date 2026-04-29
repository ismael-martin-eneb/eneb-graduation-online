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
 * Elimina el fondo de una imagen usando Imagen 3 (Vertex AI) via BGREMOVAL.
 *
 * Utiliza el endpoint `:predict` de imagen-3.0-capability-001 con
 * editMode=BGREMOVAL. No necesita prompt de texto — la operación es
 * puramente de segmentación y eliminación de fondo.
 *
 * Requiere en config.php:
 *   GOOGLE_CLOUD_PROJECT_ID         — ID del proyecto GCP
 *   GOOGLE_CLOUD_LOCATION           — Región (p. ej. 'us-central1')
 *   GOOGLE_AI_MODEL                 — 'imagen-3.0-capability-001' o '...-002'
 *   GOOGLE_SERVICE_ACCOUNT_KEY_FILE — Ruta absoluta al JSON de la Service Account
 *
 * @param string $imageData  Contenido binario de la imagen original
 * @param string $mimeType   MIME type de la imagen (p. ej. 'image/jpeg')
 * @return array ['success'=>true, 'data'=>string, 'mimeType'=>string]
 *             | ['success'=>false, 'error'=>string, 'detail'=>string]
 */
function processImageWithGoogleAI(string $imageData, string $mimeType): array
{
    // -----------------------------------------------------------------
    // 1. Obtener access token OAuth2 desde la Service Account
    // -----------------------------------------------------------------
    $tokenResult = _getVertexAIAccessToken();
    if (!$tokenResult['success']) {
        return ['success' => false, 'error' => $tokenResult['error']];
    }
    $accessToken = $tokenResult['token'];

    // -----------------------------------------------------------------
    // 2. Construir el endpoint de Vertex AI Imagen (:predict)
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

    // -----------------------------------------------------------------
    // 3. Construir el cuerpo de la petición (Imagen edit — background removal)
    //    Formato confirmado para imagen-3.0-capability-001:
    //    - La imagen va en referenceImages con REFERENCE_TYPE_SUBJECT + subjectImageConfig
    //    - editMode = 'product-image' va en editConfig (dentro del instance)
    //    - sampleCount va en parameters
    // -----------------------------------------------------------------
    $body = json_encode([
        'instances' => [[
            'referenceImages' => [[
                'referenceType'      => 'REFERENCE_TYPE_SUBJECT',
                'referenceId'        => 1,
                'referenceImage'     => ['bytesBase64Encoded' => base64_encode($imageData)],
                'subjectImageConfig' => ['subjectType' => 'SUBJECT_TYPE_PERSON'],
            ]],
            'prompt'     => 'remove the background',
            'editConfig' => ['editMode' => 'product-image'],
        ]],
        'parameters' => [
            'sampleCount' => 1,
        ],
    ]);

    if ($body === false) {
        return ['success' => false, 'error' => 'Error al serializar la petición a Vertex AI'];
    }

    // -----------------------------------------------------------------
    // 4. Llamar a la API
    // -----------------------------------------------------------------
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL not available'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        120);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
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

    // -----------------------------------------------------------------
    // 5. Extraer la imagen procesada de predictions[]
    // -----------------------------------------------------------------
    $prediction = $data['predictions'][0] ?? null;
    if (isset($prediction['bytesBase64Encoded'])) {
        $processedBytes = base64_decode($prediction['bytesBase64Encoded']);
        $processedMime  = $prediction['mimeType'] ?? 'image/png';
        if ($processedBytes !== false && $processedBytes !== '') {
            return [
                'success'  => true,
                'data'     => $processedBytes,
                'mimeType' => $processedMime,
            ];
        }
    }

    error_log('[google-ai] No se encontró imagen en predictions: ' . $response);
    return [
        'success' => false,
        'error'   => 'Vertex AI Imagen no devolvió imagen procesada',
        'detail'  => substr($response, 0, 500),
    ];
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