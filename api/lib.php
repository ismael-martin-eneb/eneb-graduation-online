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