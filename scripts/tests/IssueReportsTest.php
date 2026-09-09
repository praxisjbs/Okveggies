<?php
/** Pure Task A validation and reporting-window tests. */

okv_test_eq(
    ['wrong_item', 'missing_item', 'quality', 'short_quantity', 'damaged', 'late', 'something_else'],
    array_keys(IssueReports::CATEGORIES),
    'issue categories are the fixed approved set'
);
okv_test_eq(5, IssueReports::MAX_PHOTOS, 'one report accepts no more than 5 photos');
okv_test_eq(['image/jpeg', 'image/png', 'image/webp'], IssueReports::PHOTO_MIME, 'issue evidence accepts only the approved image MIME types');
okv_test_eq([], IssueReports::normalisePhotoUpload([]), 'an absent upload group becomes no photos');
$normalisedPhotos = IssueReports::normalisePhotoUpload([
    'name' => ['first.jpg', '', 'second.png'],
    'type' => ['image/jpeg', '', 'image/png'],
    'tmp_name' => ['/tmp/first', '', '/tmp/second'],
    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
    'size' => [200, 0, 300],
]);
okv_test_eq(2, count($normalisedPhotos), 'empty multiple-upload slots are ignored');
okv_test_eq('second.png', $normalisedPhotos[1]['name'], 'multiple-upload fields keep their matching metadata');

$valid = IssueReports::validateFields('damaged', "  Two packs arrived crushed.\r\nPlease check them.  ");
okv_test_ok(!empty($valid['ok']), 'an approved category and meaningful description pass');
okv_test_eq("Two packs arrived crushed.\nPlease check them.", $valid['description'], 'description whitespace and line endings are normalised');
okv_test_eq('category_required', IssueReports::validateFields('other', 'This is long enough.')['code'], 'an invented category is refused');
okv_test_eq('description_too_short', IssueReports::validateFields('quality', 'Too soft')['code'], 'a description below 10 characters is refused');
okv_test_eq('description_too_long', IssueReports::validateFields('quality', str_repeat('a', 1001))['code'], 'a description above 1,000 characters is refused');
$markup = IssueReports::validateFields('quality', '<b>Leaves were already brown</b>');
okv_test_eq('<b>Leaves were already brown</b>', $markup['description'], 'markup is retained only as plain customer text for escaped output');

$zone = new DateTimeZone('Africa/Lagos');
$dispatched = [
    'order_number' => 'OKV26041',
    'order_status' => 'dispatched',
    'dispatched_at' => '2026-09-01 08:15:00',
    'delivered_at' => null,
];
$eligible = IssueReports::eligibility($dispatched, new DateTimeImmutable('2026-09-08 23:59:59', $zone), 7);
okv_test_ok(!empty($eligible['ok']), 'the entire final Lagos calendar day is reportable');
okv_test_eq('2026-09-08 23:59:59', $eligible['deadline']->format('Y-m-d H:i:s'), 'dispatch plus 7 days closes at the end of that date');
$expired = IssueReports::eligibility($dispatched, new DateTimeImmutable('2026-09-09 00:00:00', $zone), 7);
okv_test_eq('expired', $expired['code'], 'the next second is outside the reporting window');

$delivered = array_merge($dispatched, ['order_status' => 'delivered', 'delivered_at' => '2026-09-04 16:30:00']);
$deliveryEligibility = IssueReports::eligibility($delivered, new DateTimeImmutable('2026-09-11 12:00:00', $zone), 7);
okv_test_ok(!empty($deliveryEligibility['ok']), 'a delivered order uses its later delivery timestamp');
okv_test_eq('2026-09-11 23:59:59', $deliveryEligibility['deadline']->format('Y-m-d H:i:s'), 'delivery starts a fresh 7-day calendar window');

okv_test_eq(
    'ineligible_status',
    IssueReports::eligibility(['order_number' => 'OKV26041', 'order_status' => 'packed'], new DateTimeImmutable('now', $zone), 7)['code'],
    'an order before dispatch is ineligible'
);
okv_test_eq(
    'missing_timestamp',
    IssueReports::eligibility(['order_number' => 'OKV26041', 'order_status' => 'dispatched', 'dispatched_at' => null], new DateTimeImmutable('now', $zone), 7)['code'],
    'a dispatched order without recorded dispatch evidence fails closed'
);
okv_test_eq(
    'missing_timestamp',
    IssueReports::eligibility(['order_number' => 'OKV26041', 'order_status' => 'delivered', 'delivered_at' => null], new DateTimeImmutable('now', $zone), 7)['code'],
    'a delivered order without recorded delivery evidence fails closed'
);

$page = file_get_contents(dirname(__DIR__, 2) . '/public/order.php');
okv_test_ok(str_contains($page, 'if (!$publicTrail && $issueState !== null)'), 'issue UI is guarded from the public token view');
okv_test_ok(str_contains($page, 'IssueReports::CATEGORIES'), 'the owner form reads categories from the shared service');
okv_test_ok(str_contains($page, 'okv_e($openReport[\'description\'])'), 'stored customer text is escaped on owner output');
okv_test_ok(str_contains($page, 'enctype="multipart/form-data"'), 'the owner report form can carry photo files');
okv_test_ok(str_contains($page, 'Choose up to 5 JPEG, PNG or WebP photos'), 'the photo count and types are stated before selection');
okv_test_ok(str_contains($page, '/public/issue_photo.php?photo='), 'owner photo links use the protected route');

$photoRoute = file_get_contents(dirname(__DIR__, 2) . '/public/issue_photo.php');
okv_test_ok(str_contains($photoRoute, "Rbac::can('issues.view')"), 'the private photo route permits only authorised staff when the customer is not its owner');
okv_test_ok(str_contains($photoRoute, 'Customer::isLoggedIn()'), 'the private photo route checks authenticated customer ownership');
okv_test_ok(str_contains(file_get_contents(dirname(__DIR__, 2) . '/uploads/issues/.htaccess'), 'Require all denied'), 'Apache direct access to issue evidence is denied');

$admin = file_get_contents(dirname(__DIR__, 2) . '/admin/make_it_right.php');
okv_test_ok(str_contains($admin, 'IssueReports::findForStaff'), 'the issues.view admin screen loads its authorised full detail through the domain service');
okv_test_ok(str_contains($admin, 'loading="lazy"'), 'admin issue thumbnails use lazy loading');

$controller = file_get_contents(dirname(__DIR__, 2) . '/api/v1/make_it_right.php');
okv_test_ok(str_contains($controller, 'Customer::requireLoginApi()'), 'the reporting controller requires customer authentication');
okv_test_ok(str_contains($controller, 'Csrf::validate()'), 'the reporting controller checks CSRF');
okv_test_ok(str_contains($controller, 'okv_is_post()'), 'the reporting controller refuses non-POST writes');
okv_test_ok(
    strpos($controller, 'IssueReports::submit') < strpos($controller, 'Notifications::announceIssueReportReceived'),
    'the committed service write completes before notification dispatch'
);
