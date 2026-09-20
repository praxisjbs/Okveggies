<?php
/** Static guards for the cPanel access-control deployment seam. */

$root = dirname(__DIR__, 2);
$apache = (string) file_get_contents($root . '/.htaccess');
$workflow = (string) file_get_contents($root . '/.github/workflows/deploy.yml');
$verify = (string) file_get_contents($root . '/scripts/verify.sh');

$rewriteLine = '';
foreach (explode("\n", $apache) as $line) {
    if (str_contains($line, 'RewriteRule') && str_contains($line, '[F,L,NC]')) {
        $rewriteLine = $line;
        break;
    }
}
foreach (['includes', 'migrations', 'scripts', 'docs', 'vendor', 'node_modules'] as $directory) {
    okv_test_ok(
        str_contains($rewriteLine, $directory),
        "Apache rewrite protection includes the server-only $directory directory"
    );
}
okv_test_ok($rewriteLine !== '', 'the server-only directory rewrite fails closed');
okv_test_ok(str_contains($apache, 'Require all denied'), 'file-level Apache denial remains in place');

okv_test_ok(str_contains($workflow, "local_path:  './_dist/.htaccess'"), 'deployment uploads .htaccess explicitly');
okv_test_ok(str_contains($workflow, "local_path:  './_dist/.user.ini'"), 'deployment uploads .user.ini explicitly');
okv_test_ok(substr_count($workflow, 'delete_remote_files: false') >= 3, 'server-control uploads never delete the remote application tree');
okv_test_ok(str_contains($workflow, 'run: bash scripts/verify.sh'), 'deployment runs the smoke gate after migration');
okv_test_ok(
    strpos($workflow, 'run: bash scripts/verify.sh') > strpos($workflow, 'Apply migrations on the server'),
    'deployment smoke verification runs after server migration'
);

foreach (['/.env', '/includes/config/db.php', '/migrations/001_core_schema.sql', '/docs/PRD.md'] as $path) {
    okv_test_ok(str_contains($verify, '$BASE' . $path), "deployment smoke checks $path");
}

// The catalogue pages read the product tables on every load, so they are the
// smoke gate's proof that the deployed code and the migrated schema agree. A
// deploy that leaves the shop blank must fail here, never reach customers.
foreach (['/shop.php', '/combos.php', '/product.php?slug=', '/api/v1/catalog.php?action=browse'] as $path) {
    okv_test_ok(str_contains($verify, '$BASE' . $path), "deployment smoke covers the catalogue at $path");
}
