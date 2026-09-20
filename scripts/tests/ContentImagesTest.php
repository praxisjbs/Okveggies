<?php
/** Focused regression checks for managed documentary-image cleanup. */

$root = dirname(__DIR__, 2);
$directory = $root . '/uploads/content';
$stem = str_repeat('a', 32);
$paths = [];

if (!is_dir($directory)) {
    mkdir($directory, 0755, true);
}

foreach ([640, 777, 960, 1280] as $width) {
    $path = $directory . '/' . $stem . '-' . $width . '.webp';
    file_put_contents($path, 'test-only');
    $paths[] = $path;
}

ContentImages::removeSet('/uploads/content/' . $stem . '-777.webp');

foreach ($paths as $path) {
    okv_test_ok(!is_file($path), 'content image cleanup removes ' . basename($path));
}

$unrelated = $directory . '/m12-review-unrelated.webp';
file_put_contents($unrelated, 'test-only');
ContentImages::removeSet('/uploads/content/not-an-approved-name.webp');
okv_test_ok(is_file($unrelated), 'content image cleanup ignores paths outside its strict generated-name format');
unlink($unrelated);
