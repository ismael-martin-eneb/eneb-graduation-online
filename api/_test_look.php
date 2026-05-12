<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';
$raw = file_get_contents(__DIR__ . '/../images/test/test_asian_woman.jpg');
$t = microtime(true);
$r = processImageWithGoogleAI($raw, 'image/jpeg');
$s = round(microtime(true) - $t, 1);
if (!$r['success']) { echo "ERROR: {$r['error']}\n"; exit(1); }
file_put_contents(__DIR__ . '/_test_look_result.png', $r['data']);
echo "OK {$s}s — " . number_format(strlen($r['data'])) . " bytes\n";
