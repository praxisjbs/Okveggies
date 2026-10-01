<?php
/**
 * scripts/tests/FounderPortraitTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Our Story page shows the founder portrait in the "The person
 * behind it" section. The body is admin editable Markdown, so the helper finds
 * the deterministic heading anchor and places the figure after it. A missing
 * heading, a missing photograph or a photograph it cannot measure leaves the
 * body exactly as written.
 *
 *   php scripts/tests/run.php
 * -----------------------------------------------------------------------------
 */

// A real 1 by 1 JPEG so getimagesize measures it the way it will measure the
// committed portrait. Generated once, embedded here, deleted after the test.
$miniJpeg = base64_decode(
    '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/'
    . 'wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q=='
);
$portraitFile = tempnam(sys_get_temp_dir(), 'okv-portrait-') . '.jpg';
file_put_contents($portraitFile, $miniJpeg);
$portrait = [
    'anchor' => 'the-person-behind-it',
    'file' => $portraitFile,
    'url' => '/assets/img/story/founder-kumbish-emmanuel-putleh.jpg',
    'alt' => 'Kumbish Emmanuel Putleh, founder of OK Veggies',
    'caption' => 'Kumbish Emmanuel Putleh, Founder',
];
$story = ContentRenderer::render("## The person behind it\n\nOK Veggies is led by its founder, Kumbish Emmanuel Putleh.");
$withPortrait = okv_story_founder_portrait($story['html'], $portrait);
okv_test_ok(str_contains($withPortrait, '<figure class="okv-founder'), 'the founder portrait figure is added to the Our Story body');
$headingEnd = strpos($withPortrait, '</h2>');
$figureStart = strpos($withPortrait, '<figure class="okv-founder');
okv_test_ok($headingEnd !== false && $figureStart !== false && $figureStart > $headingEnd, 'the figure sits directly after the section heading, not before it');
okv_test_ok(str_contains($withPortrait, 'alt="Kumbish Emmanuel Putleh, founder of OK Veggies"'), 'the portrait carries its alt text');
okv_test_ok(str_contains($withPortrait, 'Kumbish Emmanuel Putleh, Founder</figcaption>'), 'the portrait caption names the founder and his role');
okv_test_ok(str_contains($withPortrait, 'width="1" height="1"'), 'the portrait emits the photograph width and height');
okv_test_ok(str_contains($withPortrait, 'loading="lazy" decoding="async"'), 'the portrait loads lazily and decodes asynchronously');
okv_test_eq(1, substr_count($withPortrait, '<figure'), 'exactly one figure is added to the body');

$noAnchor = okv_story_founder_portrait('<p>A body without the section heading.</p>', $portrait);
okv_test_ok(str_starts_with($noAnchor, '<p>A body without the section heading.</p>') && str_contains($noAnchor, '<figure class="okv-founder'), 'a body without the heading keeps its words and the portrait follows it, so renaming the heading cannot make the portrait vanish');
okv_test_eq(1, substr_count($noAnchor, '<figure'), 'and it is added once');

$missingPhoto = $portrait;
$missingPhoto['file'] = sys_get_temp_dir() . '/okv-portrait-that-was-never-uploaded.jpg';
okv_test_eq($story['html'], okv_story_founder_portrait($story['html'], $missingPhoto), 'a missing photograph leaves the body untouched');

$unreadable = $portrait;
$unreadable['file'] = $portraitFile;
$unreadable['anchor'] = 'a-heading-the-renderer-never-emits';
$followed = okv_story_founder_portrait($story['html'], $unreadable);
okv_test_ok(str_starts_with($followed, $story['html']) && strpos($followed, '<figure class="okv-founder') > strpos($followed, 'Kumbish Emmanuel Putleh.'), 'an unknown anchor puts the portrait after the body instead of dropping it');

unlink($portraitFile);
