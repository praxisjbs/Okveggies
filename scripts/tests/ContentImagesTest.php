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

// Presentation validates the published main file and every responsive sibling.
// Copy committed fixtures into a random, temporary upload set, never live data.
$stem = bin2hex(random_bytes(16));
$webPath = '/uploads/content/' . $stem;
$mainPath = $webPath . '-1280.webp';
try {
    foreach ([640, 960, 1280] as $width) {
        copy($root . '/assets/img/hero/fresh-produce-' . $width . '.webp', $root . $webPath . '-' . $width . '.webp');
    }
    $image = ContentImages::presentation($mainPath);
    okv_test_eq($mainPath, $image['src'], 'a valid published main image retains its path');
    okv_test_eq(1280, $image['width'], 'published main width comes from the actual file');
    okv_test_eq(721, $image['height'], 'published main height comes from the actual file');
    okv_test_eq(implode(', ', [
        okv_image_url($webPath . '-640.webp') . ' 640w',
        okv_image_url($webPath . '-960.webp') . ' 960w',
        okv_image_url($mainPath) . ' 1280w',
    ]), $image['srcset'], 'valid published variants form one correctly labelled responsive set');

    unlink($root . $webPath . '-640.webp');
    file_put_contents($root . $webPath . '-960.webp', 'not an image');
    $image = ContentImages::presentation($mainPath);
    okv_test_eq(okv_image_url($mainPath) . ' 1280w', $image['srcset'], 'missing and corrupt siblings are excluded, not downloaded');

    copy($root . '/assets/img/hero/fresh-produce-640.webp', $root . $webPath . '-960.webp');
    $image = ContentImages::presentation($mainPath);
    okv_test_eq(okv_image_url($mainPath) . ' 1280w', $image['srcset'], 'a sibling with the wrong intrinsic width is excluded');

    copy($root . '/assets/img/hero/fresh-produce-960.webp', $root . $webPath . '-960.webp');
    foreach (['missing', 'corrupt', 'wrong_format', 'wrong_width'] as $state) {
        if (is_file($root . $mainPath)) {
            unlink($root . $mainPath);
        }
        if ($state === 'corrupt') {
            file_put_contents($root . $mainPath, 'not an image');
        } elseif ($state === 'wrong_format') {
            copy($root . '/assets/img/brand/hero section.png', $root . $mainPath);
        } elseif ($state === 'wrong_width') {
            copy($root . '/assets/img/hero/fresh-produce-640.webp', $root . $mainPath);
        }
        $image = ContentImages::presentation($mainPath);
        okv_test_eq('', $image['srcset'], $state . ' published main file cannot masquerade as a valid set through its siblings');
        okv_test_eq(0, $image['width'], $state . ' main file has no presentation width');
        okv_test_eq(0, $image['height'], $state . ' main file has no presentation height');
    }
    foreach (['https://example.test/hero.webp', '/uploads/content/../../assets/img/hero/fresh-produce-1280.webp'] as $unsafe) {
        okv_test_eq('', ContentImages::presentation($unsafe)['srcset'], 'presentation refuses a path outside the managed image contract');
    }
} finally {
    ContentImages::removeSet($mainPath);
}
