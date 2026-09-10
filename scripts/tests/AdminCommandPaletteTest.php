<?php
/** Pure canonical-navigation contract for the M11 command palette. */
require dirname(__DIR__, 2) . '/includes/config/nav.php';

$allowed = ['dashboard.view', 'orders.view'];
$commands = okv_admin_nav_commands(
    $OKV_ADMIN_NAV,
    static fn(string $permission): bool => in_array($permission, $allowed, true)
);

okv_test_eq(['Dashboard', 'Orders'], array_column($commands, 'label'), 'the palette flattens only permitted canonical destinations');
okv_test_eq(['/admin/', '/admin/orders.php'], array_column($commands, 'href'), 'permitted commands keep canonical URLs and order');
okv_test_eq(['Overview', 'Selling'], array_column($commands, 'heading'), 'commands retain their canonical section labels');
okv_test_ok(in_array('checkout', $commands[1]['keywords'], true), 'approved search keywords stay with the Orders navigation item');
okv_test_ok(!in_array('Users', array_column($commands, 'label'), true), 'a forbidden destination never enters command data');

$paymentCommands = okv_admin_nav_commands(
    $OKV_ADMIN_NAV,
    static fn(string $permission): bool => $permission === 'payments.view'
);
okv_test_eq(['Payments'], array_column($paymentCommands, 'label'), 'a single permission produces a single command');
okv_test_ok(in_array('pay', $paymentCommands[0]['keywords'], true), 'Payments carries its approved pay search keyword');
okv_test_eq([], okv_admin_nav_commands($OKV_ADMIN_NAV, static fn(string $permission): bool => false), 'no permissions produces no commands');

$paletteJs = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/admin-command-palette.js');
$paletteCss = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/src/input.css');
okv_test_ok(!str_contains($paletteJs, 'innerHTML'), 'the palette never renders command data through innerHTML');
okv_test_ok(str_contains($paletteJs, "event.key === 'ArrowDown'")
    && str_contains($paletteJs, "event.key === 'ArrowUp'")
    && str_contains($paletteJs, "event.key === 'Enter'")
    && str_contains($paletteJs, "event.key === 'Escape'"), 'the palette implements the required keyboard command set');
okv_test_ok(str_contains($paletteJs, 'event.ctrlKey || event.metaKey'), 'the global shortcut supports Control and Command');
okv_test_ok(str_contains($paletteCss, '@media (prefers-reduced-motion: reduce)'), 'the shared reduced-motion rule covers the palette animation');
