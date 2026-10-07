<?php
/**
 * The admin sidebar as a phone panel.
 *
 * The Owner reported that on a phone the admin menu was not a panel at all: the
 * aside was a plain block in the page flow, above the sticky topbar, so it
 * pushed the page down and scrolled away with it. This pins the contract that
 * replaced it: the aside is the .okv-admin-nav component (a viewport-fixed
 * panel with its own scroll region on a phone, a column of the shell from 768px
 * up), the signed-in bar is pinned, and assets/js/admin-nav.js owns the
 * behaviour a stylesheet cannot give it (page lock, Back closing the menu,
 * Escape, focus kept inside).
 */

$root  = dirname(__DIR__, 2);
$read  = static fn(string $rel): string => (string) file_get_contents($root . '/' . $rel);
$emDash = "\xe2\x80\x94";

$sidebar = $read('includes/components/admin/sidebar.php');
$footer  = $read('includes/components/admin/footer.php');
$header  = $read('includes/components/admin/header.php');
$source  = $read('assets/css/src/input.css');
$built   = $read('assets/css/tailwind.css');
$script  = $read('assets/js/admin-nav.js');

// 1. The aside itself. Server-rendered closed, so a phone without JavaScript
//    sees the page it always saw, and named, so the dialog role it takes on
//    open has a label without JavaScript inventing one.
okv_test_ok(
    (bool) preg_match('/<aside\b[^>]*\bid="okv-admin-sidebar"[^>]*\bclass="okv-admin-nav"[^>]*>/s', $sidebar),
    'the admin aside is the .okv-admin-nav component'
);
okv_test_ok(
    (bool) preg_match('/<aside\b(?=[^>]*\bid="okv-admin-sidebar")(?=[^>]*\bhidden\b)[^>]*>/s', $sidebar),
    'it renders closed, so a phone without JavaScript is not covered by it'
);
okv_test_ok(
    str_contains($sidebar, 'aria-label="Admin menu"'),
    'the aside carries the label the open dialog takes its name from'
);
okv_test_ok(
    !str_contains($sidebar, 'class="hidden md:flex md:flex-col md:w-64'),
    'the old in-flow attribute-toggle markup is gone'
);
okv_test_ok(
    !str_contains($sidebar, 'md:min-h-screen') && !str_contains($sidebar, 'md:flex'),
    'the aside carries no Tailwind layout of its own, the component owns it'
);

// 2. The panel carries its own Close: on a phone it covers the hamburger.
okv_test_ok(
    str_contains($sidebar, 'data-okv-nav-close') && str_contains($sidebar, 'aria-label="Close the menu"'),
    'the phone panel carries a labelled Close control'
);
okv_test_ok(
    substr_count($sidebar, 'md:hidden') === 1 && str_contains($sidebar, 'md:hidden'),
    'that Close is hidden from 768px up, where there is nothing to close'
);
okv_test_ok(
    str_contains($header, 'data-okv-nav-toggle') && str_contains($header, 'aria-controls="okv-admin-sidebar"')
        && str_contains($header, 'aria-expanded="false"'),
    'the hamburger still announces what it opens and starts collapsed'
);
okv_test_ok(
    str_contains($header, 'aria-haspopup="dialog"'),
    'and it announces that a dialog is what opens'
);

// 3. One scroll region, and nothing scrolls out of it.
okv_test_ok(
    str_contains($sidebar, 'data-okv-nav-scroll'),
    'the nav list names itself as the panel scroll region'
);
okv_test_ok(
    (bool) preg_match('/<nav\b[^>]*data-okv-nav-scroll[^>]*>/s', $sidebar),
    'the scroll region is the nav landmark'
);
preg_match('/<nav\b[^>]*data-okv-nav-scroll[^>]*>/s', $sidebar, $navTag);
$navMarkup = $navTag[0] ?? '';
foreach (['flex-1', 'min-h-0', 'overflow-y-auto', 'overscroll-contain'] as $needle) {
    okv_test_ok(str_contains($navMarkup, $needle), "the scroll region is $needle");
}
okv_test_ok(
    str_contains($sidebar, 'aria-label="Admin"'),
    'it is still the Admin navigation landmark for a screen reader'
);

// 4. The signed-in bar does not shrink: it is the foot of the panel.
okv_test_ok(
    (bool) preg_match('/<div class="okv-admin-nav-foot flex-none border-t border-white\/10 bg-white\/5 px-4 pt-3">/', $sidebar),
    'the signed-in bar is pinned as the panel foot'
);
okv_test_ok(
    (bool) preg_match('/\.okv-admin-nav-foot \{ @apply pb-\[max\(0\.75rem,env\(safe-area-inset-bottom\)\)\]; \}/', $source),
    'the panel foot clears the bottom safe area on a notched phone'
);
okv_test_ok(
    (bool) preg_match('/\.okv-admin-nav-foot\{padding-bottom:max\(\.75rem,env\(safe-area-inset-bottom\)\)\}/', $built),
    'and the compiled stylesheet carries that safe area inset'
);
okv_test_ok(
    str_contains($sidebar, 'Csrf::field()') && str_contains($sidebar, 'value="logout"')
        && str_contains($sidebar, 'action="/api/v1/auth.php"'),
    'Sign out keeps its POST form and CSRF field'
);

// 5. The stylesheet. Phone first, then the desktop column, then the override
//    that lets the hidden attribute the phone panel uses have no say from
//    768px up.
okv_test_ok(
    (bool) preg_match('/\.okv-admin-nav \{ @apply fixed inset-0 z-50 flex flex-col bg-forest text-white; \}/', $source),
    'the component pins the panel to the viewport'
);
okv_test_ok(
    (bool) preg_match('/\.okv-admin-nav\[hidden\] \{ display: none; \}/', $source),
    'and makes the hidden attribute win over display:flex'
);
okv_test_ok(
    (bool) preg_match('/@screen md \{[^}]*\.okv-admin-nav \{ @apply static z-auto w-64 shrink-0 min-h-screen; \}[^}]*\.okv-admin-nav\[hidden\] \{ display: flex !important; \}/s', $source),
    'from 768px up it is a column of the shell, hidden attribute or not'
);
okv_test_ok(
    str_contains($source, '[hidden] { display: none !important; }'),
    'the global rule that makes the hidden attribute win is still in place'
);

// The compiled stylesheet has to carry it too, or the panel never paints.
okv_test_ok(
    (bool) preg_match('/\.okv-admin-nav\{[^}]*position:fixed[^}]*inset:0/', $built),
    'tailwind.css fixes the panel to the viewport'
);
okv_test_ok(
    (bool) preg_match('/\.okv-admin-nav\[hidden\]\{display:none\}/', $built),
    'tailwind.css keeps the closed panel closed'
);
okv_test_ok(
    (bool) preg_match('/@media \(min-width:768px\)\{\.okv-admin-nav\{[^}]*position:static[^}]*\}/', $built),
    'tailwind.css hands the sidebar back to the shell from 768px up'
);
okv_test_ok(
    (bool) preg_match('/@media \(min-width:768px\)\{\.okv-admin-nav\{[^}]*\}.*?\.okv-admin-nav\[hidden\]\{display:flex!important\}/', $built),
    'and does not let the phone closed state hide the desktop sidebar'
);
okv_test_ok(
    str_contains($built, '.overscroll-contain{') && str_contains($built, '.min-h-0{'),
    'the compiled stylesheet carries the scroll region utilities'
);

// 6. The controller is loaded by the shared admin footer, and the inline glue
//    it replaces is gone.
okv_test_ok(
    str_contains($footer, "okv_asset('/assets/js/admin-nav.js')"),
    'the admin shell loads the panel controller'
);
okv_test_ok(
    !str_contains($footer, "classList.toggle('hidden')"),
    'the old inline class toggling is gone'
);
okv_test_ok(
    str_contains($footer, '$okv_admin_script'),
    'per-page scripts still load the way they did'
);

// 7. The behaviour a stylesheet cannot supply.
$behaviours = [
    "window.history.pushState({ okvAdminNav: true }" => 'opening pushes one history entry, so Back has something to close',
    "window.addEventListener('popstate'" => 'a Back press is listened for',
    'window.history.back();' => 'closing the panel hands that entry back',
    "panel.removeAttribute('aria-modal')" => 'the dialog role comes off again on close',
    "event.key === 'Escape'" => 'Escape closes the panel',
    "event.key !== 'Tab'" => 'Tab is held inside the open panel',
    "wide.addEventListener('change'" => 'crossing to 768px closes the panel',
    'document.documentElement.style.overflow' => 'the page behind is locked, on the document too',
    "document.body.style.overflow" => 'and on the body',
    "window.location.assign(href)" => 'a destination is left for once the entry is spent',
    "addEventListener('pageshow'" => 'a page restored from the back/forward cache is put back to rest',
];
foreach ($behaviours as $needle => $label) {
    okv_test_ok(str_contains($script, $needle), $label);
}
okv_test_ok(
    str_contains($script, "matchMedia('(min-width: 768px)')"),
    'the controller decides on the same 768px the stylesheet does'
);
okv_test_ok(
    str_contains($script, 'lockPage(false)') && substr_count($script, 'lockPage(true)') === 1,
    'the page is locked on open and unlocked on every way out'
);
okv_test_ok(
    !str_contains($script, 'innerHTML') && str_contains($script, "'use strict'"),
    'the controller renders no HTML and stays strict'
);
okv_test_ok(
    !str_contains($script, $emDash) && !str_contains($sidebar, $emDash),
    'the new sidebar markup and controller carry no em dash'
);
