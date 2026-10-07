<?php
/**
 * Diagnóstico: muestra la estructura JSON que se enviaría a Vertex AI
 * sin hacer la petición real.
 */
require_once __DIR__ . '/config.php';

$imageData = file_get_contents(__DIR__ . '/../images/test/test_asian_woman.jpg');
$workingImage = $imageData;

$graduationPrompt = 'Professional formal portrait photograph.';

$referenceImages = [[
    'referenceType'      => 'REFERENCE_TYPE_SUBJECT',
    'referenceId'        => 1,
    'referenceImage'     => ['bytesBase64Encoded' => '[BASE64_SUBJECT]'],
    'subjectImageConfig' => ['subjectType' => 'SUBJECT_TYPE_PERSON'],
]];

$imagesDir = __DIR__ . '/../images';
if (is_dir($imagesDir)) {
    $files = glob($imagesDir . '/*.{jpg,jpeg,png,gif}', GLOB_BRACE);
    foreach ($files as $idx => $file) {
        if (is_file($file) && $idx < 4) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
            $size = filesize($file);
            $referenceImages[] = [
                'referenceType'  => 'REFERENCE_TYPE_STYLE',
                'referenceId'    => $idx + 2,
                'referenceImage' => ['bytesBase64Encoded' => "[BASE64_{$size}bytes_{$mime}]"],
            ];
        }
    }
}

$body = [
    'instances' => [[
        'referenceImages' => $referenceImages,
        'prompt'          => $graduationPrompt,
    ]],
    'parameters' => [
        'sampleCount'      => 1,
        'personGeneration' => 'allow_all',
    ],
];

echo json_encode($body, JSON_PRETTY_PRINT) . "\n";

$project  = GOOGLE_CLOUD_PROJECT_ID;
$location = GOOGLE_CLOUD_LOCATION;
$model    = GOOGLE_AI_MODEL;
echo "\nEndpoint: https://{$location}-aiplatform.googleapis.com/v1/projects/{$project}/locations/{$location}/publishers/google/models/{$model}:predict\n";
echo "Total referenceImages: " . count($referenceImages) . "\n";
